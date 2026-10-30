# Using connect-php with Symfony

The library has no Symfony bundle. `Server` is a PSR-15 request handler and `Client` sits on any PSR-18 client, so a Symfony application needs a controller, a few service definitions and, for the client, an HTTP client it already has.

The examples use a `Stock` service in the `acme.stock` proto package, generated into the `Acme\Stock` namespace as `StockInterface` and `StockClient`. They need these packages:

```sh
composer require ftshp/connect nyholm/psr7 symfony/psr-http-message-bridge symfony/http-client
```

## Serving RPCs from a controller

The controller converts the HttpFoundation request to PSR-7 with `PsrHttpFactory`, passes it to `Server`, and converts the PSR-7 response back with `HttpFoundationFactory`. Connect procedures live at `/<package>.<Service>/<Method>`, and the route matches that shape for GET and POST.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Ftshp\Connect\Server;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ConnectController
{
    public function __construct(
        private Server $server,
        private PsrHttpFactory $psrHttpFactory,
        private HttpFoundationFactory $httpFoundationFactory,
    ) {}

    #[Route(
        '/{service}/{method}',
        requirements: ['service' => '[A-Za-z_][\w]*(\.[A-Za-z_][\w]*)+', 'method' => '[A-Za-z_]\w*'],
        methods: ['GET', 'POST'],
        priority: -10,
    )]
    public function __invoke(Request $request): Response
    {
        $response = $this->server->handle($this->psrHttpFactory->createRequest($request));

        return $this->httpFoundationFactory->createResponse($response);
    }
}
```

The route has a low priority so that the application's own routes win. The dot in the service name keeps it from matching ordinary two-segment paths. To serve Connect under a path such as `/rpc`, put the prefix in the route and pass `prefix: '/rpc'` to both `Server` and `Client`.

`Server` reads the body from the PSR-7 stream and enforces `maxRequestBytes` itself, but PHP's `post_max_size` still applies in front of it.

## Wiring the services

The definitions below go in `config/services.php`. They load the application's classes as usual, register the PSR-17 factory once, build the bridge factories from it, and create one `Server` for every service the application implements. Each `register` call adds a service, and the implementation is any class that implements the generated interface.

```php
<?php

declare(strict_types=1);

use Acme\Stock\StockClient;
use Acme\Stock\StockInterface;
use App\Connect\ErrorLog;
use App\Connect\RetryReads;
use App\Connect\TokenAuth;
use App\Stock\StockService;
use Ftshp\Connect\Client;
use Ftshp\Connect\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\Psr18Client;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->load('App\\', '../src/');

    $services->set(Psr17Factory::class);
    $services->set(PsrHttpFactory::class)->args([
        service(Psr17Factory::class),
        service(Psr17Factory::class),
        service(Psr17Factory::class),
        service(Psr17Factory::class),
    ]);
    $services->set(HttpFoundationFactory::class);

    $services->set(TokenAuth::class)->args([env('CONNECT_TOKEN')]);

    $services->set(Server::class)
        ->args([
            service(Psr17Factory::class),
            service(Psr17Factory::class),
            [service(TokenAuth::class), service(ErrorLog::class)],
        ])
        ->call('register', [StockInterface::class, service(StockService::class)]);

    $services->set('app.stock.http_client', Psr18Client::class)
        ->args([service('http_client'), service(Psr17Factory::class), service(Psr17Factory::class)]);

    $services->set('app.stock.connect_client', Client::class)
        ->args([
            service('app.stock.http_client'),
            service(Psr17Factory::class),
            service(Psr17Factory::class),
            env('STOCK_URL'),
        ])
        ->arg('$interceptors', [service(RetryReads::class)])
        ->arg('$timeoutMs', 2000);

    $services->set(StockClient::class)->args([service('app.stock.connect_client')]);
};
```

A class that needs the stock service asks for `StockClient`, or for `StockInterface` if you alias the interface to the client. Calls go out through Symfony's HttpClient, so the `framework.http_client` configuration applies to them.

When the application already uses HttplugBundle, pass one of its clients instead of the `Psr18Client`, for example `service('httplug.client.stock')`. Its plugins for authentication headers, logging and retries keep working because they sit inside the PSR-18 client.

## Authentication

This server interceptor checks a shared bearer token with `hash_equals`. It does not call `$request->message()`, so a request with a bad token is rejected before its body is decoded.

```php
<?php

declare(strict_types=1);

namespace App\Connect;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;

