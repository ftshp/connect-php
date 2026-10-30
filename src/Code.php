<?php

declare(strict_types=1);

namespace Ftshp\Connect;

enum Code: string
{
    case Canceled = 'canceled';
    case Unknown = 'unknown';
    case InvalidArgument = 'invalid_argument';
    case DeadlineExceeded = 'deadline_exceeded';
    case NotFound = 'not_found';
    case AlreadyExists = 'already_exists';
    case PermissionDenied = 'permission_denied';
    case ResourceExhausted = 'resource_exhausted';
    case FailedPrecondition = 'failed_precondition';
    case Aborted = 'aborted';
    case OutOfRange = 'out_of_range';
    case Unimplemented = 'unimplemented';
    case Internal = 'internal';
    case Unavailable = 'unavailable';
    case DataLoss = 'data_loss';
    case Unauthenticated = 'unauthenticated';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Canceled => 499,
            self::Unknown, self::Internal, self::DataLoss => 500,
            self::InvalidArgument, self::FailedPrecondition, self::OutOfRange => 400,
            self::DeadlineExceeded => 504,
            self::NotFound => 404,
            self::AlreadyExists, self::Aborted => 409,
            self::PermissionDenied => 403,
            self::ResourceExhausted => 429,
            self::Unimplemented => 501,
            self::Unavailable => 503,
            self::Unauthenticated => 401,
        };
    }

    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            400 => self::Internal,
            401 => self::Unauthenticated,
            403 => self::PermissionDenied,
            404 => self::Unimplemented,
            429, 502, 503, 504 => self::Unavailable,
            default => self::Unknown,
        };
    }
}
