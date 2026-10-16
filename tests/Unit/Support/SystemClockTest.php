<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Support;

use PhpServer\Support\SystemClock;
use PhpServer\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testWallClockIsCloseToUnixTime(): void
    {
        $now = (new SystemClock())->now();

        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 5);
    }

    public function testMonotonicDoesNotGoBackwards(): void
    {
        $clock = new SystemClock();
        $first = $clock->monotonic();
        $second = $clock->monotonic();

        self::assertGreaterThan(0, $first);
        self::assertGreaterThanOrEqual($first, $second);
    }

    public function testWallMoveDoesNotChangeMonotonicTime(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-16T00:00:00+00:00'), 100);
        $before = $clock->monotonic();

        $clock->setNow(new \DateTimeImmutable('2030-01-01T00:00:00+00:00'));
        $clock->advanceMonotonic(50);

        self::assertSame('2030-01-01T00:00:00+00:00', $clock->now()->format(\DateTimeInterface::ATOM));
        self::assertSame($before + 50, $clock->monotonic());
    }
}
