<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Headers;
use Google\Protobuf\Struct;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
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
    public function metadataIsTakenFromTheConstructor(): void
    {
        $metadata = new Headers(['x-a' => 'b']);

        $e = new ConnectException(Code::Aborted, 'x', metadata: $metadata);

        self::assertSame($metadata, $e->metadata());
        self::assertFalse($e->isWireError());
    }

    #[Test]
    public function metadataCanBeChangedBeforeTheThrow(): void
    {
        $e = new ConnectException(Code::Aborted);

        $e->metadata()->set('x-a', 'b');

        self::assertSame('b', $e->metadata()->get('x-a'));
    }

    #[Test]
    #[IgnoreDeprecations]
    public function withMetadataReturnsCopyThatKeepsTheThrowSite(): void
    {
        $e = new ConnectException(Code::Aborted, 'x', [new Struct()], new \LogicException());
        $metadata = new Headers(['x-a' => 'b']);

        $this->expectUserDeprecationMessage('Method Ftshp\\Connect\\ConnectException::withMetadata() is deprecated since 0.2, pass metadata: to the constructor or change the Headers that metadata() returns');
        // @phpstan-ignore method.deprecated
        $copy = $e->withMetadata($metadata);

        self::assertNotSame($e, $copy);
        self::assertTrue($e->metadata()->isEmpty());
        self::assertSame('b', $copy->metadata()->get('x-a'));
        self::assertSame(Code::Aborted, $copy->code());
        self::assertSame('x', $copy->getMessage());
        self::assertSame($e->details(), $copy->details());
        self::assertSame($e->getPrevious(), $copy->getPrevious());
        self::assertSame($e->getFile(), $copy->getFile());
        self::assertSame($e->getLine(), $copy->getLine());
        self::assertSame($e->getTrace(), $copy->getTrace());
    }

    #[Test]
    #[IgnoreDeprecations]
    public function withMetadataCopyOfAReceivedErrorStaysReceived(): void
    {
        $e = ConnectException::received(Code::NotFound, 'gone', [], new Headers());

        // @phpstan-ignore method.deprecated
        self::assertTrue($e->withMetadata($e->metadata())->isWireError());
    }

    #[Test]
    public function receivedErrorIsMarked(): void
    {
        $metadata = new Headers(['x-a' => 'b']);

        $e = ConnectException::received(Code::NotFound, 'gone', [], $metadata);

        self::assertTrue($e->isWireError());
        self::assertSame(Code::NotFound, $e->code());
        self::assertSame('gone', $e->getMessage());
        self::assertSame($metadata, $e->metadata());
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
