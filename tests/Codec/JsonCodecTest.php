<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Codec;

use Ftshp\Connect\Code;
use Ftshp\Connect\Codec\JsonCodec;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Test\EchoRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonCodecTest extends TestCase
{
    #[Test]
    public function roundTrip(): void
    {
        $codec = new JsonCodec();
        $message = new EchoRequest();
        $message->setText('hello');
        $message->setBig(42);
        $message->setBlob("\x00\xff\x10");

        $decoded = $codec->decode($codec->encode($message), EchoRequest::class);

        self::assertSame('json', $codec->name());
        self::assertSame('hello', $decoded->getText());
        self::assertSame(42, $decoded->getBig());
        self::assertSame("\x00\xff\x10", $decoded->getBlob());
    }

    #[Test]
    public function unknownFieldsAreIgnored(): void
    {
        $decoded = new JsonCodec()->decode('{"text":"a","nope":1}', EchoRequest::class);

        self::assertSame('a', $decoded->getText());
    }

    #[Test]
    public function invalidJsonIsInvalidArgument(): void
    {
        try {
            new JsonCodec()->decode('{not json', EchoRequest::class);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertSame(Code::InvalidArgument, $e->code());
            self::assertStringStartsWith('invalid json message: ', $e->getMessage());
        }
    }

    #[Test]
    public function int64IsAStringInJson(): void
    {
        $message = new EchoRequest();
        $message->setBig(9007199254740993);

        self::assertStringContainsString('"big":"9007199254740993"', new JsonCodec()->encode($message));
    }
}
