<?php

declare(strict_types=1);

namespace PhpServer\Support;

/**
 * Clock backed by the operating system.
 *
 * @internal
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    public function monotonic(): int
    {
        $nanoseconds = hrtime(true);
        if (!is_int($nanoseconds)) {
            // hrtime(true) is specified to return int. Refuse a non-int rather than guess.
            throw new \RuntimeException('hrtime(true) did not return an int');
        }

        return $nanoseconds;
    }
}
