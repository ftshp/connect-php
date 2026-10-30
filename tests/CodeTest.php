<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeTest extends TestCase
{
    /**
     * @return array<string, array{Code, int}>
     */
    public static function httpStatusTable(): array
    {
        return [
            'canceled' => [Code::Canceled, 499],
            'unknown' => [Code::Unknown, 500],
            'invalid_argument' => [Code::InvalidArgument, 400],
            'deadline_exceeded' => [Code::DeadlineExceeded, 504],
            'not_found' => [Code::NotFound, 404],
            'already_exists' => [Code::AlreadyExists, 409],
            'permission_denied' => [Code::PermissionDenied, 403],
            'resource_exhausted' => [Code::ResourceExhausted, 429],
            'failed_precondition' => [Code::FailedPrecondition, 400],
            'aborted' => [Code::Aborted, 409],
            'out_of_range' => [Code::OutOfRange, 400],
            'unimplemented' => [Code::Unimplemented, 501],
            'internal' => [Code::Internal, 500],
            'unavailable' => [Code::Unavailable, 503],
            'data_loss' => [Code::DataLoss, 500],
            'unauthenticated' => [Code::Unauthenticated, 401],
        ];
    }

    /**
     * @return array<string, array{int, Code}>
     */
    public static function inferenceTable(): array
    {
        return [
            'http 400' => [400, Code::Internal],
            'http 401' => [401, Code::Unauthenticated],
            'http 403' => [403, Code::PermissionDenied],
            'http 404' => [404, Code::Unimplemented],
            'http 429' => [429, Code::Unavailable],
            'http 502' => [502, Code::Unavailable],
            'http 503' => [503, Code::Unavailable],
            'http 504' => [504, Code::Unavailable],
            'http 418' => [418, Code::Unknown],
            'http 200' => [200, Code::Unknown],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownWireValues(): array
    {
        return [
            'route error' => ['bad_route'],
            'wrong case' => ['Canceled'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('httpStatusTable')]
    public function httpStatusFollowsTheSpecTable(Code $code, int $status): void
    {
        self::assertSame($status, $code->httpStatus());
    }

    #[Test]
    #[DataProvider('inferenceTable')]
    public function fromHttpStatusInference(int $status, Code $expected): void
    {
        self::assertSame($expected, Code::fromHttpStatus($status));
    }

    #[Test]
    public function wireValuesRoundTrip(): void
    {
        foreach (Code::cases() as $code) {
            self::assertSame($code, Code::from($code->value));
        }
    }

    #[Test]
    #[DataProvider('unknownWireValues')]
    public function unknownWireValueIsRejected(string $value): void
    {
        self::assertNull(Code::tryFrom($value));
    }
}
