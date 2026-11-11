<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Integration;

use Ftshp\Connect\Client;
use Ftshp\Connect\Code;
use Ftshp\Connect\Codec\Codec;
use Ftshp\Connect\Codec\JsonCodec;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\Headers;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\Server;
use Ftshp\Connect\Test\EchoClient;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\Test\FailRequest;
use Ftshp\Connect\Test\PBEmpty;
use Ftshp\Connect\Tests\Fixture\EchoService;
use Ftshp\Connect\Tests\Fixture\InProcessHttpClient;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundTripTest extends TestCase
{
    private InProcessHttpClient $http;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $reached;

    /**
     * @return array<string, array{Codec, bool}>
     */
    public static function transports(): array
    {
        return [
            'proto, POST' => [new ProtoCodec(), false],
            'proto, GET' => [new ProtoCodec(), true],
            'json, POST' => [new JsonCodec(), false],
            'json, GET' => [new JsonCodec(), true],
        ];
    }

    private function client(
        Codec $codec,
        bool $useGet,
        ?int $timeoutMs = null,
        int $maxResponseBytes = 4194304,
        int $maxRequestBytes = 4194304,
    ): EchoClient {
        $factory = new Psr17Factory();
        $auth = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                if (!hash_equals('secret', $request->context->requestHeaders->get('x-token') ?? '')) {
                    throw new ConnectException(Code::Unauthenticated, 'invalid token');
                }

                return $next($request);
            }
        };
        $this->reached = new \ArrayObject();
        $recorder = new class ($this->reached) implements Interceptor {
            /** @param \ArrayObject<int, string> $reached */
            public function __construct(private readonly \ArrayObject $reached) {}

            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $this->reached[] = $request->method->name;
                $request->context->responseTrailers->set('x-recorded', '1');

                return $next($request);
            }
        };
        $server = new Server($factory, $factory, [$auth, $recorder], maxRequestBytes: $maxRequestBytes);
        $server->register(EchoInterface::class, new EchoService());
        $this->http = new InProcessHttpClient($server);

        return new EchoClient(new Client(
            $this->http,
            $factory,
            $factory,
            'http://in-process',
            codec: $codec,
            useGet: $useGet,
            timeoutMs: $timeoutMs,
            maxResponseBytes: $maxResponseBytes,
        ));
    }

    private static function authorized(string $token = 'secret'): Context
    {
        return new Context(new Headers(['x-token' => $token, 'x-custom' => 'v']));
    }

    private static function failure(\Closure $call): ConnectException
    {
        try {
            $call();
        } catch (ConnectException $e) {
            return $e;
        }
        self::fail('expected a ConnectException');
    }

    #[Test]
    #[DataProvider('transports')]
    public function echoRoundTrip(Codec $codec, bool $useGet): void
    {
        $context = self::authorized();
        $response = $this->client($codec, $useGet)->echo(new EchoRequest(['text' => 'hello']), $context);

        self::assertSame('HELLO', $response->getText());
        self::assertSame('v', iterator_to_array($response->getHeaders())['x-custom'] ?? null);
        self::assertSame('1', $context->responseHeaders->get('x-echoed'));
        self::assertSame('t', $context->responseTrailers->get('x-trailer'));
        self::assertSame('POST', $this->http->lastRequest?->getMethod());
    }

    #[Test]
    #[DataProvider('transports')]
    public function peekUsesGetWhenEnabled(Codec $codec, bool $useGet): void
    {
        $response = $this->client($codec, $useGet)->peek(new EchoRequest(['text' => 'look']), self::authorized());

        self::assertSame('look', $response->getText());
        self::assertSame($useGet ? 'GET' : 'POST', $this->http->lastRequest?->getMethod());
    }

    #[Test]
    #[DataProvider('transports')]
    public function errorWithDetailRoundTrip(Codec $codec, bool $useGet): void
    {
        $client = $this->client($codec, $useGet);
        $e = self::failure(static fn() => $client->fail(
            new FailRequest(['code' => 'failed_precondition', 'message' => 'not yet', 'with_detail' => true]),
            self::authorized(),
        ));

        self::assertSame(Code::FailedPrecondition, $e->code());
        self::assertSame('not yet', $e->getMessage());
        self::assertCount(1, $e->details());
        $detail = $e->details()[0]->unpack(Struct::class);
        self::assertInstanceOf(Struct::class, $detail);
        $reason = $detail->getFields()['reason'];
        self::assertInstanceOf(Value::class, $reason);
        self::assertSame('detail', $reason->getStringValue());
        self::assertSame('1', $e->metadata()->get('x-recorded'));
    }

    #[Test]
    public function downstreamCallWithANewContextLeaksNothing(): void
    {
        $factory = new Psr17Factory();
        $downstreamServer = new Server($factory, $factory);
        $downstreamServer->register(EchoInterface::class, new EchoService());
        $downstreamHttp = new InProcessHttpClient($downstreamServer);
        $downstream = new EchoClient(new Client($downstreamHttp, $factory, $factory, 'http://downstream'));
        $gateway = new class ($downstream) implements EchoInterface {
            public function __construct(private readonly EchoClient $downstream) {}

            #[\Override]
            public function echo(EchoRequest $request, Context $context = new Context()): EchoResponse
            {
                $forwarded = new Context(
                    new Headers(['x-token' => $context->requestHeaders->get('x-token') ?? '']),
                    timeoutMs: $context->remainingMs(),
                );
                $response = $this->downstream->echo($request, $forwarded);
                $context->responseHeaders->set('x-downstream-timeout', $forwarded->responseHeaders->get('x-timeout') ?? '');

                return $response;
            }

            #[\Override]
            public function peek(EchoRequest $request, Context $context = new Context()): EchoResponse
            {
                return new EchoResponse();
            }

            #[\Override]
            public function fail(FailRequest $request, Context $context = new Context()): PBEmpty
            {
                return new PBEmpty();
            }
        };
        $server = new Server($factory, $factory);
        $server->register(EchoInterface::class, $gateway);
        $context = new Context(new Headers(['x-token' => 'secret', 'x-custom' => 'v']), timeoutMs: 5000);

        $response = new EchoClient(new Client(new InProcessHttpClient($server), $factory, $factory, 'http://gateway'))
            ->echo(new EchoRequest(['text' => 'hi']), $context);

        self::assertSame(['x-token' => 'secret'], iterator_to_array($response->getHeaders()));
        self::assertFalse($downstreamHttp->lastRequest?->hasHeader('x-custom'));
        self::assertFalse($context->responseHeaders->has('x-echoed'));
        self::assertFalse($context->responseTrailers->has('x-trailer'));
        $timeout = (int) $context->responseHeaders->get('x-downstream-timeout');
        self::assertGreaterThan(0, $timeout);
        self::assertLessThanOrEqual(5000, $timeout);
    }

    #[Test]
    #[DataProvider('transports')]
    public function unauthenticatedBeforeDecode(Codec $codec, bool $useGet): void
    {
        $client = $this->client($codec, $useGet);
        $e = self::failure(static fn() => $client->echo(new EchoRequest(['text' => 'hi']), self::authorized('wrong')));

        self::assertSame(Code::Unauthenticated, $e->code());
        self::assertSame([], $this->reached->getArrayCopy());
    }

    #[Test]
    #[DataProvider('transports')]
    public function unexpectedServerExceptionIsUnknownForTheClient(Codec $codec, bool $useGet): void
    {
        $client = $this->client($codec, $useGet);
        $e = self::failure(static fn() => $client->fail(new FailRequest(['code' => 'boom']), self::authorized()));

        self::assertSame(Code::Unknown, $e->code());
        self::assertSame('', $e->getMessage());
    }

    #[Test]
    #[DataProvider('transports')]
    public function timeoutHeaderReachesTheServer(Codec $codec, bool $useGet): void
    {
        $context = self::authorized();
        $this->client($codec, $useGet, timeoutMs: 250)->echo(new EchoRequest(['text' => 'hi']), $context);

        self::assertSame('250', $context->responseHeaders->get('x-timeout'));
    }

    #[Test]
    #[DataProvider('transports')]
    public function largeResponseIsGzipped(Codec $codec, bool $useGet): void
    {
        $text = str_repeat('a', 5000);
        $response = $this->client($codec, $useGet)->peek(new EchoRequest(['text' => $text]), self::authorized());

        self::assertSame('gzip', $this->http->lastResponse?->getHeaderLine('content-encoding'));
        self::assertSame($text, $response->getText());
    }

    #[Test]
    #[DataProvider('transports')]
    public function clientLimitRejectsLargeResponse(Codec $codec, bool $useGet): void
    {
        $client = $this->client($codec, $useGet, maxResponseBytes: 64);
        $e = self::failure(static fn() => $client->peek(new EchoRequest(['text' => str_repeat('a', 200)]), self::authorized()));

        self::assertSame(Code::ResourceExhausted, $e->code());
    }

    #[Test]
    #[DataProvider('transports')]
    public function serverLimitRejectsLargeRequest(Codec $codec, bool $useGet): void
    {
        $client = $this->client($codec, $useGet, maxRequestBytes: 64);
        $e = self::failure(static fn() => $client->peek(new EchoRequest(['text' => str_repeat('a', 200)]), self::authorized()));

        self::assertSame(Code::ResourceExhausted, $e->code());
        self::assertSame(['Peek'], $this->reached->getArrayCopy());
    }
}
