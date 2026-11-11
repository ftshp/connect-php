# Migrating from twirphp

To move a PHP application from twirphp to connect-php one service at a time without breaking callers, generate both sets of code, serve both routes during the switch, then move the callers. The examples use the `GreetService` from the README, in the `greet.v1` proto package, generated into the `Greet\V1` namespace.

## Generating both

The message classes come from `--php_out` once and both sets of service code use them, so the old and the new code can live in one package during the switch. Both plugins write a class named `<Service>Client`, though, so they cannot write to the same directory: protoc stops on the duplicate file, and separate runs would overwrite one client with the other. Write the Twirp code to a directory of its own:

```sh
protoc --php_out=gen --connect-php_out=gen greet/v1/greet.proto
protoc --twirp_php_out=gen-twirp greet/v1/greet.proto
```

In an application that serves both protocols, delete `gen-twirp/Greet/V1/GreetServiceClient.php` and list both directories for the namespace in `composer.json`. The Twirp server, its interface and `TwirpError` do not use that client. An application that calls the service switches from the Twirp client to the Connect client in one change, so it never needs both.

```json
"autoload": {
    "psr-4": {
        "Greet\\V1\\": ["gen/Greet/V1/", "gen-twirp/Greet/V1/"],
        "GPBMetadata\\": "gen/GPBMetadata/"
    }
}
```

## Class names

| twirphp | connect-php |
|---|---|
| `GreetService` (interface) | `GreetServiceInterface` |
| `GreetServiceClient`, `GreetServiceJsonClient` | `GreetServiceClient`, with the codec set on `Ftshp\Connect\Client` |
| `GreetServiceServer`, one per service | `Ftshp\Connect\Server`, one for all services |
| `TwirpError`, one per package | `Ftshp\Connect\ConnectException` |
| `Twirp\ServerHooks` | `Ftshp\Connect\Interceptor`, on the server and on the client |

Method names change from `Greet` to `greet`. PHP method names are case-insensitive, so changing the case is optional. The argument order still changes, as the next section shows.

## Method signatures

The generated methods take the message first and a `Context` second:

```php
// twirphp
interface GreetService
{
    public function Greet(array $ctx, GreetRequest $req): GreetResponse;
}

// connect-php
interface GreetServiceInterface
{
    public function greet(GreetRequest $request, Context $context = new Context()): GreetResponse;
}
```

The interface and the client default `$context` to a new `Context`, so most calls become `$greeter->greet($request)`, also where the code only knows `GreetServiceInterface`. A server implementation repeats `= new Context()` in its own signature, since PHP does not let an implementation make the parameter required. Request headers move from the context array to `Headers`:

```php
// twirphp
$ctx = \Twirp\Context::withHttpRequestHeaders([], ['Authorization' => 'Bearer ' . $token]);
$greeter->Greet($ctx, $request);

// connect-php
$greeter->greet($request, new Context(new Headers(['authorization' => 'Bearer ' . $token])));
```

A server implementation reads request headers from `$context->requestHeaders` and sets response headers with `$context->responseHeaders->set()` instead of `Twirp\Context::withHttpResponseHeader()`.

twirphp code often passes the incoming `$ctx` on to the next client call. A connect-php `Context` belongs to one call, so a handler that calls another service builds a new one with the headers it means to forward and the time that is left:

```php
public function greet(GreetRequest $request, Context $context = new Context()): GreetResponse
{
    $forwarded = new Context(
        new Headers(['authorization' => $context->requestHeaders->get('authorization') ?? '']),
        timeoutMs: $context->remainingMs(),
    );
    $profile = $this->profiles->getProfile(new GetProfileRequest(['name' => $request->getName()]), $forwarded);

    return new GreetResponse(['greeting' => 'Hello, ' . $profile->getDisplayName() . '!']);
}
```

Reusing the incoming `Context` would send every header the caller sent on to the other service, and the downstream response headers would replace the ones the handler had set.

## Clients

The twirphp client takes the address and the PSR-18 client directly. The connect-php client wraps a `Client` that holds the transport settings:

```php
// twirphp
$greeter = new \Greet\V1\GreetServiceClient('https://greet.example.com', $httpClient, $requestFactory, $streamFactory);

// connect-php
$greeter = new \Greet\V1\GreetServiceClient(new Client($httpClient, $requestFactory, $streamFactory, 'https://greet.example.com'));
```

The PSR-18 client stays the same, so httplug plugins for authentication, logging and retries keep working. A retry decider that looks at the last segment of the URL still sees the method name. With Guzzle, set `decode_content` to `false`, as the README explains, so that the response size limit also bounds decompression.