final readonly class TokenAuth implements Interceptor
{
    public function __construct(private string $token) {}

    #[\Override]
    public function intercept(UnaryRequest $request, \Closure $next): Message
    {
        $header = $request->context->requestHeaders->get('authorization') ?? '';
        if (!hash_equals('Bearer ' . $this->token, $header)) {
            throw new ConnectException(Code::Unauthenticated, 'invalid token');
        }

        return $next($request);
    }
}
```

The client sends the token in the call's `Context`:

```php
$context = new Context(new Headers(['authorization' => 'Bearer ' . $token]));
$stock->availabilityGet($request, $context);
```

With HttplugBundle, the bundle's `authentication` plugin of type `bearer` does the same for every call.

## Logging errors

This interceptor goes last in the server's list, so it sees the exception from the implementation before `Server` turns it into a response. It logs client errors such as `not_found` at info level and server errors at error level, then rethrows.

```php
<?php

declare(strict_types=1);

namespace App\Connect;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final readonly class ErrorLog implements Interceptor
{
    public function __construct(private LoggerInterface $logger) {}

    #[\Override]
    public function intercept(UnaryRequest $request, \Closure $next): Message
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            $code = ConnectException::fromThrowable($e)->code();
            $level = match ($code) {
                Code::Unknown, Code::Internal, Code::DataLoss, Code::Unavailable => LogLevel::ERROR,
                Code::DeadlineExceeded, Code::ResourceExhausted => LogLevel::WARNING,
                default => LogLevel::INFO,
            };
            $this->logger->log($level, 'RPC {procedure} failed with {code}', [
                'procedure' => $request->method->procedure(),
                'code' => $code->value,
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
```

An exception that is not a `ConnectException` is logged with its original message and class, while the client only receives `unknown`.

## Retrying reads

On the client, `$next` sends the request each time it is called. This interceptor repeats calls to methods marked `option idempotency_level = NO_SIDE_EFFECTS` when they fail with `unavailable`. The client reports network errors as `unavailable`, and also 429, 502, 503 and 504 responses without a Connect error body, such as those from a proxy. Other methods and other codes pass through untouched.

```php
<?php

declare(strict_types=1);

namespace App\Connect;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Idempotency;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;

final readonly class RetryReads implements Interceptor
{
    public function __construct(
        private int $attempts = 3,
        private int $delayMs = 100,
    ) {}

    #[\Override]
    public function intercept(UnaryRequest $request, \Closure $next): Message
    {
        if (!$request->isClient || $request->method->idempotency !== Idempotency::NoSideEffects) {
            return $next($request);
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return $next($request);
            } catch (ConnectException $e) {
                if ($e->code() !== Code::Unavailable || $attempt >= $this->attempts) {
                    throw $e;
                }
                usleep($this->delayMs * 1000 * $attempt);
            }
        }
    }
}
```

Each attempt gets the full timeout from the `Context`, so the worst case is about `attempts` times the timeout plus the delays.

## Applying the timeout to the transport

PSR-18 has no per-request timeout. The client sends the call's timeout in the `Connect-Timeout-Ms` header so the server knows the deadline, but the HTTP client keeps waiting for as long as its own configuration allows. This httplug plugin reads the header and sends the request through a copy of Symfony's `HttplugClient` with that timeout. A request without the header is passed on unchanged.

```php
<?php

declare(strict_types=1);

namespace App\Connect;

use Http\Client\Common\Plugin;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpClient\HttplugClient;

final readonly class ConnectTimeoutPlugin implements Plugin
{
    public function __construct(private HttplugClient $transport) {}

    #[\Override]
    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $timeoutMs = (int) $request->getHeaderLine('Connect-Timeout-Ms');
        if ($timeoutMs <= 0) {
            return $next($request);
        }
        $seconds = $timeoutMs / 1000;

        return $this->transport
            ->withOptions(['timeout' => $seconds, 'max_duration' => $seconds])
            ->sendAsyncRequest($request);
    }
}
```

The plugin sends the request itself instead of calling `$next`, so it has to be the last plugin in the list, and `$transport` should be the client the plugin chain would otherwise reach. With HttplugBundle that is the client the `httplug.factory.symfony` factory builds:

```php
$services->set(ConnectTimeoutPlugin::class)->args([service('httplug.client.stock.client')]);
```

```yaml
httplug:
    clients:
        stock:
            factory: 'httplug.factory.symfony'
            config:
                timeout: 5
            plugins:
                - 'httplug.plugin.logger'
                - 'App\Connect\ConnectTimeoutPlugin'
```

When the transport times out, Symfony throws a network exception and `Client` turns it into `deadline_exceeded`, because by then the call's deadline has passed.
