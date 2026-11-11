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
use Ftshp\Connect\Internal\GetQuery;
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
    private const array UNFORWARDED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'te', 'content-encoding'];

    /** @var array<string, Compression> */
    private readonly array $acceptCompression;

    private readonly string $prefix;

    /**
     * @param ?Compression $compression compresses request bodies
     * @param list<Compression> $acceptCompression accepted on responses
     * @param list<Interceptor> $interceptors first is outermost
     * @param bool $useGet send NoSideEffects methods as GET
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
        private readonly bool $useGet = false,
        private readonly ?int $timeoutMs = null,
        private readonly int $maxResponseBytes = 4194304,
        string $prefix = '',
    ) {
        $prefix = trim($prefix, '/');
        $this->prefix = $prefix === '' ? '' : '/' . $prefix;
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
        $context->timeoutMs ??= $this->timeoutMs;
        $context->begin($method);
        $call = Chain::build($this->interceptors, $this->send(...));

        /** @var TOut */
        return $call(new UnaryRequest($method, $context, true, $request));
    }

    private function send(UnaryRequest $unary): Message
    {
        $context = $unary->context;
        if ($context->remainingMs() === 0) {
            throw new ConnectException(Code::DeadlineExceeded, 'deadline exceeded before sending the request');
        }
        $request = $this->buildRequest($unary);
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
        $body = $this->codec->encode($unary->message());
        if ($this->compression !== null) {
            $body = $this->compression->compress($body);
        }
        $url = rtrim($this->baseUrl, '/') . $this->prefix . $unary->method->procedure();
        $get = $this->useGet && $unary->method->idempotency === Idempotency::NoSideEffects;
        $request = $get
            ? $this->requestFactory->createRequest('GET', $url . '?' . GetQuery::encode($this->codec->name(), $body, $this->compression?->name()))
            : $this->requestFactory->createRequest('POST', $url);
        foreach ($context->requestHeaders->toArray() as $name => $values) {
            if (!\in_array((string) $name, self::UNFORWARDED_HEADERS, true)) {
                $request = $request->withHeader((string) $name, $values);
            }
        }
        $request = $request->withHeader('Connect-Protocol-Version', '1');
        if ($this->acceptCompression !== []) {
            $request = $request->withHeader('Accept-Encoding', implode(', ', array_keys($this->acceptCompression)));
        }
        $remainingMs = $context->remainingMs();
        if ($remainingMs !== null && $remainingMs <= 9_999_999_999) {
            $request = $request->withHeader('Connect-Timeout-Ms', (string) $remainingMs);
        }
        if ($get) {
            return $request->withoutHeader('Content-Type');
        }
        $request = $request
            ->withHeader('Content-Type', 'application/' . $this->codec->name())
            ->withBody($this->streamFactory->createStream($body));

        return $this->compression === null ? $request : $request->withHeader('Content-Encoding', $this->compression->name());
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
                ? $this->statusError($response, '', $metadata)
                : $this->error($response, $this->decompress($body, $compression), $metadata);
        }
        $mediaType = ContentType::mediaType($contentType);
        if ($mediaType !== 'application/' . $this->codec->name()) {
            $code = str_starts_with($mediaType, 'application/') ? Code::Internal : Code::Unknown;

            throw new ConnectException($code, \sprintf('unexpected content type "%s"', $contentType));
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

    private function error(ResponseInterface $response, string $body, Headers $metadata): ConnectException
    {
        $error = ContentType::mediaType($response->getHeaderLine('Content-Type')) === 'application/json'
            ? ErrorBody::decode($body, $metadata, Code::fromHttpStatus($response->getStatusCode()))
            : null;

        return $error ?? $this->statusError($response, $body, $metadata);
    }

    private function statusError(ResponseInterface $response, string $body, Headers $metadata): ConnectException
    {
        $message = rtrim('HTTP ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
        $isText = \in_array(ContentType::mediaType($response->getHeaderLine('Content-Type')), ['text/plain', 'application/json'], true);
        $snippet = $isText ? self::utf8Prefix($body, 1024) : '';

        return ConnectException::received(
            Code::fromHttpStatus($response->getStatusCode()),
            $snippet === '' ? $message : $message . ': ' . $snippet,
            [],
            $metadata,
        );
    }

    private static function utf8Prefix(string $text, int $maxBytes): string
    {
        if (preg_match('//u', $text) !== 1) {
            return '';
        }
        $prefix = substr($text, 0, $maxBytes);
        while (preg_match('//u', $prefix) !== 1) {
            $prefix = substr($prefix, 0, -1);
        }

        return $prefix;
    }
}
