<?php

declare(strict_types=1);

namespace Ftshp\Connect\Codec;

use Ftshp\Connect\ConnectException;
use Google\Protobuf\Internal\Message;

/**
 * Client takes any implementation, Server uses its own proto and json codecs. Exceptions other than ConnectException escape Client::unary() as they are,
 * and on a Server they become unknown with an empty message.
 */
interface Codec
{
    /**
     * The codec part of the content type, application/{name}.
     */
    public function name(): string;

    public function encode(Message $message): string;

    /**
     * @template T of Message
     * @param class-string<T> $class
     * @return T
     *
     * @throws ConnectException with Code::InvalidArgument when $data is not a valid $class message
     */
    public function decode(string $data, string $class): Message;
}
