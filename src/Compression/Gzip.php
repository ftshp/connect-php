<?php

declare(strict_types=1);

namespace Ftshp\Connect\Compression;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;

final class Gzip implements Compression
{
    public function name(): string
    {
        return 'gzip';
    }

    public function compress(string $data): string
    {
        $compressed = gzencode($data, 6);
        if ($compressed === false) {
            throw new \RuntimeException('gzip compression failed');
        }

        return $compressed;
    }

    public function decompress(string $data, int $maxBytes): string
    {
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $result = $maxBytes > 0 ? gzdecode($data, $maxBytes + 1) : gzdecode($data);
        } finally {
            restore_error_handler();
        }

        // gzdecode() with a length cap returns false and warns "insufficient memory" once the cap is hit
        if ($result === false && str_contains($warning, 'insufficient memory')) {
            throw new ConnectException(Code::ResourceExhausted, 'message size exceeds limit');
        }
        if ($result === false) {
            throw new ConnectException(Code::InvalidArgument, 'invalid gzip data');
        }
        if ($maxBytes > 0 && strlen($result) > $maxBytes) {
            throw new ConnectException(Code::ResourceExhausted, 'message size exceeds limit');
        }

        return $result;
    }
}
