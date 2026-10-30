<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
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
        int $maxRequestBytes = 0,
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

        $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Protocol-Version' => '1'], $server);
        self::assertSame(200, $response->getStatusCode());
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
        foreach (['12345678901', '0', 'abc', '-5'] as $value) {
            $response = $this->post(self::ECHO, 'application/proto', $this->proto('a'), ['Connect-Timeout-Ms' => $value]);

            self::assertSame(400, $response->getStatusCode(), $value);
            self::assertSame(['code' => 'invalid_argument', 'message' => 'invalid timeout'], $this->json($response));
        }
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
    public function prefixIsStripped(): void
    {
        $server = $this->server(prefix: '/rpc');

        self::assertSame(200, $this->post('/rpc' . self::ECHO, 'application/proto', $this->proto('a'), server: $server)->getStatusCode());
        self::assertSame(404, $this->post(self::ECHO, 'application/proto', $this->proto('a'), server: $server)->getStatusCode());
    }

    #[Test]
    public function registerRejectsWrongImplementation(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        new Server($factory, $factory)->register(EchoInterface::class, new \stdClass());
    }
}
