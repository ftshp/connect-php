<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Internal;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\ErrorDetail;
use Ftshp\Connect\Headers;
use Ftshp\Connect\Internal\ErrorBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorBodyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'null code' => ['{"code":null}'],
            'unknown code' => ['{"code":"bad_route"}'],
            'numeric code' => ['{"code":5}'],
            'missing code' => ['{"message":"x"}'],
            'empty list' => ['[]'],
            'string' => ['"x"'],
            'not json' => ['not json'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function encodeMinimal(): void
    {
        self::assertSame('{"code":"not_found"}', ErrorBody::encode(new ConnectException(Code::NotFound)));
    }

    #[Test]
    public function encodeWithMessageAndDetail(): void
    {
        $e = new ConnectException(Code::InvalidArgument, 'bad/ü', [new ErrorDetail('a.B', "\x01\x02")]);

        $json = ErrorBody::encode($e);
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame('{"code":"invalid_argument","message":"bad/ü","details":[{"type":"a.B","value":"AQI"}]}', $json);
        self::assertIsArray($data);
        self::assertSame(['code', 'message', 'details'], array_keys($data));
    }

    #[Test]
    public function decodeValid(): void
    {
        $e = ErrorBody::decode(
            '{"code":"not_found","message":"gone","details":[{"type":"a.B","value":"AQI"}],"debug":{"x":1}}',
            new Headers(),
        );

        self::assertNotNull($e);
        self::assertSame(Code::NotFound, $e->code());
        self::assertSame('gone', $e->getMessage());
        self::assertCount(1, $e->details());
        self::assertSame('a.B', $e->details()[0]->type);
        self::assertSame("\x01\x02", $e->details()[0]->value);
    }

    #[Test]
    public function decodeAcceptsPaddedBase64(): void
    {
        $e = ErrorBody::decode('{"code":"internal","details":[{"type":"a.B","value":"AQI="}]}', new Headers());

        self::assertNotNull($e);
        self::assertSame("\x01\x02", $e->details()[0]->value);
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function decodeRejectsInvalidBody(string $json): void
    {
        self::assertNull(ErrorBody::decode($json, new Headers()));
    }

    #[Test]
    public function decodeSkipsMalformedDetail(): void
    {
        $e = ErrorBody::decode(
            '{"code":"internal","details":[1,{"type":"a.B"},{"type":"a.B","value":"!!"},{"type":"c.D","value":"AQI"}]}',
            new Headers(),
        );

        self::assertNotNull($e);
        self::assertCount(1, $e->details());
        self::assertSame('c.D', $e->details()[0]->type);
    }

    #[Test]
    public function decodeAttachesMetadata(): void
    {
        $e = ErrorBody::decode('{"code":"aborted"}', new Headers(['x-a' => 'b']));

        self::assertNotNull($e);
        self::assertSame('b', $e->metadata()->get('x-a'));
        self::assertSame('', $e->getMessage());
    }
}
