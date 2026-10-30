<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Attribute\Procedure;
use Ftshp\Connect\Attribute\Service;
use Ftshp\Connect\Context;
use Ftshp\Connect\Idempotency;
use Ftshp\Connect\ServiceDescriptor;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

interface WithoutServiceAttribute {}

#[Service('x.NoProcedure')]
interface MethodWithoutProcedure
{
    public function echo(EchoRequest $request, Context $context): EchoResponse;
}

#[Service('x.ScalarParameter')]
interface ScalarParameter
{
    #[Procedure('Echo')]
    public function echo(string $request, Context $context): EchoResponse;
}

#[Service('x.NonMessageReturn')]
interface NonMessageReturn
{
    #[Procedure('Echo')]
    public function echo(EchoRequest $request, Context $context): \stdClass;
}

#[Service('x.WrongContext')]
interface WrongContext
{
    #[Procedure('Echo')]
    public function echo(EchoRequest $request, EchoRequest $context): EchoResponse;
}

#[Service('x.NotAnInterface')]
final class NotAnInterface {}

final class ServiceDescriptorTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function invalidServices(): array
    {
        return [
            'not an interface' => [NotAnInterface::class],
            'missing service attribute' => [WithoutServiceAttribute::class],
            'missing procedure attribute' => [MethodWithoutProcedure::class],
            'scalar parameter' => [ScalarParameter::class],
            'non-message return' => [NonMessageReturn::class],
            'wrong context parameter' => [WrongContext::class],
        ];
    }

    #[Test]
    public function fromInterfaceReadsAttributes(): void
    {
        $service = ServiceDescriptor::fromInterface(EchoInterface::class);

        self::assertSame('ftshp.connect.test.Echo', $service->name);
        self::assertSame(EchoInterface::class, $service->interface);
        self::assertSame(['Echo', 'Peek', 'Fail'], array_keys($service->methods));
        self::assertSame(Idempotency::Unknown, $service->methods['Echo']->idempotency);

        $peek = $service->methods['Peek'];
        self::assertSame('ftshp.connect.test.Echo', $peek->service);
        self::assertSame('Peek', $peek->name);
        self::assertSame('peek', $peek->phpName);
        self::assertSame(Idempotency::NoSideEffects, $peek->idempotency);
        self::assertSame(EchoRequest::class, $peek->input);
        self::assertSame(EchoResponse::class, $peek->output);
        self::assertSame('/ftshp.connect.test.Echo/Peek', $peek->procedure());
    }

    #[Test]
    public function fromInterfaceIsMemoised(): void
    {
        self::assertSame(
            ServiceDescriptor::fromInterface(EchoInterface::class),
            ServiceDescriptor::fromInterface(EchoInterface::class),
        );
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('invalidServices')]
    public function rejectsInvalidService(string $class): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServiceDescriptor::fromInterface($class);
    }
}
