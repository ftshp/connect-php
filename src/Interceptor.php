<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

interface Interceptor
{
    /**
     * @param \Closure(UnaryRequest): Message $next
     */
    public function intercept(UnaryRequest $request, \Closure $next): Message;
}
