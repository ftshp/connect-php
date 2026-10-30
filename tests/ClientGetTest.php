<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Client;
use Ftshp\Connect\Codec\Codec;
use Ftshp\Connect\Codec\JsonCodec;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\Compression\Compression;
use Ftshp\Connect\Compression\Gzip;
use Ftshp\Connect\Context;
use Ftshp\Connect\Headers;
use Ftshp\Connect\Internal\GetQuery;
use Ftshp\Connect\MethodDescriptor;
use Ftshp\Connect\ServiceDescriptor;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\Tests\Fixture\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientGetTest extends TestCase
{
    private FakeHttpClient $http;

    private function client(bool $useGet = true, Codec $codec = new ProtoCodec(), ?Compression $compression = null): Client
    {
        $factory = new Psr17Factory();
        $this->http = new FakeHttpClient(static fn(RequestInterface $request): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'application/' . $codec->name()],
            $codec->encode(new EchoResponse(['text' => 'HI'])),
        ));

        return new Client($this->http, $factory, $factory, 'https://api.test', codec: $codec, compression: $compression, useGet: $useGet);
    }

    /**
     * @return MethodDescriptor<EchoRequest, EchoResponse>
     */
    private static function method(string $name): MethodDescriptor
    {
        /** @var MethodDescriptor<EchoRequest, EchoResponse> */
        return ServiceDescriptor::fromInterface(EchoInterface::class)->methods[$name];
    }

    private function call(Client $client, string $method = 'Peek', Context $context = new Context()): EchoResponse
    {
        return $client->unary(self::method($method), new EchoRequest(['text' => 'hi']), $context);
    }

    private function sentRequest(): RequestInterface
    {
        self::assertNotNull($this->http->lastRequest);

        return $this->http->lastRequest;
    }

    #[Test]
    public function getIsUsedForNoSideEffectsWhenEnabled(): void
    {
        $response = $this->call($this->client(), context: new Context(new Headers(['x-token' => 'secret', 'content-type' => 'text/plain'])));

        self::assertSame('HI', $response->getText());
        $request = $this->sentRequest();
        self::assertSame('GET', $request->getMethod());
        $query = GetQuery::encode('proto', new EchoRequest(['text' => 'hi'])->serializeToString(), null);
        self::assertSame('https://api.test/ftshp.connect.test.Echo/Peek?' . $query, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        $headers = array_change_key_case($request->getHeaders());
        unset($headers['host']);
        ksort($headers);
        self::assertSame([
            'accept-encoding' => ['gzip'],
            'connect-protocol-version' => ['1'],
            'x-token' => ['secret'],
        ], $headers);
    }

    #[Test]
    public function postIsUsedForOtherMethodsEvenWhenGetIsEnabled(): void
    {
        $this->call($this->client(), 'Echo');

        self::assertSame('POST', $this->sentRequest()->getMethod());
        self::assertSame('https://api.test/ftshp.connect.test.Echo/Echo', (string) $this->sentRequest()->getUri());
    }

    #[Test]
    public function getIsNotUsedByDefault(): void
    {
        $factory = new Psr17Factory();
        $http = new FakeHttpClient(static fn(RequestInterface $request): ResponseInterface => new Response(200, ['Content-Type' => 'application/proto']));

        new Client($http, $factory, $factory, 'https://api.test')->unary(self::method('Peek'), new EchoRequest(['text' => 'hi']));

        self::assertSame('POST', $http->lastRequest?->getMethod());
    }

    #[Test]
    public function getWithJsonCodec(): void
    {
        $response = $this->call($this->client(codec: new JsonCodec()));

        self::assertSame('HI', $response->getText());
        self::assertSame('connect=v1&encoding=json&message=%7B%22text%22%3A%22hi%22%7D', $this->sentRequest()->getUri()->getQuery());
        self::assertFalse($this->sentRequest()->hasHeader('Content-Type'));
    }

    #[Test]
    public function getWithCompression(): void
    {
        $this->call($this->client(compression: new Gzip()));

        $request = $this->sentRequest();
        self::assertStringContainsString('&compression=gzip&', $request->getUri()->getQuery());
        self::assertFalse($request->hasHeader('Content-Encoding'));
        parse_str($request->getUri()->getQuery(), $params);
        $parsed = GetQuery::parse($params);
        self::assertSame('gzip', $parsed['compression']);
        $sent = new EchoRequest();
        $sent->mergeFromString(new Gzip()->decompress($parsed['message'], 0));
        self::assertSame('hi', $sent->getText());
    }
}
