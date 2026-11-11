<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

final class Context
{
    public readonly Headers $responseHeaders;
    public readonly Headers $responseTrailers;
    public private(set) ?float $deadline = null;
    /** @var MethodDescriptor<Message, Message> */
    public MethodDescriptor $method {
        get => $this->startedMethod ?? throw new \LogicException('no call has started with this Context');
    }

    /** @var ?MethodDescriptor<Message, Message> */
    private ?MethodDescriptor $startedMethod = null;

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

    /**
     * @internal sets the method and starts the timer when a call or an attempt starts
     *
     * @param MethodDescriptor<Message, Message> $method
     */
    public function begin(MethodDescriptor $method): void
    {
        $this->startedMethod = $method;
        $this->deadline = $this->timeoutMs === null ? null : microtime(true) + $this->timeoutMs / 1000;
    }
}
