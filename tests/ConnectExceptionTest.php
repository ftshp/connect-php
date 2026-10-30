<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Headers;
use Google\Protobuf\Struct;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectExceptionTest extends TestCase
{
    #[Test]
    public function exposesCodeAndMessage(): void
    {
        $e = new ConnectException(Code::NotFound, 'no such order');

        self::assertSame(Code::NotFound, $e->code());
        self::assertSame('no such order', $e->getMessage());
        self::assertSame([], $e->details());
        self::assertTrue($e->metadata()->isEmpty());
    }

    #[Test]
    public function messagesAreWrappedAsDetails(): void
    {
        $e = new ConnectException(Code::InvalidArgument, 'bad', [new Struct()]);

        self::assertCount(1, $e->details());
        self::assertSame('google.protobuf.Struct', $e->details()[0]->type);
    }

    #[Test]
    public function withMetadataReturnsCopy(): void
    {
        $e = new ConnectException(Code::Aborted, 'x');
        $metadata = new Headers(['x-a' => 'b']);

        $copy = $e->withMetadata($metadata);

        self::assertNotSame($e, $copy);
        self::assertTrue($e->metadata()->isEmpty());
        self::assertSame('b', $copy->metadata()->get('x-a'));
        self::assertSame(Code::Aborted, $copy->code());
        self::assertSame('x', $copy->getMessage());
    }

    #[Test]
    public function fromThrowableWrapsUnknownWithEmptyMessage(): void
    {
        $original = new \RuntimeException('secret');

        $e = ConnectException::fromThrowable($original);

        self::assertSame(Code::Unknown, $e->code());
        self::assertSame('', $e->getMessage());
        self::assertSame($original, $e->getPrevious());
    }

    #[Test]
    public function fromThrowableReturnsSameInstance(): void
    {
        $e = new ConnectException(Code::Internal);

        self::assertSame($e, ConnectException::fromThrowable($e));
    }
}
