<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\Internal\GetQuery;
use Ftshp\Connect\Server;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\Tests\Fixture\EchoService;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ServerGetTest extends TestCase
{
    private const string PEEK = '/ftshp.connect.test.Echo/Peek';

    /**
     * @param list<Interceptor> $interceptors
     */
    private function server(
        array $interceptors = [],
        int $compressMinBytes = 1024,
        int $maxRequestBytes = 4194304,
        bool $requireProtocolHeader = false,
    ): Server {
        $factory = new Psr17Factory();
        $server = new Server(
            $factory,
            $factory,
            interceptors: $interceptors,
            compressMinBytes: $compressMinBytes,
            maxRequestBytes: $maxRequestBytes,
            requireProtocolHeader: $requireProtocolHeader,
        );
        $server->register(EchoInterface::class, new EchoService());

        return $server;
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, string $query, array $headers = [], ?Server $server = null): ResponseInterface
    {
        parse_str($query, $params);
        $request = new ServerRequest('GET', $path . '?' . $query, $headers)->withQueryParams($params);

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
    public function getPeekProto(): void
    {
        $response = $this->get(self::PEEK, GetQuery::encode('proto', $this->proto('hello'), null));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/proto', $response->getHeaderLine('content-type'));
        self::assertSame('hello', $this->echoResponse((string) $response->getBody())->getText());
    }

    #[Test]
    public function getPeekJson(): void
    {
        $response = $this->get(self::PEEK, GetQuery::encode('json', '{"text":"a+b/c"}', null));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('a+b/c', $this->json($response)['text']);
    }

    #[Test]
    public function getWithParametersInAnyOrderWithoutConnect(): void
    {
        $response = $this->get(self::PEEK, 'message=%7B%22text%22%3A%22x%22%7D&extra=1&encoding=json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('x', $this->json($response)['text']);
    }

    #[Test]
    public function getOnNonIdempotentMethodIs405(): void
    {
        $response = $this->get('/ftshp.connect.test.Echo/Echo', GetQuery::encode('proto', $this->proto('a'), null));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('allow'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function getMissingMessageIsInvalidArgument(): void
    {
        $response = $this->get(self::PEEK, 'connect=v1&encoding=json');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('invalid_argument', $this->json($response)['code']);
    }

    #[Test]
    public function getUnknownEncodingIs415(): void
    {
        $response = $this->get(self::PEEK, 'connect=v1&encoding=xml&message=x');

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('application/json, application/proto', $response->getHeaderLine('accept-post'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function getUnknownCompressionIsUnimplemented(): void
    {
        $response = $this->get(self::PEEK, GetQuery::encode('proto', $this->proto('a'), 'br'));

        self::assertSame(501, $response->getStatusCode());
        self::assertSame('gzip', $response->getHeaderLine('accept-encoding'));
        self::assertSame(
            ['code' => 'unimplemented', 'message' => 'unknown compression "br": supported encodings are gzip'],
            $this->json($response),
        );
    }

    #[Test]
    public function getWithGzipPayload(): void
    {
        $response = $this->get(self::PEEK, GetQuery::encode('proto', (string) gzencode($this->proto('zipped')), 'gzip'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('zipped', $this->echoResponse((string) $response->getBody())->getText());
    }

    #[Test]
    public function getResponseIsCompressedWhenAccepted(): void
    {
        $response = $this->get(
            self::PEEK,
            GetQuery::encode('proto', $this->proto('hello'), null),
            ['Accept-Encoding' => 'gzip'],
            $this->server(compressMinBytes: 1),
        );

        self::assertSame('gzip', $response->getHeaderLine('content-encoding'));
        self::assertSame('hello', $this->echoResponse((string) gzdecode((string) $response->getBody()))->getText());
    }

    #[Test]
    public function getOverLimitIsResourceExhausted(): void
    {
        $server = $this->server(maxRequestBytes: 100);

        $raw = $this->get(self::PEEK, GetQuery::encode('proto', $this->proto(str_repeat('a', 200)), null), server: $server);
        $inflated = $this->get(self::PEEK, GetQuery::encode('proto', (string) gzencode($this->proto(str_repeat('a', 1000))), 'gzip'), server: $server);

        self::assertSame(429, $raw->getStatusCode());
        self::assertSame(['code' => 'resource_exhausted', 'message' => 'message size exceeds limit'], $this->json($raw));
        self::assertSame(429, $inflated->getStatusCode());
    }

    #[Test]
    public function getRunsInterceptors(): void
    {
        $seen = new \ArrayObject();
        $interceptor = new class ($seen) implements Interceptor {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(private readonly \ArrayObject $seen) {}

            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $this->seen->append($request->method->name);

                return $next($request);
            }
        };

        $response = $this->get(self::PEEK, GetQuery::encode('proto', $this->proto('a'), null), server: $this->server([$interceptor]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Peek'], $seen->getArrayCopy());
    }

    #[Test]
    public function getInterceptorCanRejectBeforeDecoding(): void
    {
        $reject = new class implements Interceptor {
            #[\Override]
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                throw new ConnectException(Code::Unauthenticated, 'no token');
            }
        };

        $response = $this->get(self::PEEK, 'encoding=json&message=%7Bnot%20json', server: $this->server([$reject]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function getWithRequiredProtocolUsesConnectParameter(): void
    {
        $server = $this->server(requireProtocolHeader: true);

        self::assertSame(200, $this->get(self::PEEK, GetQuery::encode('json', '{}', null), server: $server)->getStatusCode());
        self::assertSame(400, $this->get(self::PEEK, 'encoding=json&message=%7B%7D', server: $server)->getStatusCode());
    }

    #[Test]
    public function getWithWrongConnectParameterIsRejectedWhenNotRequired(): void
    {
        $response = $this->get(self::PEEK, 'connect=v2&encoding=json&message=%7B%7D');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['code' => 'invalid_argument', 'message' => 'connect must be "v1": got "v2"'], $this->json($response));
        self::assertSame(200, $this->get(self::PEEK, 'encoding=json&message=%7B%7D')->getStatusCode());
    }
}
