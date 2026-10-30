<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\DescriptorPool;
use Google\Protobuf\Internal\GPBDecodeException;
use Google\Protobuf\Internal\Message;

final readonly class ErrorDetail
{
    public function __construct(
        public string $type,
        public string $value,
    ) {}

    public static function fromMessage(Message $message): self
    {
        return new self(self::typeOf($message), $message->serializeToString());
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $class
     *
     * @return ?T
     */
    public function unpack(string $class): ?Message
    {
        $message = new $class();
        if (self::typeOf($message) !== $this->type) {
            return null;
        }

        try {
            $message->mergeFromString($this->value);
        } catch (GPBDecodeException $e) {
            throw new ConnectException(Code::Internal, 'invalid error detail', [], $e);
        }

        return $message;
    }

    private static function typeOf(Message $message): string
    {
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($message::class);

        // @phpstan-ignore identical.alwaysFalse (the docblock says non-null, but the pool returns null for unknown classes)
        if ($descriptor === null) {
            throw new \InvalidArgumentException(\sprintf('No descriptor for %s', $message::class));
        }

        return $descriptor->getFullName();
    }
}
