<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Context;
use Ftshp\Connect\ServiceDescriptor;
use Ftshp\Connect\Test\EchoInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    #[Test]
    public function remainingMsWithoutDeadlineIsNull(): void
    {
        self::assertNull((new Context())->remainingMs());
    }

    #[Test]
    public function readingMethodBeforeACallStartsThrows(): void
    {
        try {
            (new Context())->method;
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertSame('no call has started with this Context', $e->getMessage());
        }
    }

    #[Test]
    public function beginSetsMethodAndDeadline(): void
    {
        $method = ServiceDescriptor::fromInterface(EchoInterface::class)->methods['Echo'];
        $context = new Context(timeoutMs: 50);
        $context->begin($method);

        self::assertSame($method, $context->method);
        self::assertNotNull($context->deadline);
        $remaining = $context->remainingMs();
        self::assertNotNull($remaining);
        self::assertGreaterThanOrEqual(0, $remaining);
        self::assertLessThanOrEqual(50, $remaining);
    }

    #[Test]
    public function beginWithoutTimeoutLeavesDeadlineUnset(): void
    {
        $context = new Context();
        $context->begin(ServiceDescriptor::fromInterface(EchoInterface::class)->methods['Echo']);

        self::assertNull($context->deadline);
    }

    #[Test]
    public function remainingMsNeverNegative(): void
    {
        $context = new Context(timeoutMs: 1);
        $context->begin(ServiceDescriptor::fromInterface(EchoInterface::class)->methods['Echo']);
        usleep(5000);

        self::assertSame(0, $context->remainingMs());
    }
}
