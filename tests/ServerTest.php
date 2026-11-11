<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Headers;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\Server;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\Test\FailRequest;
use Ftshp\Connect\Tests\Fixture\EchoService;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ServerTest extends TestCase
{
    private const string ECHO = '/ftshp.connect.test.Echo/Echo';

    /**
     * @param list<Interceptor> $interceptors
     */
    private function server(
        array $interceptors = [],
        int $compressMinBytes = 1024,
        int $maxRequestBytes = 4194304,
        ?int $maxTimeoutMs = null,
        bool $requireProtocolHeader = false,
        string $prefix = '',
    ): Server {
        $factory = new Psr17Factory();
        $server = new Server(
            $factory,
            $factory,
            interceptors: $interceptors,
            compressMinBytes: $compressMinBytes,
            maxRequestBytes: $maxRequestBytes,
            maxTimeoutMs: $maxTimeoutMs,
            requireProtocolHeader: $requireProtocolHeader,
            prefix: $prefix,
        );
        $server->register(EchoInterface::class, new EchoService());

        return $server;
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(
        string $path,
        string $contentType,
        string $body,
        array $headers = [],
        ?Server $server = null,
        string $method = 'POST',
    ): ResponseInterface {
        $request = new ServerRequest($method, $path, ['Content-Type' => $contentType] + $headers, $body);

        return ($server ?? $this->server())->handle($request);
    }

    private function proto(string $text): string
    {
        return new EchoRequest(['text' => $text])->serializeToString();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function echoResponse(string $bytes): EchoResponse
    {
        $response = new EchoResponse();
        $response->mergeFromString($bytes);

        return $response;
    }

    #[Test]
    public function protoRoundTrip(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/proto', $response->getHeaderLine('content-type'));
        self::assertSame('HELLO', $this->echoResponse((string) $response->getBody())->getText());
    }

    #[Test]
    public function jsonRoundTrip(): void
    {
        $response = $this->post(self::ECHO, 'application/json', '{"text":"hello"}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('HELLO', $this->json($response)['text']);
    }

    #[Test]
    public function jsonContentTypeWithCharsetIsAccepted(): void
    {
        $response = $this->post(self::ECHO, 'Application/JSON; charset=UTF-8', '{"text":"hello"}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HELLO', $this->json($response)['text']);
    }

    #[Test]
    public function unknownProcedureIsUnimplemented(): void
    {
        $response = $this->post('/ftshp.connect.test.Echo/Nope', 'application/proto', '');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame(
            ['code' => 'unimplemented', 'message' => 'unknown procedure /ftshp.connect.test.Echo/Nope'],
            $this->json($response),
        );
    }

    #[Test]
    public function pathWithExtraSegmentsIsUnimplemented(): void
    {
        $response = $this->post('/ftshp.connect.test.Echo/Echo/x', 'application/proto', '');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function wrongMethodIs405WithAllow(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', '', method: 'PUT');
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('allow'));
        self::assertSame('', (string) $response->getBody());

        $response = $this->post('/ftshp.connect.test.Echo/Peek', 'application/proto', '', method: 'PUT');
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('allow'));
    }

    #[Test]
    public function unsupportedContentTypeIs415(): void
    {
        $response = $this->post(self::ECHO, 'text/plain', 'hello');

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('application/json, application/proto', $response->getHeaderLine('accept-post'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function requiredProtocolHeader(): void
    {
        $server = $this->server(requireProtocolHeader: true);

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $server);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['code' => 'invalid_argument', 'message' => 'missing required header: Connect-Protocol-Version'],
            $this->json($response),
        );

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Protocol-Version' => '2'], $server);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['code' => 'invalid_argument', 'message' => 'Connect-Protocol-Version must be "1": got "2"'],
            $this->json($response),
        );

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Protocol-Version' => '1'], $server);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function wrongProtocolVersionIsRejectedWhenNotRequired(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Protocol-Version' => '2']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['code' => 'invalid_argument', 'message' => 'Connect-Protocol-Version must be "1": got "2"'],
            $this->json($response),
        );
        self::assertSame(200, $this->post(self::ECHO, 'application/proto', $this->proto('a'))->getStatusCode());
    }

    #[Test]
    public function timeoutIsParsedAndClamped(): void
    {
        $response = $this->post(
            self::ECHO,
            'application/proto',
            $this->proto('a'),
            ['Connect-Timeout-Ms' => '2500'],
            $this->server(maxTimeoutMs: 1000),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1000', $response->getHeaderLine('x-timeout'));
        self::assertLessThanOrEqual(1000, (int) $response->getHeaderLine('x-remaining'));
        self::assertGreaterThan(0, (int) $response->getHeaderLine('x-remaining'));
    }

    #[Test]
    public function timeoutBelowTheClampIsKept(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Timeout-Ms' => '2500']);

        self::assertSame('2500', $response->getHeaderLine('x-timeout'));
    }

    #[Test]
    public function invalidTimeoutIs400(): void
    {
        foreach (['12345678901', 'abc', '-5'] as $value) {
            $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Timeout-Ms' => $value]);

            self::assertSame(400, $response->getStatusCode(), $value);
            self::assertSame(['code' => 'invalid_argument', 'message' => 'invalid timeout'], $this->json($response));
        }
    }

    #[Test]
    public function zeroTimeoutIsDeadlineExceededWithoutRunningTheHandler(): void
    {
        $interceptor = new class implements Interceptor {
            public bool $ran = false;

            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $this->ran = true;

                return $next($request);
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Timeout-Ms' => '0'], $this->server([$interceptor]));

        self::assertSame(504, $response->getStatusCode());
        self::assertSame(['code' => 'deadline_exceeded', 'message' => 'deadline exceeded'], $this->json($response));
        self::assertTrue($interceptor->ran);
        self::assertFalse($response->hasHeader('x-echoed'));
    }

    #[Test]
    public function deadlinePassedInInterceptorsSkipsTheHandler(): void
    {
        $slow = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                usleep(5000);

                return $next($request);
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Timeout-Ms' => '1'], $this->server([$slow]));

        self::assertSame(504, $response->getStatusCode());
        self::assertSame('deadline_exceeded', $this->json($response)['code']);
        self::assertFalse($response->hasHeader('x-echoed'));
    }

    #[Test]
    public function unknownContentEncodingIsUnimplemented(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Content-Encoding' => 'br']);

        self::assertSame(501, $response->getStatusCode());
        self::assertSame('gzip', $response->getHeaderLine('accept-encoding'));
        self::assertSame(
            ['code' => 'unimplemented', 'message' => 'unknown compression "br": supported encodings are gzip'],
            $this->json($response),
        );
    }

    #[Test]
    public function identityContentEncodingIsUncompressed(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Content-Encoding' => 'identity']);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function gzipRequestIsDecoded(): void
    {
        $body = (string) gzencode($this->proto('zipped'));
        $response = $this->post(self::ECHO, 'application/proto', $body, ['Content-Encoding' => 'gzip']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ZIPPED', $this->echoResponse((string) $response->getBody())->getText());
    }

    #[Test]
    public function emptyGzipBodyIsNotDecompressed(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', '', ['Content-Encoding' => 'gzip']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $this->echoResponse((string) $response->getBody())->getText());
    }

    #[Test]
    public function requestOverLimitIsResourceExhausted(): void
    {
        $response = $this->post(
            self::ECHO,
            'application/proto',
            $this->proto('more than ten bytes'),
            server: $this->server(maxRequestBytes: 10),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['code' => 'resource_exhausted', 'message' => 'message size exceeds limit'], $this->json($response));
    }

    #[Test]
    public function inflatedRequestOverLimitIsResourceExhausted(): void
    {
        $response = $this->post(
            self::ECHO,
            'application/proto',
            (string) gzencode($this->proto(str_repeat('a', 1000))),
            ['Content-Encoding' => 'gzip'],
            $this->server(maxRequestBytes: 100),
        );

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function gzipBombIsRejectedByDefault(): void
    {
        $factory = new Psr17Factory();
        $server = new Server($factory, $factory);
        $server->register(EchoInterface::class, new EchoService());

        $response = $this->post(
            self::ECHO,
            'application/proto',
            (string) gzencode($this->proto(str_repeat('a', 5 * 1024 * 1024))),
            ['Content-Encoding' => 'gzip'],
            $server,
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['code' => 'resource_exhausted', 'message' => 'message size exceeds limit'], $this->json($response));
    }

    #[Test]
    public function largeBodyIsRejectedByDefaultAndAcceptedWithoutLimit(): void
    {
        $body = $this->proto(str_repeat('a', 5 * 1024 * 1024));
        $factory = new Psr17Factory();
        $default = new Server($factory, $factory);
        $default->register(EchoInterface::class, new EchoService());

        self::assertSame(429, $this->post(self::ECHO, 'application/proto', $body, server: $default)->getStatusCode());
        self::assertSame(200, $this->post(self::ECHO, 'application/proto', $body, server: $this->server(maxRequestBytes: 0))->getStatusCode());
    }

    #[Test]
    public function undecodableBodyIsInvalidArgument(): void
    {
        $response = $this->post(self::ECHO, 'application/json', '{not json');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_argument', $this->json($response)['code']);
    }

    #[Test]
    public function responseIsGzippedWhenAcceptedAndLarge(): void
    {
        $response = $this->post(
            self::ECHO,
            'application/proto',
            $this->proto('hello'),
            ['Accept-Encoding' => 'br;q=0.9, gzip'],
            $this->server(compressMinBytes: 1),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('gzip', $response->getHeaderLine('content-encoding'));
        self::assertSame('HELLO', $this->echoResponse((string) gzdecode((string) $response->getBody()))->getText());
    }

    #[Test]
    public function encodingRefusedWithZeroQualityIsNotUsed(): void
    {
        foreach (['gzip;q=0', 'gzip; Q=0.000', 'gzip;q=0, identity', 'gzip;level=1;q=0'] as $accept) {
            $response = $this->post(self::ECHO, 'application/proto', $this->proto(str_repeat('a', 2048)), ['Accept-Encoding' => $accept]);

            self::assertFalse($response->hasHeader('content-encoding'), $accept);
        }
        $response = $this->post(self::ECHO, 'application/proto', $this->proto(str_repeat('a', 2048)), ['Accept-Encoding' => 'gzip;q=0.5']);
        self::assertSame('gzip', $response->getHeaderLine('content-encoding'));
    }

    #[Test]
    public function smallResponseIsNotCompressed(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('hello'), ['Accept-Encoding' => 'gzip']);

        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertSame('HELLO', $this->echoResponse((string) $response->getBody())->getText());
    }

    #[Test]
    public function errorBodyIsNeverCompressed(): void
    {
        $body = new FailRequest(['code' => 'not_found', 'message' => str_repeat('x', 100)])->serializeToString();
        $response = $this->post(
            '/ftshp.connect.test.Echo/Fail',
            'application/proto',
            $body,
            ['Accept-Encoding' => 'gzip'],
            $this->server(compressMinBytes: 1),
        );

        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertSame('not_found', $this->json($response)['code']);
    }

    #[Test]
    public function handlerErrorBecomesJsonWithStatus(): void
    {
        $response = $this->post('/ftshp.connect.test.Echo/Fail', 'application/json', '{"code":"not_found","message":"no such thing"}');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame(['code' => 'not_found', 'message' => 'no such thing'], $this->json($response));
    }

    #[Test]
    public function errorDetailIsEncoded(): void
    {
        $response = $this->post('/ftshp.connect.test.Echo/Fail', 'application/json', '{"code":"aborted","withDetail":true}');

        $body = $this->json($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertIsArray($body['details']);
        self::assertIsArray($body['details'][0]);
        self::assertSame('google.protobuf.Struct', $body['details'][0]['type']);
        self::assertIsString($body['details'][0]['value']);
        self::assertStringEndsNotWith('=', $body['details'][0]['value']);
    }

    #[Test]
    public function unexpectedExceptionIsUnknownWithEmptyMessage(): void
    {
        $response = $this->post('/ftshp.connect.test.Echo/Fail', 'application/json', '{"code":"boom"}');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"code":"unknown"}', (string) $response->getBody());
    }

    #[Test]
    public function responseHeadersAndTrailers(): void
    {
        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['X-Custom' => 'v']);

        self::assertSame('1', $response->getHeaderLine('x-echoed'));
        self::assertSame('t', $response->getHeaderLine('trailer-x-trailer'));
        self::assertSame(['x-custom' => 'v'], iterator_to_array($this->echoResponse((string) $response->getBody())->getHeaders()));
    }

    #[Test]
    public function errorMetadataIsSentAsHeaders(): void
    {
        $reject = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $request->context->responseHeaders->set('x-reason', 'context');
                $request->context->responseTrailers->set('x-trailer', 't');
                $e = new ConnectException(Code::NotFound, 'gone', metadata: new Headers(['x-reason' => 'error', 'x-id-bin' => "\x00\x01"]));
                $e->metadata()->add('x-added', '1');

                throw $e;
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $this->server([$reject]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['context', 'error'], $response->getHeader('x-reason'));
        self::assertSame('AAE', $response->getHeaderLine('x-id-bin'));
        self::assertSame('1', $response->getHeaderLine('x-added'));
        self::assertSame('t', $response->getHeaderLine('trailer-x-trailer'));
        self::assertSame(['code' => 'not_found', 'message' => 'gone'], $this->json($response));
    }

    #[Test]
    public function errorMetadataCannotReplaceProtocolHeaders(): void
    {
        $reject = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                throw new ConnectException(Code::NotFound, metadata: Headers::fromPsr([
                    'content-type' => ['text/html'],
                    'content-length' => ['3'],
                    'content-encoding' => ['gzip'],
                    'transfer-encoding' => ['chunked'],
                    'accept-encoding' => ['br'],
                    'connect-timeout-ms' => ['1'],
                    'grpc-status' => ['0'],
                    'grpc-message' => ['ok'],
                    'grpc-status-details-bin' => ['AA'],
                    'grpc-timeout' => ['1S'],
                    'grpc-encoding' => ['gzip'],
                    'grpc-accept-encoding' => ['gzip'],
                    'trailer-x-forged' => ['1'],
                    'x-bad' => ["a\x00b"],
                    'bad name' => ['1'],
                    'x-kept' => ['1'],
                ]));
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $this->server([$reject]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        foreach ([
            'content-length', 'content-encoding', 'transfer-encoding', 'accept-encoding', 'connect-timeout-ms',
            'grpc-status', 'grpc-message', 'grpc-status-details-bin', 'grpc-timeout', 'grpc-encoding', 'grpc-accept-encoding',
            'trailer-x-forged', 'x-bad', 'bad name',
        ] as $name) {
            self::assertFalse($response->hasHeader($name), $name);
        }
        self::assertSame('1', $response->getHeaderLine('x-kept'));
        self::assertSame(['code' => 'not_found'], $this->json($response));
    }

    #[Test]
    public function metadataOfAReceivedErrorIsNotForwarded(): void
    {
        $forward = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                throw ConnectException::received(Code::Unavailable, 'upstream down', [], new Headers(['set-cookie' => 'upstream=1']));
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $this->server([$forward]));

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($response->hasHeader('set-cookie'));
        self::assertSame(['code' => 'unavailable', 'message' => 'upstream down'], $this->json($response));
    }

    #[Test]
    public function handlerSetBodyHeadersAreNotEmitted(): void
    {
        $copy = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $request->context->responseHeaders->set('content-encoding', 'br');
                $request->context->responseHeaders->set('content-length', '999');
                $request->context->responseHeaders->set('transfer-encoding', 'chunked');
                $request->context->responseHeaders->set('x-kept', '1');

                return $next($request);
            }
        };
        $server = $this->server([$copy]);

        foreach ([self::ECHO, '/ftshp.connect.test.Echo/Fail'] as $path) {
            $response = $this->post($path, 'application/proto', $this->proto('hello'), server: $server);

            self::assertFalse($response->hasHeader('content-encoding'), $path);
            self::assertFalse($response->hasHeader('content-length'), $path);
            self::assertFalse($response->hasHeader('transfer-encoding'), $path);
            self::assertSame('1', $response->getHeaderLine('x-kept'), $path);
        }
    }

    #[Test]
    public function headerTheResponseRejectsBecomesUnknown(): void
    {
        $copy = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $response = $next($request);
                $request->context->responseHeaders->replace(Headers::fromPsr(['x-bad' => ["a\x00b"], 'x-kept' => ['1']]));

                return $response;
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('hello'), server: $this->server([$copy]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"code":"unknown"}', (string) $response->getBody());
        self::assertFalse($response->hasHeader('x-bad'));
        self::assertSame('1', $response->getHeaderLine('x-kept'));
    }

    #[Test]
    public function interceptorsRunOuterToInnerAndSeeOriginalException(): void
    {
        $seen = new \ArrayObject();
        $outer = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $request->context->responseHeaders->set('x-outer', 'yes');

                return $next($request);
            }
        };
        $inner = new class ($seen) implements Interceptor {
            /** @param \ArrayObject<int, \Throwable> $seen */
            public function __construct(private readonly \ArrayObject $seen) {}

            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                try {
                    return $next($request);
                } catch (\Throwable $e) {
                    $this->seen->append($e);

                    throw $e;
                }
            }
        };

        $response = $this->post(
            '/ftshp.connect.test.Echo/Fail',
            'application/json',
            '{"code":"boom"}',
            server: $this->server([$outer, $inner]),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('x-outer'));
        self::assertCount(1, $seen);
        self::assertInstanceOf(\RuntimeException::class, $seen[0]);
        self::assertNotInstanceOf(ConnectException::class, $seen[0]);
        self::assertSame('boom', $seen[0]->getMessage());
    }

    #[Test]
    public function interceptorCanRejectBeforeDecoding(): void
    {
        $reject = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                throw new ConnectException(Code::Unauthenticated, 'no token');
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', "\xff\xff garbage", server: $this->server([$reject]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['code' => 'unauthenticated', 'message' => 'no token'], $this->json($response));
    }

    #[Test]
    public function interceptorReturningTheWrongTypeIsInternal(): void
    {
        $wrong = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                return new EchoRequest();
            }
        };

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $this->server([$wrong]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            ['code' => 'internal', 'message' => 'interceptor returned Ftshp\Connect\Test\EchoRequest, expected Ftshp\Connect\Test\EchoResponse'],
            $this->json($response),
        );
    }

    #[Test]
    public function prefixIsStripped(): void
    {
        foreach (['rpc', '/rpc', '/rpc/'] as $prefix) {
            $server = $this->server(prefix: $prefix);

            self::assertSame(200, $this->post('/rpc' . self::ECHO, 'application/proto', $this->proto('a'), server: $server)->getStatusCode(), $prefix);
            self::assertSame(404, $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $server)->getStatusCode(), $prefix);
        }
    }

    #[Test]
    public function registerRejectsWrongImplementation(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        new Server($factory, $factory)->register(EchoInterface::class, new \stdClass());
    }
}
