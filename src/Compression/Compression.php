<?php

declare(strict_types=1);

namespace Ftshp\Connect\Compression;

interface Compression
{
    public function name(): string;

    public function compress(string $data): string;

    /** A $maxBytes of 0 means unlimited. */
    public function decompress(string $data, int $maxBytes): string;
}
