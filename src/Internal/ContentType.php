<?php

declare(strict_types=1);

namespace Ftshp\Connect\Internal;

use Ftshp\Connect\Codec\Codec;

/** @internal */
final class ContentType
{
    /**
     * @param array<string, Codec> $codecs keyed by codec name
     */
    public static function codec(string $contentType, array $codecs): ?Codec
    {
        $type = self::mediaType($contentType);

        return str_starts_with($type, 'application/') ? $codecs[substr($type, 12)] ?? null : null;
    }

    public static function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}
