<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Compression;

use Ftshp\Connect\Code;
use Ftshp\Connect\Compression\Gzip;
use Ftshp\Connect\ConnectException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GzipTest extends TestCase
{
    #[Test]
    public function roundTrip(): void
    {
        $gzip = new Gzip();

        self::assertSame('gzip', $gzip->name());
        self::assertSame('payload', $gzip->decompress($gzip->compress('payload'), 1024));
    }

    #[Test]
    public function limitIsEnforced(): void
    {
        $gzip = new Gzip();
        $compressed = $gzip->compress(str_repeat("\0", 1024 * 1024));

        try {
            $gzip->decompress($compressed, 1024);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertSame(Code::ResourceExhausted, $e->code());
        }
    }

    #[Test]
    public function resultExactlyAtTheLimitIsAccepted(): void
    {
        $gzip = new Gzip();

        self::assertSame(1024, strlen($gzip->decompress($gzip->compress(str_repeat('a', 1024)), 1024)));
    }

    #[Test]
    public function oneByteOverTheLimitIsResourceExhausted(): void
    {
        $gzip = new Gzip();

        try {
            $gzip->decompress($gzip->compress(str_repeat('a', 1025)), 1024);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertSame(Code::ResourceExhausted, $e->code());
        }
    }

    #[Test]
    public function unlimitedWhenZero(): void
    {
        $gzip = new Gzip();
        $data = str_repeat("\0", 1024 * 1024);

        self::assertSame($data, $gzip->decompress($gzip->compress($data), 0));
    }

    #[Test]
    public function invalidDataIsInvalidArgument(): void
    {
        try {
            new Gzip()->decompress('not gzip', 1024);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertSame(Code::InvalidArgument, $e->code());
            self::assertSame('invalid gzip data', $e->getMessage());
        }
    }
}
