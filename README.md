# ftshp/connect

[![CI](https://github.com/ftshp/connect-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ftshp/connect-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/ftshp/connect)](https://packagist.org/packages/ftshp/connect)
[![PHP](https://img.shields.io/packagist/dependency-v/ftshp/connect/php)](https://packagist.org/packages/ftshp/connect)

A PHP implementation of the Connect protocol for unary RPC, with a PSR-18 client and a PSR-15 server.

This is an unofficial, community implementation of the Connect protocol. It is not affiliated with Buf or the Connect project. It works with any other Connect client or server, connect-go and curl included, and needs PHP 8.5 or newer.

| Feature | Client | Server |
|---|---|---|
| Connect protocol, unary RPCs | yes | yes |
| HTTP/1.1 | yes | yes |
| `proto` and `json` codecs | yes | yes |
| gzip compression | yes | yes |
| GET for `NO_SIDE_EFFECTS` methods | opt-in | yes |
| Timeouts (`Connect-Timeout-Ms`) | sent | parsed, with an optional cap |
| Headers, trailers and error details | yes | yes |
| Interceptors | yes | yes |
| Streaming RPCs | no | no |
| gRPC and gRPC-Web | no | no |

Streaming is out of scope because PHP-FPM, PSR-18 and PSR-15 are built around one request and one complete response. The code generator skips streaming RPCs with a warning.

The package is at 0.x, so the API may change between minor versions. Classes in the `Ftshp\Connect\Internal` namespace and anything marked `@internal` are not part of the public API and may change in any release.

## A small example

A service definition in `proto/greet/v1/greet.proto`:

```proto
syntax = "proto3";

package greet.v1;

message GreetRequest {
  string name = 1;
}

message GreetResponse {
  string greeting = 1;
}

service GreetService {
  // Greet says hello to the caller.
  rpc Greet(GreetRequest) returns (GreetResponse) {
    option idempotency_level = NO_SIDE_EFFECTS;
  }
}
```

A `buf.gen.yaml` that runs the protobuf PHP plugin for the messages and `protoc-gen-connect-php` for the service:

```yaml
version: v2
plugins:
  - remote: buf.build/protocolbuffers/php:v36.2
    out: gen
  - local: protoc-gen-connect-php
    out: gen
```

Generate the code and install the dependencies. The example uses nyholm/psr7 for PSR-7 and PSR-17 and Guzzle as the PSR-18 client. Other implementations of those standards work the same way.

```sh
buf generate proto
composer require ftshp/connect nyholm/psr7 nyholm/psr7-server guzzlehttp/guzzle
```

Add the generated directories to the autoloader in `composer.json` and run `composer dump-autoload`:

```json
"autoload": {
    "psr-4": {
        "Greet\\V1\\": "gen/Greet/V1/",
        "GPBMetadata\\": "gen/GPBMetadata/"
    }
}
```

The server, `server.php`, implements the generated interface and hands each request to `Server`:

```php
<?php

declare(strict_types=1);

use Ftshp\Connect\Context;
use Ftshp\Connect\Server;
use Greet\V1\GreetRequest;
use Greet\V1\GreetResponse;
use Greet\V1\GreetServiceInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/vendor/autoload.php';

final class Greeter implements GreetServiceInterface
{
    public function greet(GreetRequest $request, Context $context = new Context()): GreetResponse
    {
        return new GreetResponse(['greeting' => 'Hello, ' . $request->getName() . '!']);
    }
}

$factory = new Psr17Factory();
$server = new Server($factory, $factory);
$server->register(GreetServiceInterface::class, new Greeter());

$request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();
$response = $server->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
```

Start it with PHP's built-in web server:

```sh
php -S localhost:8080 server.php
```

The client, `client.php`, uses the generated client class:

```php
<?php

declare(strict_types=1);

use Ftshp\Connect\Client;
use Greet\V1\GreetRequest;
use Greet\V1\GreetServiceClient;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory();
$greeter = new GreetServiceClient(new Client(new GuzzleHttp\Client(), $factory, $factory, 'http://localhost:8080'));

echo $greeter->greet(new GreetRequest(['name' => 'Jane']))->getGreeting(), "\n";
```

```console
$ php client.php
Hello, Jane!
```

The server speaks plain Connect, so curl can call it with JSON:

```console
$ curl -H 'Content-Type: application/json' -d '{"name": "Jane"}' http://localhost:8080/greet.v1.GreetService/Greet
{"greeting":"Hello, Jane!"}
```

## Installation

```sh
composer require ftshp/connect
```

The library needs PHP `^8.5`, `ext-zlib`, `google/protobuf` and the PSR HTTP interfaces. It works with the protobuf C extension and with the pure PHP runtime. You provide the PSR-7 and PSR-17 implementation and, for the client, a PSR-18 HTTP client.

The code generator is a protoc plugin written in Go. Download a binary for Linux, macOS or Windows from the [GitHub releases](https://github.com/ftshp/connect-php/releases) and put it on your `PATH`, or build it with Go:

```sh
go install github.com/ftshp/connect-php/cmd/protoc-gen-connect-php@latest
```

With buf, add it as a local plugin next to the protobuf PHP plugin and write both to the same directory:

```yaml
version: v2
plugins:
  - remote: buf.build/protocolbuffers/php:v36.2
    out: gen
  - local: protoc-gen-connect-php
    out: gen
```

With protoc, pass `--php_out=gen --connect-php_out=gen`. The plugin takes no options.

## Generated code

For each service the plugin writes an interface and a client into the namespace protoc uses for the messages, so the `php_namespace` and `php_class_prefix` options apply to both. This is the output for the example above, without the file header and imports:

```php
#[Service('greet.v1.GreetService')]
interface GreetServiceInterface
{
    /**
     * Greet says hello to the caller.
     *
     * @throws ConnectException
     */
    #[Procedure('Greet', Idempotency::NoSideEffects)]
    public function greet(GreetRequest $request, Context $context = new Context()): GreetResponse;
}
```

```php
final class GreetServiceClient implements GreetServiceInterface
{
    public function __construct(private readonly Client $client) {}

    public function greet(GreetRequest $request, Context $context = new Context()): GreetResponse
    {
        return $this->client->unary(self::greetMethod(), $request, $context);
    }

    /** @return MethodDescriptor<GreetRequest, GreetResponse> */
    private static function greetMethod(): MethodDescriptor
    {
        static $method = null;

        return $method ??= new MethodDescriptor('greet.v1.GreetService', 'Greet', 'greet', GreetRequest::class, GreetResponse::class, Idempotency::NoSideEffects);
    }
}
```

A server implementation is any class that implements the interface. `Server::register()` reads the `#[Service]` and `#[Procedure]` attributes, so there is no generated server class, and one `Server` holds every service of an application. Method names are lowerCamel, and deprecated RPCs get `@deprecated`. The generated code passes PHPStan at `level: max`.

Every method takes the request first and an optional `Context` second, as in connect-es. The interface and the client both default `$context` to `new Context()`, so code that types against the interface, a test double included, can call `$greeter->greet($request)`. An implementation has to declare the same default, because PHP rejects a class that makes an optional parameter of its interface required. The server always passes a `Context` to the handler. The default is a new `Context` and not `null` because handlers and interceptors write response headers and trailers into it.

`Context` carries the request headers and the timeout, and after the call it holds the response headers and trailers:

```php
$context = new Context(new Headers(['x-request-id' => 'f00d']), timeoutMs: 2000);
$response = $greeter->greet(new GreetRequest(['name' => 'Jane']), $context);
$etag = $context->responseHeaders->get('etag');
```

On the server the handler gets the same class. It reads what the client sent and sets `$context->responseHeaders` and `$context->responseTrailers`. `$context->remainingMs()` returns the time left before the deadline from `Connect-Timeout-Ms`, or `null` when the client sent none. `$context->method` is the `MethodDescriptor` of the call once the client or the server has started it. Reading it earlier throws a `\LogicException`.

The deadline is set when the call starts, and again from that moment when an interceptor changes `timeoutMs`. On the client every attempt, including each retry an interceptor makes, sends the time left in `Connect-Timeout-Ms`, and an attempt that starts after the deadline fails with `deadline_exceeded` without sending a request. A timeout longer than 10 digits is not sent at all, as in connect-go. On the server a deadline that has passed before the handler runs, after slow interceptors or with `Connect-Timeout-Ms: 0`, ends the call with `deadline_exceeded` and the handler is not called. A handler that is already running is not interrupted; it can check `remainingMs()` and give up early.

`Headers` names are case-insensitive and stored in lowercase. A name that ends in `-bin` holds raw bytes: `get()`, `all()`, `set()`, `add()` and `foreach` work with the bytes, and the library sends them as unpadded base64. `toArray()` returns the wire form, with those values in base64, ready for PSR-7 `withHeader()`, and `Headers::fromPsr()` reads the same form from PSR-7 `getHeaders()` and decodes it. To log or copy every header with raw values, iterate:

```php
foreach ($context->requestHeaders as $name => $values) {
    // $values is a list of strings, raw bytes for -bin names
}
```

A `Context` belongs to one call. A handler that calls another service builds a new `Context` for that call, copies the headers it means to forward and passes on the time that is left:

```php
$downstream = new Context(
    new Headers(['x-request-id' => $context->requestHeaders->get('x-request-id') ?? '']),
    timeoutMs: $context->remainingMs(),
);
$response = $greeter->greet(new GreetRequest(['name' => 'Jane']), $downstream);
```

The client never sends `host`, `content-length`, `transfer-encoding`, `connection`, `te` or `content-encoding` from a `Context`. The server drops `content-encoding`, `content-length` and `transfer-encoding` from the response headers a handler sets, because it encodes the body itself.

`Client` takes options as named arguments: `codec: new JsonCodec()`, `compression: new Gzip()` to compress requests, `acceptCompression` for the encodings accepted on responses, `useGet: true` to send `NO_SIDE_EFFECTS` calls as GET, `timeoutMs`, `maxResponseBytes`, `interceptors` and a path `prefix`. `Server` takes `interceptors`, `compression`, `compressMinBytes`, `maxRequestBytes`, `maxTimeoutMs`, `requireProtocolHeader` and `prefix`. With `requireProtocolHeader: true` the server rejects a POST without `Connect-Protocol-Version: 1` and a GET without `connect=v1`. A different value is rejected with `invalid_argument` whatever the option says. Both sides limit messages to 4 MiB by default, counting the raw and the decompressed body.

`codec` takes any `Codec` implementation, and both sides take any `Compression`. `Codec::decode()` throws a `ConnectException` with `invalid_argument` for data it cannot parse. `Compression::decompress()` stops once the output passes the `$maxBytes` it is given, throws `resource_exhausted` when that happens and `invalid_argument` for corrupt input. Any other exception from an implementation becomes `unknown` with an empty message on the server and leaves `Client::unary()` unchanged.

## Errors

Every failed RPC ends in a `ConnectException` with a `Code`. Invalid metadata you set yourself throws `\InvalidArgumentException` instead: a header name outside the allowed characters or with the `connect-` prefix, or a text value with a control character. A server handler throws a `ConnectException` to send its code and message to the client:

```php
throw new ConnectException(Code::NotFound, 'no such user', [new ErrorInfo(['reason' => 'USER_DELETED'])]);
```

The third argument is a list of error details, each of them a generated protobuf message. The example uses `Google\Rpc\ErrorInfo` from `google/common-protos`. Any other exception thrown by a handler reaches the client as `unknown` with an empty message, so exception messages stay on the server.

Headers that belong to the error go in `metadata`. The server sends them as response headers after the ones in `$context->responseHeaders`:

```php
throw new ConnectException(Code::Unavailable, 'try again later', metadata: new Headers(['retry-after' => '5']));
```

`$e->metadata()` returns the same `Headers`, so a handler or interceptor can add to it before it throws. The server leaves out protocol headers such as `content-type`, `accept-encoding` and the `grpc-` status headers, names starting with `trailer-`, and any name or value `Headers` would reject. A `ConnectException` that a `Client` threw keeps its metadata to itself: a handler that rethrows it does not pass the upstream response headers on to its own caller.

The client throws the same exception:

```php
try {
    $greeter->greet(new GreetRequest(['name' => 'Jane']));
} catch (ConnectException $e) {
    if ($e->code() === Code::NotFound) {
        foreach ($e->details() as $detail) {
            $info = $detail->unpack(ErrorInfo::class);
            if ($info !== null) {
                echo $info->getReason(), "\n";
            }
        }
    }
}
```

`ErrorDetail::unpack($class)` returns `null` when the detail holds a different message type. `$e->metadata()` has the response headers and trailers of the failed call. A response that is not a Connect error, such as an HTML page from a proxy, gets the code that the HTTP status maps to and the status line as its message, for example `HTTP 502 Bad Gateway`. A `text/plain` or JSON body is appended to the message, cut to 1 KiB. A PSR-18 network error becomes `unavailable`, or `deadline_exceeded` once the call's timeout has passed, and any other PSR-18 exception becomes `unknown`.

## Interceptors

An interceptor wraps every call on the client or on the server. It gets a `UnaryRequest` with the method descriptor and the `Context`, and calls `$next` to continue. This one checks a bearer token:

```php
use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;

final readonly class TokenAuth implements Interceptor
{
    public function __construct(private string $token) {}

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

The server decodes the request body the first time something calls `$request->message()`, so this check rejects a bad token before the body is parsed.

This one logs every failed call at a level picked by its code, then rethrows:

```php
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

    public function intercept(UnaryRequest $request, \Closure $next): Message
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            $code = ConnectException::fromThrowable($e)->code();
            $level = match ($code) {
                Code::Unknown, Code::Internal, Code::DataLoss => LogLevel::ERROR,
                default => LogLevel::INFO,
            };
            $this->logger->log($level, '{procedure} failed with {code}', [
                'procedure' => $request->method->procedure(),
                'code' => $code->value,
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
```

Interceptors are passed as a list, and the first one is outermost. Here the error log sits inside the auth check, so rejected tokens are not logged:

```php
$server = new Server($factory, $factory, interceptors: [new TokenAuth($token), new ErrorLog($logger)]);
```

On the client, an interceptor may call `$next` more than once. A retry interceptor does that, and `$request->method->idempotency` tells it which calls are safe to repeat. There is one in [docs/symfony.md](docs/symfony.md).

## Using with Symfony

[docs/symfony.md](docs/symfony.md) has a controller that bridges HttpFoundation to `Server`, service wiring for the server and a generated client, the interceptors from this page, a retry interceptor, and an httplug plugin that applies `Connect-Timeout-Ms` as the transport timeout.

Moving an application from twirphp is described in [docs/migrating-from-twirphp.md](docs/migrating-from-twirphp.md).

## Conformance

CI runs the [Connect conformance suite](https://github.com/connectrpc/conformance) v1.0.7 against the server and the client, for the features in the table above. The server passes all 172 cases and the client passes 180 of 184. The four client failures are the `cancel-after-close-send` cases, because a synchronous PSR-18 client cannot cancel a call in flight. They are listed in `conformance/known-failing-client.txt`.

Running the suite locally needs Go, buf, and PHP with the `pcntl` and `posix` extensions:

```sh
make conformance-install
make conformance
```

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md) explains how to propose a change. Run `make check` before you open a pull request. Report security problems as described in [SECURITY.md](SECURITY.md).

## Legal

Copyright 2026 Footshop a.s. Licensed under the Apache License 2.0, see [LICENSE](LICENSE) and [NOTICE](NOTICE).

Connect is a Cloud Native Computing Foundation sandbox project created by Buf. Its name is used here only to describe the protocol this library implements. This project is not endorsed by or affiliated with the Connect project, Buf or the CNCF.
