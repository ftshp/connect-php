<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

/**
 * @template TIn of Message
 * @template TOut of Message
 */
final readonly class MethodDescriptor
{
    /**
     * @param class-string<TIn> $input
     * @param class-string<TOut> $output
     */
    public function __construct(
        public string $service,
        public string $name,
        public string $phpName,
        public string $input,
        public string $output,
        public Idempotency $idempotency = Idempotency::Unknown,
    ) {}

    public function procedure(): string
    {
        return '/' . $this->service . '/' . $this->name;
    }
}
