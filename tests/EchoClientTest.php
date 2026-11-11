<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Client;
use Ftshp\Connect\Context;
use Ftshp\Connect\Headers;
use Ftshp\Connect\Test\EchoClient;
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

final class EchoClientTest extends TestCase
{
    private FakeHttpClient $http;

    private function client(): EchoClient
    {
        $factory = new Psr17Factory();
        $this->http = new FakeHttpClient(static fn(RequestInterface $request): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'application/proto'],
            new EchoResponse(['text' => 'HI'])->serializeToString(),
        ));

        return new EchoClient(new Client($this->http, $factory, $factory, 'https://api.test'));
    }

    #[Test]
    public function generatedClientReturnsTypedResponse(): void
    {
        $response = $this->client()->echo(new EchoRequest(['text' => 'hi']));

        self::assertSame('HI', $response->getText());
        self::assertSame('https://api.test/ftshp.connect.test.Echo/Echo', (string) $this->http->lastRequest?->getUri());
    }

    #[Test]
    public function generatedClientPassesContext(): void
    {
        $this->client()->peek(new EchoRequest(['text' => 'hi']), new Context(new Headers(['x-token' => 'secret'])));

        self::assertSame('secret', $this->http->lastRequest?->getHeaderLine('x-token'));
        self::assertSame('https://api.test/ftshp.connect.test.Echo/Peek', (string) $this->http->lastRequest->getUri());
    }

    #[Test]
    public function callThroughTheInterfaceNeedsNoContext(): void
    {
        $client = $this->client();
        $echo = static fn(EchoInterface $service): EchoResponse => $service->echo(new EchoRequest(['text' => 'hi']));

        self::assertSame('HI', $echo($client)->getText());
    }

    #[Test]
    public function interfaceContextDefaultsToANewContextLikeTheClient(): void
    {
        foreach (['echo', 'peek', 'fail'] as $method) {
            $declared = new \ReflectionMethod(EchoInterface::class, $method)->getParameters()[1];
            $implemented = new \ReflectionMethod(EchoClient::class, $method)->getParameters()[1];

            self::assertTrue($declared->isOptional(), $method);
            self::assertInstanceOf(Context::class, $declared->getDefaultValue(), $method);
            self::assertSame((string) $declared, (string) $implemented, $method);
        }
    }

    #[Test]
    public function generatedClientHasNoStreamingMethod(): void
    {
        self::assertFalse(new \ReflectionClass(EchoClient::class)->hasMethod('watch'));
    }
}
