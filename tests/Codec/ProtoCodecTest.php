<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Codec;

use Ftshp\Connect\Code;
use Ftshp\Connect\Codec\ProtoCodec;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Test\EchoRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProtoCodecTest extends TestCase
{
    #[Test]
    public function roundTrip(): void
    {
        $codec = new ProtoCodec();
        $message = new EchoRequest();
        $message->setText('hello');
        $message->setBig(PHP_INT_MAX);
        $message->setBlob("\x00\xff\x10");

        $decoded = $codec->decode($codec->encode($message), EchoRequest::class);

        self::assertSame('proto', $codec->name());
        self::assertSame('hello', $decoded->getText());
        self::assertSame(PHP_INT_MAX, $decoded->getBig());
        self::assertSame("\x00\xff\x10", $decoded->getBlob());
    }

    #[Test]
    public function invalidBytesAreInvalidArgument(): void
    {
        try {
            new ProtoCodec()->decode("\x0a\x05a", EchoRequest::class);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertSame(Code::InvalidArgument, $e->code());
            self::assertStringStartsWith('invalid proto message: ', $e->getMessage());
        }
    }
}
