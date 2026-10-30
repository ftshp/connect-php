<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

final class Context
{
    public readonly Headers $responseHeaders;
    public readonly Headers $responseTrailers;
    public ?float $deadline = null;
    /** @var MethodDescriptor<Message, Message>|null */
    public ?MethodDescriptor $method = null;

    public function __construct(
        public readonly Headers $requestHeaders = new Headers(),
        public ?int $timeoutMs = null,
    ) {
        $this->responseHeaders = new Headers();
        $this->responseTrailers = new Headers();
    }

    public function remainingMs(): ?int
    {
        if ($this->deadline === null) {
            return null;
        }

        return max(0, (int) ceil(($this->deadline - microtime(true)) * 1000));
    }

    public function startTimer(): void
    {
        if ($this->timeoutMs !== null) {
            $this->deadline = microtime(true) + $this->timeoutMs / 1000;
        }
    }
}