twirphp has no size limit. connect-php rejects request and response messages over 4 MiB with `resource_exhausted`, on both sides. A service with larger messages needs a higher `maxResponseBytes` on its clients and `maxRequestBytes` on its server, or `0` for no limit.

## Errors

Catch `ConnectException` instead of `Twirp\Error`, and compare `code()`, which returns the `Code` enum, instead of the string from `getErrorCode()`:

```php
// twirphp
try {
    $greeter->Greet([], $request);
} catch (\Twirp\Error $e) {
    if ($e->getErrorCode() === \Twirp\ErrorCode::NotFound) {
        return null;
    }
    throw $e;
}

// connect-php
try {
    $greeter->greet($request);
} catch (ConnectException $e) {
    if ($e->code() === Code::NotFound) {
        return null;
    }
    throw $e;
}
```

On the server, `TwirpError::newError(ErrorCode::NotFound, 'no such user')` becomes `new ConnectException(Code::NotFound, 'no such user')`.

Twirp error meta is a map of strings. Connect has error details instead, which are protobuf messages, so a value that was `setMeta('reason', ...)` goes into a message such as `Google\Rpc\ErrorInfo` and is read back with `$e->details()[0]->unpack(ErrorInfo::class)`. Code that read `getMeta('cause')` to get the original exception message can use `getMessage()`.

twirphp sends any other exception as `internal` with the exception message. connect-php sends it as `unknown` with an empty message and keeps the message on the server, so log it in an interceptor. The Twirp-only codes have Connect equivalents: a body that does not decode is `invalid_argument` instead of `malformed`, and an unknown route is `unimplemented` instead of `bad_route`.

## HTTP status codes

Both protocols use the same error code names, and clients read the code from the response body. A few codes map to different HTTP statuses, which matters for proxies, access logs, dashboards and alerts that count statuses:

| Code | Twirp | Connect |
|---|---|---|
| `canceled` | 408 | 499 |
| `deadline_exceeded` | 408 | 504 |
| `failed_precondition` | 412 | 400 |

## Paths

Twirp serves `/twirp/greet.v1.GreetService/Greet`. Connect serves `/greet.v1.GreetService/Greet`, without the `/twirp` prefix. If a gateway or firewall routes on the prefix, either change its rules or pass the same `prefix` to `Server` and `Client`.

## Running both servers side by side

During the switch the application serves the old Twirp route and the new Connect route at the same time. Implement the new interface, then keep the old `GreetServiceServer` running with an adapter that implements the old interface by delegating to the new implementation:

```php
<?php

declare(strict_types=1);

namespace App\Greet;

use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Greet\V1\GreetRequest;
use Greet\V1\GreetResponse;
use Greet\V1\GreetService;
use Greet\V1\GreetServiceInterface;
use Greet\V1\TwirpError;

final readonly class TwirpGreetAdapter implements GreetService
{
    public function __construct(private GreetServiceInterface $greeter) {}

    public function Greet(array $ctx, GreetRequest $req): GreetResponse
    {
        return $this->call(fn(Context $context) => $this->greeter->greet($req, $context));
    }

    /**
     * @template T
     *
     * @param \Closure(Context): T $call
     *
     * @return T
     */
    private function call(\Closure $call): mixed
    {
        try {
            return $call(new Context());
        } catch (ConnectException $e) {
            throw TwirpError::newError($e->code()->value, $e->getMessage(), $e);
        }
    }
}
```

The adapter passes an empty `Context`, so the implementation sees no request headers on the Twirp route and any response headers it sets are dropped there. Every Connect code name is also a valid Twirp code, so the code passes through as a string. Other exceptions reach twirphp unchanged and it handles them as before. The Twirp route keeps its server hooks, so authentication on the old path does not change.

Mount both handlers. With Symfony, keep the existing Twirp controller on `/twirp/...` and add the Connect controller from [symfony.md](symfony.md):

```php
$twirp = new \Greet\V1\GreetServiceServer(new TwirpGreetAdapter($greeter), $hooks, $factory, $factory);

$connect = new Server($factory, $factory, interceptors: [new TokenAuth($token)]);
$connect->register(GreetServiceInterface::class, $greeter);
```

Then move the callers. PHP callers switch to the new generated client. Go callers that use a Twirp client switch to a connect-go client for the same service. When the access log shows no more requests under `/twirp/`, remove the adapter, the Twirp route and the twirphp packages.

## Streaming RPCs

Twirp has no streaming, and twirphp generates streaming RPCs as if they were unary. connect-php supports only unary RPCs, and the generator skips streaming ones with a line on stderr:

```text
protoc-gen-connect-php: skipping greet.v1.GreetService/GreetStream: streaming RPCs are not supported
```

The skipped methods are missing from the generated interface and client. A PHP application that calls one of them today has to keep that call on the Twirp client.
