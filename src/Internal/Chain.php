<?php

declare(strict_types=1);

namespace Ftshp\Connect\Internal;

use Ftshp\Connect\Interceptor;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;

/**
 * @internal
 */
final class Chain
{
    /**
     * @param list<Interceptor> $interceptors
     * @param \Closure(UnaryRequest): Message $innermost
     * @return \Closure(UnaryRequest): Message
     */
    public static function build(array $interceptors, \Closure $innermost): \Closure
    {
        return array_reduce(
            array_reverse($interceptors),
            static fn(\Closure $next, Interceptor $interceptor): \Closure
                => static fn(UnaryRequest $request): Message => $interceptor->intercept($request, $next),
            $innermost,
        );
    }
}
