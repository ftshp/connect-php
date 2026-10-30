<?php

declare(strict_types=1);

namespace Ftshp\Connect\Conformance;

use Google\Protobuf\Internal\Message;

final class Framing
{
    /**
     * @template T of Message
     *
     * @param T $into
     *
     * @return ?T null on end of input
     */
    public static function read(Message $into): ?Message
    {
        $prefix = stream_get_contents(STDIN, 4);
        if ($prefix === '' || $prefix === false) {
            return null;
        }
        $unpacked = \strlen($prefix) < 4 ? false : unpack('N', $prefix);
        $length = \is_array($unpacked) ? $unpacked[1] ?? null : null;
        if (!\is_int($length)) {
            throw new \RuntimeException('stdin closed inside a size prefix');
        }
        $body = $length === 0 ? '' : stream_get_contents(STDIN, $length);
        if ($body === false || \strlen($body) < $length) {
            throw new \RuntimeException('stdin closed inside a message');
        }
        $into->mergeFromString($body);

        return $into;
    }

    public static function write(Message $message): void
    {
        $data = $message->serializeToString();
        fwrite(STDOUT, pack('N', \strlen($data)) . $data);
        fflush(STDOUT);
    }
}
