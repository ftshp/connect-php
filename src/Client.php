<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Ftshp\Connect\Codec\Codec;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\Compression\Compression;
use Ftshp\Connect\Compression\Gzip;
use Ftshp\Connect\Internal\Chain;
use Ftshp\Connect\Internal\ContentType;
use Ftshp\Connect\Internal\ErrorBody;
use Google\Protobuf\Internal\Message;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class Client
{
    /** @var array<string, Compression> */
    private readonly array $acceptCompression;

    /**
     * @param ?Compression $compression compresses request bodies
     * @param list<Compression> $acceptCompression accepted on responses
     * @param list<Interceptor> $interceptors first is outermost
     * @param ?int $timeoutMs default per call, overridden by the Context
     * @param int $maxResponseBytes limit on raw and decompressed response bodies, 0 for none
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl,
        private readonly Codec $codec = new ProtoCodec(),
        private readonly ?Compression $compression = null,
        array $acceptCompression = [new Gzip()],
        private readonly array $interceptors = [],
        private readonly ?int $timeoutMs = null,
        private readonly int $maxResponseBytes = 4194304,
        private readonly string $prefix = '',
    ) {
        $byName = [];
        foreach ($acceptCompression as $algorithm) {
            $byName[strtolower($algorithm->name())] = $algorithm;
        }
        $this->acceptCompression = $byName;
    }

    /**
     * @template TIn of Message
     * @template TOut of Message
     *
     * @param MethodDescriptor<TIn, TOut> $method
     * @param TIn $request
     *
     * @return TOut
     *
     * @throws ConnectException
     */
    public function unary(MethodDescriptor $method, Message $request, Context $context = new Context()): Message
    {
        $context->method = $method;
        $context->timeoutMs ??= $this->timeoutMs;
        $call = Chain::build($this->interceptors, $this->send(...));

        /** @var TOut */
        return $call(new UnaryRequest($method, $context, true, $request));
    }

    private function send(UnaryRequest $unary): Message
    {
        $request = $this->buildRequest($unary);
        $context = $unary->context;
        $context->startTimer();
        try {
            $response = $this->http->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            $code = $context->remainingMs() === 0 ? Code::DeadlineExceeded : Code::Unavailable;

            throw new ConnectException($code, $e->getMessage(), [], $e);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectException(Code::Unknown, $e->getMessage(), [], $e);
        }

        return $this->readResponse($response, $unary);
    }

    private function buildRequest(UnaryRequest $unary): RequestInterface
    {
        $context = $unary->context;
        $request = $this->requestFactory->createRequest('POST', rtrim($this->baseUrl, '/') . $this->prefix . $unary->method->procedure());
        foreach ($context->requestHeaders->toArray() as $name => $values) {
            $request = $request->withHeader((string) $name, $values);
        }
        $request = $request
            ->withHeader('Content-Type', 'application/' . $this->codec->name())
            ->withHeader('Connect-Protocol-Version', '1');
        if ($this->acceptCompression !== []) {
            $request = $request->withHeader('Accept-Encoding', implode(', ', array_keys($this->acceptCompression)));
        }
        if ($context->timeoutMs !== null) {
            $request = $request->withHeader('Connect-Timeout-Ms', (string) $context->timeoutMs);
        }

        $request = $request->withoutHeader('Content-Encoding');
        $body = $this->codec->encode($unary->message());
        if ($this->compression !== null && $body !== '') {
            $body = $this->compression->compress($body);
            $request = $request->withHeader('Content-Encoding', $this->compression->name());
        }

        return $request->withBody($this->streamFactory->createStream($body));
    }

    private function readResponse(ResponseInterface $response, UnaryRequest $unary): Message
    {
        $headers = [];
        $trailers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $name = strtolower((string) $name);
            if (str_starts_with($name, 'trailer-')) {
                $trailers[substr($name, 8)] = $values;
            } else {
                $headers[$name] = $values;
            }
        }
        $unary->context->responseHeaders->replace(Headers::fromPsr($headers));
        $unary->context->responseTrailers->replace(Headers::fromPsr($trailers));

        $body = $this->readBody($response->getBody());
        $contentType = $response->getHeaderLine('Content-Type');
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        $compression = $encoding === '' || $encoding === 'identity' ? null : $this->acceptCompression[$encoding] ?? false;
        if ($response->getStatusCode() !== 200) {
            foreach ($trailers as $name => $values) {
                $headers[$name] = [...$headers[$name] ?? [], ...$values];
            }
            $metadata = Headers::fromPsr($headers);

            throw $compression === false
                ? $this->statusError($response->getStatusCode(), $body, $metadata)
                : $this->error($response->getStatusCode(), $contentType, $this->decompress($body, $compression), $metadata);
        }
        if (ContentType::mediaType($contentType) !== 'application/' . $this->codec->name()) {
            throw new ConnectException(Code::Internal, \sprintf('unexpected content type "%s"', $contentType));
        }
        if ($compression === false) {
            throw new ConnectException(Code::Internal, \sprintf('unknown compression "%s"', $encoding));
        }

        return $this->codec->decode($this->decompress($body, $compression), $unary->method->output);
    }

    private function decompress(string $body, ?Compression $compression): string
    {
        return $compression === null || $body === '' ? $body : $compression->decompress($body, $this->maxResponseBytes);
    }

    private function readBody(StreamInterface $stream): string
    {
        if ($this->maxResponseBytes <= 0) {
            return (string) $stream;
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (\strlen($body) > $this->maxResponseBytes) {
                throw new ConnectException(Code::ResourceExhausted, 'message size exceeds limit');
            }
        }

        return $body;
    }

    private function error(int $status, string $contentType, string $body, Headers $metadata): ConnectException
    {
        $error = ContentType::mediaType($contentType) === 'application/json' ? ErrorBody::decode($body, $metadata) : null;

        return $error ?? $this->statusError($status, $body, $metadata);
    }

    private function statusError(int $status, string $body, Headers $metadata): ConnectException
    {
        return new ConnectException(Code::fromHttpStatus($status), 'HTTP ' . $status . ($body !== '' ? ': ' . $body : ''))
            ->withMetadata($metadata);
    }
}
