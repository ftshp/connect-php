<?php

declare(strict_types=1);

namespace Ftshp\Connect\Internal;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;

/** @internal */
final class GetQuery
{
    public static function encode(string $codecName, string $message, ?string $compressionName): string
    {
        $base64 = $codecName === 'proto' || $compressionName !== null;
        $query = 'connect=v1';
        if ($base64) {
            $query .= '&base64=1';
        }
        if ($compressionName !== null) {
            $query .= '&compression=' . rawurlencode($compressionName);
        }

        return $query . '&encoding=' . rawurlencode($codecName) . '&message='
            . ($base64 ? rtrim(strtr(base64_encode($message), '+/', '-_'), '=') : rawurlencode($message));
    }

    /**
     * @param array<mixed> $query decoded query parameters
     * @return array{encoding: string, message: string, compression: ?string}
     */
    public static function parse(array $query): array
    {
        $encoding = $query['encoding'] ?? null;
        $message = $query['message'] ?? null;
        if (!\is_string($encoding) || !\is_string($message)) {
            throw new ConnectException(Code::InvalidArgument, 'missing encoding or message query parameter');
        }
        if (($query['base64'] ?? null) === '1') {
            $message = base64_decode(strtr($message, '-_', '+/'), true);
            if ($message === false) {
                throw new ConnectException(Code::InvalidArgument, 'invalid base64 in message query parameter');
            }
        }
        $compression = $query['compression'] ?? null;

        return ['encoding' => $encoding, 'message' => $message, 'compression' => \is_string($compression) ? $compression : null];
    }
}
