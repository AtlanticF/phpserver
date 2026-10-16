<?php

declare(strict_types=1);

namespace PhpServer\Support;

/**
 * Wall clock plus a monotonic clock.
 *
 * Use {@see self::now()} only for log timestamps. Use {@see self::monotonic()}
 * for timeouts, intervals, and rate limits so a wall-clock step cannot
 * stretch or skip them.
 *
 * @internal
 */
interface ClockInterface
{
    /**
     * Current wall time. The timezone is the clock's own; callers convert for display.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Monotonic nanoseconds from an arbitrary origin (`hrtime(true)`).
     *
     * The value is comparable only with other values from the same clock.
     * It is not Unix time.
     */
    public function monotonic(): int;
}
