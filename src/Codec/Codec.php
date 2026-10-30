<?php

declare(strict_types=1);

namespace Ftshp\Connect\Codec;

use Google\Protobuf\Internal\Message;

interface Codec
{
    public function name(): string;

    public function encode(Message $message): string;

    /**
     * @template T of Message
     * @param class-string<T> $class
     * @return T
     */
    public function decode(string $data, string $class): Message;
}
