<?php

declare(strict_types=1);

namespace Ftshp\Connect\Compression;

use Ftshp\Connect\ConnectException;

/**
 * Exceptions other than ConnectException escape Client::unary() as they are, and on a Server they become
 * unknown with an empty message.
 */
interface Compression
{
    /**
     * The Content-Encoding token, such as gzip.
     */
    public function name(): string;

    public function compress(string $data): string;

    /**
     * Stops inflating as soon as the output exceeds $maxBytes, so a small body cannot exhaust memory.
     *
     * @param int $maxBytes 0 for no limit
     *
     * @throws ConnectException with Code::ResourceExhausted when the output exceeds $maxBytes
     * @throws ConnectException with Code::InvalidArgument when $data is not valid for this algorithm
     */
    public function decompress(string $data, int $maxBytes): string;
}
