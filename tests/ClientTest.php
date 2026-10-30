<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Client;
use Ftshp\Connect\Code;
use Ftshp\Connect\Codec\Codec;
use Ftshp\Connect\Codec\JsonCodec;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\Compression\Compression;
use Ftshp\Connect\Compression\Gzip;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\Headers;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\Internal\ErrorBody;
use Ftshp\Connect\MethodDescriptor;
use Ftshp\Connect\ServiceDescriptor;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\Tests\Fixture\FakeClientException;
use Ftshp\Connect\Tests\Fixture\FakeHttpClient;
use Ftshp\Connect\Tests\Fixture\FakeNetworkException;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    private FakeHttpClient $http;

    /**
     * @param \Closure(RequestInterface): ResponseInterface $respond
     * @param list<Interceptor> $interceptors
     */
    private function client(
        \Closure $respond,
        Codec $codec = new ProtoCodec(),
        ?Compression $compression = null,
        array $interceptors = [],
        ?int $timeoutMs = null,
        int $maxResponseBytes = 4194304,
        string $prefix = '',
    ): Client {
        $factory = new Psr17Factory();
        $this->http = new FakeHttpClient($respond);

        return new Client(
            $this->http,
            $factory,
            $factory,
            'https://api.test/',
            codec: $codec,
            compression: $compression,
            interceptors: $interceptors,
            timeoutMs: $timeoutMs,
            maxResponseBytes: $maxResponseBytes,
            prefix: $prefix,
        );
    }

    /**
     * @return MethodDescriptor<EchoRequest, EchoResponse>
     */
    private function echo(): MethodDescriptor
    {
        /** @var MethodDescriptor<EchoRequest, EchoResponse> */
        return ServiceDescriptor::fromInterface(EchoInterface::class)->methods['Echo'];
    }

    /**
     * @param array<string, string> $headers
     */
    private static function respond(int $status = 200, string $body = '', array $headers = []): \Closure
    {
        return static fn(RequestInterface $request): ResponseInterface => new Response($status, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function ok(string $text, array $headers = []): \Closure
    {
        return self::respond(200, new EchoResponse(['text' => $text])->serializeToString(), ['Content-Type' => 'application/proto'] + $headers);
    }

    private function sentRequest(): RequestInterface
    {
        self::assertNotNull($this->http->lastRequest);

        return $this->http->lastRequest;
    }

    private function call(Client $client, string $text = 'hi', Context $context = new Context()): EchoResponse
    {
        return $client->unary($this->echo(), new EchoRequest(['text' => $text]), $context);
    }

    private function callError(Client $client): ConnectException
    {
        try {
            $this->call($client);
        } catch (ConnectException $e) {
            return $e;
        }
        self::fail('expected a ConnectException');
    }

    #[Test]
    public function postRequestShape(): void
    {
        $client = $this->client(self::ok('HI'), timeoutMs: 500, prefix: '/rpc');
        $context = new Context(new Headers(['x-token' => 'secret']));

        $this->call($client, 'hi', $context);

        $request = $this->sentRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.test/rpc/ftshp.connect.test.Echo/Echo', (string) $request->getUri());
        $headers = array_change_key_case($request->getHeaders());
        unset($headers['host']);
        ksort($headers);
        self::assertSame([
            'accept-encoding' => ['gzip'],
            'connect-protocol-version' => ['1'],
            'connect-timeout-ms' => ['500'],
            'content-type' => ['application/proto'],
            'x-token' => ['secret'],
        ], $headers);
        $sent = new EchoRequest();
        $sent->mergeFromString((string) $request->getBody());
        self::assertSame('hi', $sent->getText());
        self::assertSame(500, $context->timeoutMs);
        self::assertNotNull($context->deadline);
        self::assertSame($this->echo(), $context->method);
    }

    #[Test]
    public function jsonCodec(): void
    {
        $client = $this->client(self::respond(200, '{"text":"HI"}', ['Content-Type' => 'application/json']), codec: new JsonCodec());

        $response = $this->call($client);

        self::assertSame('HI', $response->getText());
        self::assertSame('application/json', $this->sentRequest()->getHeaderLine('Content-Type'));
        self::assertSame('{"text":"hi"}', (string) $this->sentRequest()->getBody());
    }

    #[Test]
    public function successIsDecoded(): void
    {
        self::assertSame('HI', $this->call($this->client(self::ok('HI')))->getText());
    }

    #[Test]
    public function gzipResponseIsInflated(): void
    {
        $body = new Gzip()->compress(new EchoResponse(['text' => 'HI'])->serializeToString());
        $client = $this->client(self::respond(200, $body, ['Content-Type' => 'application/proto', 'Content-Encoding' => 'gzip']));

        self::assertSame('HI', $this->call($client)->getText());
    }

    #[Test]
    public function emptyGzipResponseIsNotInflated(): void
    {
        $client = $this->client(self::respond(200, '', ['Content-Type' => 'application/proto', 'Content-Encoding' => 'gzip']));

        self::assertSame('', $this->call($client)->getText());
    }

    #[Test]
    public function gzipResponseInflatingPastLimitIsResourceExhausted(): void
    {
        $body = new Gzip()->compress(new EchoResponse(['text' => str_repeat('a', 1000)])->serializeToString());
        $client = $this->client(self::respond(200, $body, ['Content-Type' => 'application/proto', 'Content-Encoding' => 'gzip']), maxResponseBytes: 100);

        self::assertLessThan(100, \strlen($body));
        self::assertSame(Code::ResourceExhausted, $this->callError($client)->code());
    }

    #[Test]
    public function identityContentEncodingIsDecoded(): void
    {
        self::assertSame('HI', $this->call($this->client(self::ok('HI', ['Content-Encoding' => 'identity'])))->getText());
    }

    #[Test]
    public function partlyReadSeekableResponseIsRewound(): void
    {
        $client = $this->client(static function (RequestInterface $request): ResponseInterface {
            $response = new Response(200, ['Content-Type' => 'application/proto'], new EchoResponse(['text' => 'HI'])->serializeToString());
            $response->getBody()->getContents();

            return $response;
        });

        self::assertSame('HI', $this->call($client)->getText());
    }

    #[Test]
    public function responseOverLimitIsResourceExhausted(): void
    {
        $client = $this->client(self::ok(str_repeat('a', 100)), maxResponseBytes: 50);

        $e = $this->callError($client);

        self::assertSame(Code::ResourceExhausted, $e->code());
        self::assertSame('message size exceeds limit', $e->getMessage());
    }

    #[Test]
    public function responseHeadersAndTrailersAreExposed(): void
    {
        $client = $this->client(self::ok('HI', ['X-Echoed' => '1', 'Trailer-X-Trailer' => 't']));
        $context = new Context();

        $this->call($client, 'hi', $context);

        self::assertSame('1', $context->responseHeaders->get('x-echoed'));
        self::assertFalse($context->responseHeaders->has('trailer-x-trailer'));
        self::assertSame('t', $context->responseTrailers->get('x-trailer'));
    }

    #[Test]
    public function connectErrorIsParsed(): void
    {
        $detail = new Struct(['fields' => ['reason' => new Value(['string_value' => 'detail'])]]);
        $body = ErrorBody::encode(new ConnectException(Code::NotFound, 'no such thing', [$detail]));
        $client = $this->client(self::respond(404, $body, ['Content-Type' => 'application/json', 'X-Err' => '1']));

        $e = $this->callError($client);

        self::assertSame(Code::NotFound, $e->code());
        self::assertSame('no such thing', $e->getMessage());
        self::assertCount(1, $e->details());
        self::assertSame('1', $e->metadata()->get('x-err'));
    }

    #[Test]
    public function gzipConnectErrorIsParsed(): void
    {
        $body = new Gzip()->compress(ErrorBody::encode(new ConnectException(Code::NotFound, 'no such thing')));
        $client = $this->client(self::respond(404, $body, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']));

        $e = $this->callError($client);

        self::assertSame(Code::NotFound, $e->code());
        self::assertSame('no such thing', $e->getMessage());
    }

    #[Test]
    public function errorWithUnknownContentEncodingFallsBackToStatus(): void
    {
        $client = $this->client(self::respond(404, 'xx', ['Content-Type' => 'application/json', 'Content-Encoding' => 'br']));

        $e = $this->callError($client);

        self::assertSame(Code::Unimplemented, $e->code());
        self::assertSame('HTTP 404: xx', $e->getMessage());
    }

    #[Test]
    public function html502IsUnavailableWithBody(): void
    {
        $e = $this->callError($this->client(self::respond(502, '<html>Bad Gateway</html>', ['Content-Type' => 'text/html', 'Trailer-X-T' => 't'])));

        self::assertSame(Code::Unavailable, $e->code());
        self::assertSame('HTTP 502: <html>Bad Gateway</html>', $e->getMessage());
        self::assertSame('t', $e->metadata()->get('x-t'));
    }

    #[Test]
    public function empty503IsUnavailable(): void
    {
        $e = $this->callError($this->client(self::respond(503)));

        self::assertSame(Code::Unavailable, $e->code());
        self::assertSame('HTTP 503', $e->getMessage());
    }

    #[Test]
    public function nullCodeFallsBackToStatus(): void
    {
        $e = $this->callError($this->client(self::respond(400, '{"code":null}', ['Content-Type' => 'application/json'])));

        self::assertSame(Code::Internal, $e->code());
        self::assertSame('HTTP 400: {"code":null}', $e->getMessage());
    }

    #[Test]
    public function unknownCodeFallsBackToStatus(): void
    {
        $e = $this->callError($this->client(self::respond(404, '{"code":"bad_route"}', ['Content-Type' => 'application/json; charset=utf-8'])));

        self::assertSame(Code::Unimplemented, $e->code());
    }

    #[Test]
    public function wrongContentTypeOn200IsInternal(): void
    {
        $e = $this->callError($this->client(self::respond(200, '<html></html>', ['Content-Type' => 'text/html'])));

        self::assertSame(Code::Internal, $e->code());
        self::assertSame('unexpected content type "text/html"', $e->getMessage());
    }

    #[Test]
    public function unsupportedContentEncodingOnResponseIsInternal(): void
    {
        $e = $this->callError($this->client(self::respond(200, 'xx', ['Content-Type' => 'application/proto', 'Content-Encoding' => 'br'])));

        self::assertSame(Code::Internal, $e->code());
        self::assertSame('unknown compression "br"', $e->getMessage());
    }

    #[Test]
    public function networkErrorIsUnavailable(): void
    {
        $e = $this->callError($this->client(static fn(RequestInterface $r): never => throw new FakeNetworkException($r, 'connection refused')));

        self::assertSame(Code::Unavailable, $e->code());
        self::assertSame('connection refused', $e->getMessage());
        self::assertInstanceOf(FakeNetworkException::class, $e->getPrevious());
    }

    #[Test]
    public function networkErrorAfterTimeoutIsDeadlineExceeded(): void
    {
        $client = $this->client(
            static function (RequestInterface $r): never {
                usleep(20000);

                throw new FakeNetworkException($r, 'timed out');
            },
            timeoutMs: 10,
        );

        $e = $this->callError($client);

        self::assertSame(Code::DeadlineExceeded, $e->code());
        self::assertInstanceOf(FakeNetworkException::class, $e->getPrevious());
    }

    #[Test]
    public function otherClientExceptionIsUnknown(): void
    {
        $e = $this->callError($this->client(static fn(RequestInterface $r): never => throw new FakeClientException('bad request')));

        self::assertSame(Code::Unknown, $e->code());
        self::assertSame('bad request', $e->getMessage());
        self::assertInstanceOf(FakeClientException::class, $e->getPrevious());
    }

    #[Test]
    public function requestCompressionWhenConfigured(): void
    {
        $this->call($this->client(self::ok('HI'), compression: new Gzip()));

        $request = $this->sentRequest();
        self::assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
        $sent = new EchoRequest();
        $sent->mergeFromString(new Gzip()->decompress((string) $request->getBody(), 0));
        self::assertSame('hi', $sent->getText());
    }

    #[Test]
    public function emptyRequestIsNotCompressed(): void
    {
        $this->call($this->client(self::ok('HI'), compression: new Gzip()), '');

        $request = $this->sentRequest();
        self::assertFalse($request->hasHeader('Content-Encoding'));
        self::assertSame('', (string) $request->getBody());
    }

    #[Test]
    public function userContentEncodingIsDroppedFromUncompressedRequest(): void
    {
        $this->call($this->client(self::ok('HI')), 'hi', new Context(new Headers(['content-encoding' => 'gzip'])));

        self::assertFalse($this->sentRequest()->hasHeader('Content-Encoding'));
    }

    #[Test]
    public function interceptorsWrapTheCall(): void
    {
        $interceptor = new class implements Interceptor {
            public ?string $seen = null;
            public ?bool $isClient = null;

            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $this->isClient = $request->isClient;
                $request->context->requestHeaders->set('x-added', 'yes');
                $response = $next($request);
                $this->seen = $request->context->responseHeaders->get('x-echoed');

                return $response;
            }
        };
        $client = $this->client(self::ok('HI', ['X-Echoed' => '1']), interceptors: [$interceptor]);

        self::assertSame('HI', $this->call($client)->getText());
        self::assertSame('yes', $this->sentRequest()->getHeaderLine('x-added'));
        self::assertSame('1', $interceptor->seen);
        self::assertTrue($interceptor->isClient);
    }

    #[Test]
    public function userHeaderWithConnectPrefixIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Context(new Headers(['connect-timeout-ms' => '1']));
    }
}
