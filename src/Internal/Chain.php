<?php

declare(strict_types=1);

namespace Ftshp\Connect\Internal;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
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
        $chain = array_reduce(
            array_reverse($interceptors),
            static fn(\Closure $next, Interceptor $interceptor): \Closure
                => static fn(UnaryRequest $request): Message => $interceptor->intercept($request, $next),
            $innermost,
        );

        return static function (UnaryRequest $request) use ($chain): Message {
            $response = $chain($request);
            if (!$response instanceof $request->method->output) {
                throw new ConnectException(Code::Internal, \sprintf('interceptor returned %s, expected %s', $response::class, $request->method->output));
            }

            return $response;
        };
    }
}
