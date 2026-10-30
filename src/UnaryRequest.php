<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

final class UnaryRequest
{
    /** @var (\Closure(): Message)|Message */
    private \Closure|Message $message;

    /**
     * @param MethodDescriptor<Message, Message> $method
     * @param (\Closure(): Message)|Message $message
     */
    public function __construct(
        public readonly MethodDescriptor $method,
        public readonly Context $context,
        public readonly bool $isClient,
        \Closure|Message $message,
    ) {
        $this->message = $message;
    }

    public function message(): Message
    {
        if ($this->message instanceof \Closure) {
            $this->message = ($this->message)();
        }

        return $this->message;
    }
}
