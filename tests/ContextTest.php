<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Context;
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
    public function startTimerSetsDeadline(): void
    {
        $context = new Context(timeoutMs: 50);
        $context->startTimer();

        self::assertNotNull($context->deadline);
        $remaining = $context->remainingMs();
        self::assertNotNull($remaining);
        self::assertGreaterThanOrEqual(0, $remaining);
        self::assertLessThanOrEqual(50, $remaining);
    }

    #[Test]
    public function startTimerWithoutTimeoutLeavesDeadlineUnset(): void
    {
        $context = new Context();
        $context->startTimer();

        self::assertNull($context->deadline);
    }

    #[Test]
    public function remainingMsNeverNegative(): void
    {
        $context = new Context();
        $context->deadline = microtime(true) - 10;

        self::assertSame(0, $context->remainingMs());
    }
}
