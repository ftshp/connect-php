<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Headers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'space' => ['Bad Name'],
            'at sign' => ['x@y'],
            'empty' => [''],
            'connect prefix' => ['Connect-Protocol-Version'],
        ];
    }

    #[Test]
    public function namesAreCaseInsensitive(): void
    {
        $headers = new Headers();
        $headers->set('X-Token', 'abc');

        self::assertSame('abc', $headers->get('x-token'));
        self::assertSame('abc', $headers->get('X-TOKEN'));
    }

    #[Test]
    public function addKeepsMultipleValues(): void
    {
        $headers = new Headers();
        $headers->add('x-a', '1');
        $headers->add('X-A', '2');

        self::assertSame(['1', '2'], $headers->all('x-a'));
        self::assertSame('1', $headers->get('x-a'));
    }

    #[Test]
    public function setReplacesExistingValues(): void
    {
        $headers = new Headers(['x-a' => ['1', '2']]);
        $headers->set('x-a', '3');

        self::assertSame(['3'], $headers->all('x-a'));
    }

    #[Test]
    public function missingHeaderYieldsNullAndEmptyList(): void
    {
        $headers = new Headers();

        self::assertNull($headers->get('x-a'));
        self::assertSame([], $headers->all('x-a'));
        self::assertTrue($headers->isEmpty());
    }

    #[Test]
    public function binaryValuesAreBase64OnTheWire(): void
    {
        $headers = new Headers();
        $headers->set('meta-bin', "\x00\xff");

        self::assertSame("\x00\xff", $headers->get('meta-bin'));
        self::assertSame(['meta-bin' => ['AP8']], $headers->toArray());
        self::assertSame("\x00\xff", Headers::fromPsr(['Meta-Bin' => ['AP8=']])->get('meta-bin'));
        self::assertSame("\x00\xff", Headers::fromPsr(['Meta-Bin' => ['AP8']])->get('meta-bin'));
    }

    #[Test]
    public function invalidBase64InFromPsrIsKeptAsIs(): void
    {
        self::assertSame('***', Headers::fromPsr(['x-bin' => ['***']])->get('x-bin'));
    }

    #[Test]
    public function binaryZeroCharacterDecodes(): void
    {
        self::assertSame('0', Headers::fromPsr(['x-bin' => ['MA']])->get('x-bin'));
    }

    #[Test]
    public function constructorAcceptsStringsAndLists(): void
    {
        $headers = new Headers(['X-A' => '1', 'X-B' => ['2', '3']]);

        self::assertSame(['x-a' => ['1'], 'x-b' => ['2', '3']], $headers->toArray());
    }

    #[Test]
    public function constructorValidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Headers(['bad name' => 'x']);
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function rejectsInvalidNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Headers()->set($name, 'x');
    }

    #[Test]
    public function addRejectsConnectPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Headers()->add('connect-timeout-ms', '1');
    }

    #[Test]
    public function rejectsCrLfInValues(): void
    {
        $headers = new Headers();

        foreach (["a\r\nb", "a\nb", "a\rb"] as $value) {
            try {
                $headers->set('x-a', $value);
                self::fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                self::assertTrue($headers->isEmpty());
            }
        }
    }

    #[Test]
    public function fromPsrDoesNotValidate(): void
    {
        $headers = Headers::fromPsr(['Sec-CH-UA' => ['"x"'], 'Connect-Protocol-Version' => ['1']]);

        self::assertSame('"x"', $headers->get('sec-ch-ua'));
        self::assertSame('1', $headers->get('connect-protocol-version'));
    }

    #[Test]
    public function removeAndHas(): void
    {
        $headers = new Headers(['x-a' => '1']);

        self::assertTrue($headers->has('X-A'));
        $headers->remove('X-A');
        self::assertFalse($headers->has('x-a'));
        self::assertTrue($headers->isEmpty());
    }

    #[Test]
    public function numericNamesWork(): void
    {
        $headers = new Headers();
        $headers->set('123', 'x');
        $headers->add('123', 'y');

        self::assertSame('x', $headers->get('123'));
        self::assertSame(['x', 'y'], $headers->all('123'));
        self::assertTrue($headers->has('123'));
        self::assertSame(['x', 'y'], $headers->toArray()[123]);

        $headers->remove('123');
        self::assertFalse($headers->has('123'));

        self::assertSame('x', Headers::fromPsr([123 => ['x']])->get('123'));
    }
}
