<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\ErrorDetail;
use Google\Protobuf\Struct;
use Google\Protobuf\Timestamp;
use Google\Protobuf\Value;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorDetailTest extends TestCase
{
    #[Test]
    public function unpackReturnsTypedMessage(): void
    {
        $struct = new Struct();
        $struct->getFields()['name'] = (new Value())->setStringValue('shoe');

        $unpacked = ErrorDetail::fromMessage($struct)->unpack(Struct::class);

        self::assertInstanceOf(Struct::class, $unpacked);
        $field = $unpacked->getFields()['name'];
        self::assertInstanceOf(Value::class, $field);
        self::assertSame('shoe', $field->getStringValue());
    }

    #[Test]
    public function unpackReturnsNullOnTypeMismatch(): void
    {
        self::assertNull(ErrorDetail::fromMessage(new Struct())->unpack(Timestamp::class));
    }

    #[Test]
    public function unpackFailsWithInternalOnMalformedValue(): void
    {
        $detail = new ErrorDetail('google.protobuf.Struct', "\x0a\x05a");

        try {
            $detail->unpack(Struct::class);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertSame(Code::Internal, $e->code());
            self::assertSame('invalid error detail', $e->getMessage());
        }
    }
}
