<?php

declare(strict_types=1);

namespace Ftshp\Connect\Internal;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\ErrorDetail;
use Ftshp\Connect\Headers;

/** @internal */
final class ErrorBody
{
    public static function encode(ConnectException $e): string
    {
        $body = ['code' => $e->code()->value];
        if ($e->getMessage() !== '') {
            $body['message'] = $e->getMessage();
        }
        if ($e->details() !== []) {
            $body['details'] = array_map(
                static fn(ErrorDetail $detail): array => [
                    'type' => $detail->type,
                    'value' => rtrim(base64_encode($detail->value), '='),
                ],
                $e->details(),
            );
        }

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $json, Headers $metadata): ?ConnectException
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || !\is_string($data['code'] ?? null)) {
            return null;
        }
        $code = Code::tryFrom($data['code']);
        if ($code === null) {
            return null;
        }

        $message = \is_string($data['message'] ?? null) ? $data['message'] : '';

        return (new ConnectException($code, $message, self::details($data['details'] ?? null)))
            ->withMetadata($metadata);
    }

    /**
     * @return list<ErrorDetail>
     */
    private static function details(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $details = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry) || !\is_string($entry['type'] ?? null) || !\is_string($entry['value'] ?? null)) {
                continue;
            }
            $value = base64_decode(str_pad($entry['value'], (int) ceil(\strlen($entry['value']) / 4) * 4, '='), true);
            if ($value !== false) {
                $details[] = new ErrorDetail($entry['type'], $value);
            }
        }

        return $details;
    }
}
