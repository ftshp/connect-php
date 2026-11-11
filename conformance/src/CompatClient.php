<?php

declare(strict_types=1);

namespace Ftshp\Connect\Conformance;

use Connectrpc\Conformance\V1\ClientCompatRequest;
use Connectrpc\Conformance\V1\ClientCompatResponse;
use Connectrpc\Conformance\V1\ClientErrorResult;
use Connectrpc\Conformance\V1\ClientResponseResult;
use Connectrpc\Conformance\V1\Code as ConformanceCode;
use Connectrpc\Conformance\V1\Codec;
use Connectrpc\Conformance\V1\Compression;
use Connectrpc\Conformance\V1\ConformancePayload;
use Connectrpc\Conformance\V1\ConformanceServiceClient;
use Connectrpc\Conformance\V1\Error;
use Connectrpc\Conformance\V1\Header;
use Connectrpc\Conformance\V1\IdempotentUnaryRequest;
use Connectrpc\Conformance\V1\StreamType;
use Connectrpc\Conformance\V1\UnaryRequest;
use Connectrpc\Conformance\V1\UnimplementedRequest;
use Connectrpc\Conformance\V1\UnimplementedResponse;
use Ftshp\Connect\Client;
use Ftshp\Connect\Codec\JsonCodec;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\Compression\Gzip;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\ErrorDetail;
use Ftshp\Connect\Headers;
use Google\Protobuf\Any;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\HandlerStack;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;

final class CompatClient
{
    private const string SERVICE = 'connectrpc.conformance.v1.ConformanceService';

    public static function run(ClientCompatRequest $request): ClientCompatResponse
    {
        $response = new ClientCompatResponse(['test_name' => $request->getTestName()]);
        try {
            $response->setResponse(self::invoke($request));
        } catch (\Throwable $e) {
            $response->setError(new ClientErrorResult(['message' => $e->getMessage()]));
        }

        return $response;
    }

    private static function invoke(ClientCompatRequest $request): ClientResponseResult
    {
        if ($request->getService() !== self::SERVICE || $request->getStreamType() !== StreamType::STREAM_TYPE_UNARY) {
            throw new \InvalidArgumentException(\sprintf('unsupported call %s/%s', $request->getService(), $request->getMethod()));
        }
        if (\count($request->getRequestMessages()) !== 1) {
            throw new \InvalidArgumentException('a unary call needs exactly one request message');
        }
        $message = $request->getRequestMessages()[0];
        $service = new ConformanceServiceClient(self::client($request));
        $headers = [];
        foreach ($request->getRequestHeaders() as $header) {
            $headers[$header->getName()] = [...$headers[$header->getName()] ?? [], ...$header->getValue()];
        }
        $context = new Context(Headers::fromPsr($headers), $request->hasTimeoutMs() === true ? $request->getTimeoutMs() : null);

        $result = new ClientResponseResult();
        try {
            $response = match ($request->getMethod()) {
                'Unary' => $service->unary(self::unpack($message, new UnaryRequest()), $context),
                'IdempotentUnary' => $service->idempotentUnary(self::unpack($message, new IdempotentUnaryRequest()), $context),
                'Unimplemented' => $service->unimplemented(self::unpack($message, new UnimplementedRequest()), $context),
                default => throw new \InvalidArgumentException(\sprintf('unknown method %s', $request->getMethod())),
            };
            $payload = $response instanceof UnimplementedResponse ? null : $response->getPayload();
            $result->setPayloads([$payload ?? new ConformancePayload()]);
        } catch (ConnectException $e) {
            $result->setError(self::error($e));
        }
        $result->setResponseHeaders(self::headerList($context->responseHeaders));
        $result->setResponseTrailers(self::headerList($context->responseTrailers));

        return $result;
    }

    private static function client(ClientCompatRequest $request): Client
    {
        $stack = HandlerStack::create();
        $stack->push(self::timeoutFromHeader(...));
        $factory = new Psr17Factory();

        return new Client(
            new Guzzle(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false, 'decode_content' => false]),
            $factory,
            $factory,
            \sprintf('http://%s:%d', $request->getHost(), $request->getPort()),
            codec: match ($request->getCodec()) {
                Codec::CODEC_PROTO => new ProtoCodec(),
                Codec::CODEC_JSON => new JsonCodec(),
                default => throw new \InvalidArgumentException(\sprintf('unsupported codec %d', $request->getCodec())),
            },
            compression: match ($request->getCompression()) {
                Compression::COMPRESSION_IDENTITY, Compression::COMPRESSION_UNSPECIFIED => null,
                Compression::COMPRESSION_GZIP => new Gzip(),
                default => throw new \InvalidArgumentException(\sprintf('unsupported compression %d', $request->getCompression())),
            },
            useGet: $request->getUseGetHttpMethod(),
            maxResponseBytes: $request->getMessageReceiveLimit(),
        );
    }

    private static function timeoutFromHeader(callable $handler): callable
    {
        return static function (RequestInterface $request, array $options) use ($handler): mixed {
            $timeoutMs = $request->getHeaderLine('Connect-Timeout-Ms');
            if ($timeoutMs !== '') {
                $options['timeout'] = (int) $timeoutMs / 1000;
            }

            return $handler($request, $options);
        };
    }

    /**
     * @template T of Message
     *
     * @param T $into
     *
     * @return T
     */
    private static function unpack(Any $any, Message $into): Message
    {
        $expected = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($into::class)->getFullName();
        $actual = array_last(explode('/', $any->getTypeUrl()));
        if ($actual !== $expected) {
            throw new \InvalidArgumentException(\sprintf('expected a %s request, got %s', $expected, $actual));
        }
        $into->mergeFromString($any->getValue());

        return $into;
    }

    private static function error(ConnectException $e): Error
    {
        return new Error([
            'code' => ConformanceCode::value('CODE_' . strtoupper($e->code()->value)),
            'message' => $e->getMessage(),
            'details' => array_map(
                static fn(ErrorDetail $detail): Any => new Any(['type_url' => 'type.googleapis.com/' . $detail->type, 'value' => $detail->value]),
                $e->details(),
            ),
        ]);
    }

    /**
     * @return list<Header>
     */
    private static function headerList(Headers $headers): array
    {
        $list = [];
        foreach ($headers->toArray() as $name => $values) {
            $list[] = new Header(['name' => (string) $name, 'value' => $values]);
        }

        return $list;
    }
}
