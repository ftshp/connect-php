<?php

declare(strict_types=1);

namespace Ftshp\Connect\Codec;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Google\Protobuf\Internal\Message;

final class JsonCodec implements Codec
{
    public function name(): string
    {
        return 'json';
    }

    public function encode(Message $message): string
    {
        return $message->serializeToJsonString();
    }

    public function decode(string $data, string $class): Message
    {
        $message = new $class();
        try {
            $message->mergeFromJsonString($data, true);
        } catch (\Exception $e) {
            throw new ConnectException(Code::InvalidArgument, 'invalid json message: ' . $e->getMessage(), previous: $e);
        }

        return $message;
    }
}
