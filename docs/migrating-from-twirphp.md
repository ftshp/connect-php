# Migrating from twirphp

To move a PHP application from twirphp to connect-php one service at a time without breaking callers, generate both sets of code, serve both routes during the switch, then move the callers. The examples use a `Stock` service in the `acme.stock` proto package, generated into the `Acme\Stock` namespace.

## Generating both

Add the connect-php plugin to the protoc or buf run that already produces the Twirp code. The message classes come from `--php_out` once and both sets of service code use them, so the old and the new code can live in one package during the switch:

```sh
protoc --php_out=gen --twirp_php_out=gen --connect-php_out=gen acme/stock/stock.proto
```

## Class names

| twirphp | connect-php |
|---|---|
| `Stock` (interface) | `StockInterface` |
| `StockClient`, `StockJsonClient` | `StockClient`, with the codec set on `Ftshp\Connect\Client` |
| `StockServer`, one per service | `Ftshp\Connect\Server`, one for all services |
| `TwirpError`, one per package | `Ftshp\Connect\ConnectException` |
| `Twirp\ServerHooks` | `Ftshp\Connect\Interceptor`, on the server and on the client |

Method names change from `AvailabilityGet` to `availabilityGet`. PHP method names are case-insensitive, so changing the case is optional. The argument order still changes, as the next section shows.

## Method signatures

The generated methods take the message first and a `Context` second:

```php
// twirphp
interface Stock
{
    public function AvailabilityGet(array $ctx, AvailabilityGetRequest $req): AvailabilityGetResponse;
}

// connect-php
interface StockInterface
{
    public function availabilityGet(AvailabilityGetRequest $request, Context $context = new Context()): AvailabilityGetResponse;
}
```

The interface and the client default `$context` to a new `Context`, so most calls become `$stock->availabilityGet($request)`, also where the code only knows `StockInterface`. A server implementation repeats `= new Context()` in its own signature, since PHP does not let an implementation make the parameter required. Request headers move from the context array to `Headers`:

```php
// twirphp
$ctx = \Twirp\Context::withHttpRequestHeaders([], ['Authorization' => 'Bearer ' . $token]);
$stock->AvailabilityGet($ctx, $request);

// connect-php
$stock->availabilityGet($request, new Context(new Headers(['authorization' => 'Bearer ' . $token])));
```

A server implementation reads request headers from `$context->requestHeaders` and sets response headers with `$context->responseHeaders->set()` instead of `Twirp\Context::withHttpResponseHeader()`.

twirphp code often passes the incoming `$ctx` on to the next client call. A connect-php `Context` belongs to one call, so a handler that calls another service builds a new one with the headers it means to forward and the time that is left:

```php
public function availabilityGet(AvailabilityGetRequest $request, Context $context = new Context()): AvailabilityGetResponse
{
    $forwarded = new Context(
        new Headers(['authorization' => $context->requestHeaders->get('authorization') ?? '']),
        timeoutMs: $context->remainingMs(),
    );
    $stock = $this->warehouse->stockGet(new StockGetRequest(['sku' => $request->getSku()]), $forwarded);

    return new AvailabilityGetResponse(['quantity' => $stock->getQuantity()]);
}
```

Reusing the incoming `Context` would send every header the caller sent on to the other service, and the downstream response headers would replace the ones the handler had set.

## Clients

The twirphp client takes the address and the PSR-18 client directly. The connect-php client wraps a `Client` that holds the transport settings:

```php
// twirphp
$stock = new \Acme\Stock\StockClient('https://stock.internal', $httpClient, $requestFactory, $streamFactory);

// connect-php
$stock = new \Acme\Stock\StockClient(new Client($httpClient, $requestFactory, $streamFactory, 'https://stock.internal'));
```

The PSR-18 client stays the same, so httplug plugins for authentication, logging and retries keep working. A retry decider that looks at the last segment of the URL still sees the method name.

## Errors

Catch `ConnectException` instead of `Twirp\Error`, and compare `code()`, which returns the `Code` enum, instead of the string from `getErrorCode()`:

```php
// twirphp
try {
    $stock->AvailabilityGet([], $request);
} catch (\Twirp\Error $e) {
    if ($e->getErrorCode() === \Twirp\ErrorCode::NotFound) {
        return null;
    }
    throw $e;
}

// connect-php
try {
    $stock->availabilityGet($request);
} catch (ConnectException $e) {
    if ($e->code() === Code::NotFound) {
        return null;
    }
    throw $e;
}
```

On the server, `TwirpError::newError(ErrorCode::NotFound, 'no such variant')` becomes `new ConnectException(Code::NotFound, 'no such variant')`.

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

Twirp serves `/twirp/acme.stock.Stock/AvailabilityGet`. Connect serves `/acme.stock.Stock/AvailabilityGet`, without the `/twirp` prefix. If a gateway or firewall routes on the prefix, either change its rules or pass the same `prefix` to `Server` and `Client`.

## Running both servers side by side

During the switch the application serves the old Twirp route and the new Connect route at the same time. Implement the new interface, then keep the old `StockServer` running with an adapter that implements the old interface by delegating to the new implementation:

```php
<?php

declare(strict_types=1);

namespace App\Stock;

use Acme\Stock\AvailabilityGetRequest;
use Acme\Stock\AvailabilityGetResponse;
use Acme\Stock\Stock;
use Acme\Stock\StockInterface;
use Acme\Stock\TwirpError;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;

final readonly class TwirpStockAdapter implements Stock
{
    public function __construct(private StockInterface $stock) {}

    public function AvailabilityGet(array $ctx, AvailabilityGetRequest $req): AvailabilityGetResponse
    {
        return $this->call(fn(Context $context) => $this->stock->availabilityGet($req, $context));
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
$twirp = new \Acme\Stock\StockServer(new TwirpStockAdapter($stockService), $hooks, $factory, $factory);

$connect = new Server($factory, $factory, [new TokenAuth($token)]);
$connect->register(StockInterface::class, $stockService);
```

Then move the callers. PHP callers switch to the new generated client. Go callers that use a Twirp client switch to a connect-go client for the same service. When the access log shows no more requests under `/twirp/`, remove the adapter, the Twirp route and the twirphp packages.

## Streaming RPCs

Twirp has no streaming, and twirphp generates streaming RPCs as if they were unary. connect-php supports only unary RPCs, and the generator skips streaming ones with a line on stderr:

```text
protoc-gen-connect-php: skipping acme.stock.Stock/ReservationsWatch: streaming RPCs are not supported
```

The skipped methods are missing from the generated interface and client. A PHP application that calls one of them today has to keep that call on the Twirp client.
