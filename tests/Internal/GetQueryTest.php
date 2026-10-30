<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Internal;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Internal\GetQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GetQueryTest extends TestCase
{
    #[Test]
    public function encodeProto(): void
    {
        self::assertSame('connect=v1&base64=1&encoding=proto&message=CgNhYmM', GetQuery::encode('proto', "\x0a\x03abc", null));
    }

    #[Test]
    public function encodeJson(): void
    {
        self::assertSame(
            'connect=v1&encoding=json&message=%7B%22text%22%3A%22a%2Bb%2Fc%22%7D',
            GetQuery::encode('json', '{"text":"a+b/c"}', null),
        );
    }

    #[Test]
    public function encodeCompressed(): void
    {
        $query = GetQuery::encode('json', (string) gzencode('{"text":"a"}'), 'gzip');

        self::assertStringContainsString('base64=1&compression=gzip', $query);
        self::assertStringNotContainsString('=', substr($query, (int) strpos($query, 'message=') + 8));
    }

    #[Test]
    public function encodeUsesUrlSafeAlphabet(): void
    {
        self::assertSame('connect=v1&base64=1&encoding=proto&message=-_8', GetQuery::encode('proto', "\xfb\xff", null));
    }

    #[Test]
    public function parseAnyOrderAndUnknownKeys(): void
    {
        self::assertSame(
            ['encoding' => 'proto', 'message' => "\xfb\xff", 'compression' => 'gzip'],
            GetQuery::parse(['message' => '-_8', 'x' => 'y', 'compression' => 'gzip', 'base64' => '1', 'encoding' => 'proto']),
        );
        self::assertSame(
            ['encoding' => 'json', 'message' => '{}', 'compression' => null],
            GetQuery::parse(['encoding' => 'json', 'message' => '{}', 'compression' => ['gzip']]),
        );
    }

    #[Test]
    public function parseAcceptsPercentEncodedPadding(): void
    {
        self::assertSame("\x0a\x03abc", GetQuery::parse(['encoding' => 'proto', 'base64' => '1', 'message' => 'CgNhYmM='])['message']);
    }

    #[Test]
    public function parseToleratesStandardAlphabet(): void
    {
        self::assertSame("\xfb\xff", GetQuery::parse(['encoding' => 'proto', 'base64' => '1', 'message' => '+/8='])['message']);
    }

    #[Test]
    public function parseMissingEncodingIsInvalidArgument(): void
    {
        foreach ([['message' => 'x'], ['encoding' => 'json'], ['encoding' => 'json', 'message' => ['x']]] as $query) {
            try {
                GetQuery::parse($query);
                self::fail('expected an exception');
            } catch (ConnectException $e) {
                self::assertSame(Code::InvalidArgument, $e->code());
            }
        }
    }

    #[Test]
    public function parseBadBase64IsInvalidArgument(): void
    {
        $this->expectExceptionObject(new ConnectException(Code::InvalidArgument, 'invalid base64 in message query parameter'));

        GetQuery::parse(['encoding' => 'proto', 'base64' => '1', 'message' => '!!']);
    }
}
