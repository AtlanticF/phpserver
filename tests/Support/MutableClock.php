<?php

declare(strict_types=1);

namespace PhpServer\Tests\Support;

use PhpServer\Support\ClockInterface;

/**
 * Clock whose wall time and monotonic time move only when a test says so.
 */
final class MutableClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-16T00:00:00+00:00'),
        private int $monotonic = 0,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function monotonic(): int
    {
        return $this->monotonic;
    }

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advanceMonotonic(int $nanoseconds): void
    {
        if ($nanoseconds < 0) {
            throw new \InvalidArgumentException('nanoseconds must be >= 0');
        }
        $this->monotonic += $nanoseconds;
    }
}
