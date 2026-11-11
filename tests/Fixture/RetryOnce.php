<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Fixture;

use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;

final class RetryOnce implements Interceptor
{
    #[\Override]
    public function intercept(UnaryRequest $request, \Closure $next): Message
    {
        try {
            return $next($request);
        } catch (ConnectException) {
            return $next($request);
        }
    }
}
