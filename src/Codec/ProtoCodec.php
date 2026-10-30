<?php

declare(strict_types=1);

namespace Ftshp\Connect\Codec;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Google\Protobuf\Internal\Message;

final class ProtoCodec implements Codec
{
    public function name(): string
    {
        return 'proto';
    }

    public function encode(Message $message): string
    {
        return $message->serializeToString();
    }

    public function decode(string $data, string $class): Message
    {
        $message = new $class();
        try {
            $message->mergeFromString($data);
        } catch (\Exception $e) {
            throw new ConnectException(Code::InvalidArgument, 'invalid proto message: ' . $e->getMessage(), previous: $e);
        }

        return $message;
    }
}
