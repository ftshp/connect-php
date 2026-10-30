<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Ftshp\Connect\Codec\Codec;
use Ftshp\Connect\Codec\JsonCodec;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\Compression\Compression;
use Ftshp\Connect\Compression\Gzip;
use Ftshp\Connect\Internal\Chain;
use Ftshp\Connect\Internal\ContentType;
use Ftshp\Connect\Internal\ErrorBody;
use Ftshp\Connect\Internal\GetQuery;
use Google\Protobuf\Internal\Message;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Server implements RequestHandlerInterface
{
    private const array BODY_HEADERS = ['content-encoding', 'content-length', 'transfer-encoding'];

    /** @var array<string, array{MethodDescriptor<Message, Message>, \Closure(Message, Context): Message}> */
    private array $procedures = [];

    /** @var array<string, Codec> */
    private readonly array $codecs;

    /** @var array<string, Compression> */
    private readonly array $compression;

    /**
     * @param list<Interceptor> $interceptors first is outermost
     * @param list<Compression> $compression accepted on requests and offered on responses
     * @param int $maxRequestBytes limit on raw and decompressed request bodies, 0 for none
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $interceptors = [],
        array $compression = [new Gzip()],
        private readonly int $compressMinBytes = 1024,
        private readonly int $maxRequestBytes = 4194304,
        private readonly ?int $maxTimeoutMs = null,
        private readonly bool $requireProtocolHeader = false,
        private readonly string $prefix = '',
    ) {
        $this->codecs = ['proto' => new ProtoCodec(), 'json' => new JsonCodec()];
        $byName = [];
        foreach ($compression as $algorithm) {
            $byName[strtolower($algorithm->name())] = $algorithm;
        }
        $this->compression = $byName;
    }

    /**
     * @param ServiceDescriptor|class-string $service a descriptor or the generated service interface
     */
    public function register(ServiceDescriptor|string $service, object $implementation): void
    {
        $descriptor = $service instanceof ServiceDescriptor ? $service : ServiceDescriptor::fromInterface($service);
        if (!$implementation instanceof $descriptor->interface) {
            throw new \InvalidArgumentException(\sprintf('%s does not implement %s', $implementation::class, $descriptor->interface));
        }
        foreach ($descriptor->methods as $method) {
            $this->procedures[$method->procedure()] = [$method, new \ReflectionMethod($implementation, $method->phpName)->getClosure($implementation)];
        }
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $procedure = str_starts_with($path, $this->prefix) ? substr($path, \strlen($this->prefix)) : null;
        if ($procedure === null || !isset($this->procedures[$procedure])) {
            return $this->error(new ConnectException(Code::Unimplemented, 'unknown procedure ' . $path), status: 404);
        }
        [$method, $handler] = $this->procedures[$procedure];

        $isGet = $request->getMethod() === 'GET' && $method->idempotency === Idempotency::NoSideEffects;
        if (!$isGet && $request->getMethod() !== 'POST') {
            return $this->responseFactory->createResponse(405)
                ->withHeader('Allow', $method->idempotency === Idempotency::NoSideEffects ? 'GET, POST' : 'POST');
        }

        $context = null;
        try {
            if ($this->requireProtocolHeader) {
                $this->checkProtocol($request, $isGet);
            }
            $query = $isGet ? GetQuery::parse($request->getQueryParams()) : null;
            $codec = $query !== null
                ? $this->codecs[$query['encoding']] ?? null
                : ContentType::codec($request->getHeaderLine('Content-Type'), $this->codecs);
            if ($codec === null) {
                return $this->responseFactory->createResponse(415)
                    ->withHeader('Accept-Post', 'application/json, application/proto');
            }
            $timeoutMs = $this->timeoutMs($request);
            $encoding = strtolower(trim($query !== null ? $query['compression'] ?? '' : $request->getHeaderLine('Content-Encoding')));
            $compression = null;
            if ($encoding !== '' && $encoding !== 'identity') {
                $compression = $this->compression[$encoding] ?? null;
                if ($compression === null) {
                    $supported = implode(', ', array_keys($this->compression));

                    return $this->error(new ConnectException(Code::Unimplemented, \sprintf('unknown compression "%s": supported encodings are %s', $encoding, $supported)))
                        ->withHeader('Accept-Encoding', $supported);
                }
            }

            $context = new Context(Headers::fromPsr($request->getHeaders()), $timeoutMs);
            $context->begin($method);

            $body = $query !== null ? $query['message'] : $request->getBody();
            $unary = new UnaryRequest(
                $method,
                $context,
                false,
                fn(): Message => $codec->decode($this->readBody($body, $compression), $method->input),
            );
            $call = Chain::build(
                $this->interceptors,
                static fn(UnaryRequest $unary): Message => $handler($unary->message(), $unary->context),
            );

            return $this->success($call($unary), $codec, $context, $request);
        } catch (\Throwable $e) {
            return $this->error(ConnectException::fromThrowable($e), $context);
        }
    }

    private function checkProtocol(ServerRequestInterface $request, bool $isGet): void
    {
        if ($isGet && ($request->getQueryParams()['connect'] ?? null) !== 'v1') {
            throw new ConnectException(Code::InvalidArgument, 'missing required query parameter: connect=v1');
        }
        if (!$isGet && !$request->hasHeader('Connect-Protocol-Version')) {
            throw new ConnectException(Code::InvalidArgument, 'missing required header: Connect-Protocol-Version');
        }
    }

    private function timeoutMs(ServerRequestInterface $request): ?int
    {
        if (!$request->hasHeader('Connect-Timeout-Ms')) {
            return null;
        }
        $value = $request->getHeaderLine('Connect-Timeout-Ms');
        if (preg_match('/^\d{1,10}\z/', $value) !== 1 || (int) $value === 0) {
            throw new ConnectException(Code::InvalidArgument, 'invalid timeout');
        }

        return $this->maxTimeoutMs === null ? (int) $value : min((int) $value, $this->maxTimeoutMs);
    }

    private function readBody(StreamInterface|string $body, ?Compression $compression): string
    {
        if ($body instanceof StreamInterface) {
            $body = $this->maxRequestBytes > 0 ? $this->readBounded($body) : (string) $body;
        } elseif ($this->maxRequestBytes > 0 && \strlen($body) > $this->maxRequestBytes) {
            throw new ConnectException(Code::ResourceExhausted, 'message size exceeds limit');
        }

        return $compression === null || $body === '' ? $body : $compression->decompress($body, $this->maxRequestBytes);
    }

    private function readBounded(StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (\strlen($body) > $this->maxRequestBytes) {
                throw new ConnectException(Code::ResourceExhausted, 'message size exceeds limit');
            }
        }

        return $body;
    }

    private function success(Message $message, Codec $codec, Context $context, ServerRequestInterface $request): ResponseInterface
    {
        $body = $codec->encode($message);
        $response = $this->withMetadata($this->responseFactory->createResponse(200), $context)
            ->withHeader('Content-Type', 'application/' . $codec->name());

        $compression = $this->responseCompression($request);
        if ($compression !== null && \strlen($body) >= $this->compressMinBytes) {
            $body = $compression->compress($body);
            $response = $response->withHeader('Content-Encoding', $compression->name());
        }

        return $response->withBody($this->streamFactory->createStream($body));
    }

    private function responseCompression(ServerRequestInterface $request): ?Compression
    {
        foreach (explode(',', $request->getHeaderLine('Accept-Encoding')) as $token) {
            $name = strtolower(trim(explode(';', $token, 2)[0]));
            if (isset($this->compression[$name])) {
                return $this->compression[$name];
            }
        }

        return null;
    }

    private function error(ConnectException $e, ?Context $context = null, ?int $status = null): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status ?? $e->code()->httpStatus());
        if ($context !== null) {
            $response = $this->withMetadata($response, $context, skipRejected: true);
        }

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(ErrorBody::encode($e)));
    }

    private function withMetadata(ResponseInterface $response, Context $context, bool $skipRejected = false): ResponseInterface
    {
        $headers = array_diff_key($context->responseHeaders->toArray(), array_flip(self::BODY_HEADERS));
        foreach ($context->responseTrailers->toArray() as $name => $values) {
            $headers['trailer-' . $name] = $values;
        }
        foreach ($headers as $name => $values) {
            try {
                $response = $response->withHeader((string) $name, $values);
            } catch (\InvalidArgumentException $e) {
                if (!$skipRejected) {
                    throw $e;
                }
            }
        }

        return $response;
    }
}
