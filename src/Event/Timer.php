<?php

declare(strict_types=1);

namespace PhpServer\Event;

/**
 * One timer. `$dueNs` is an absolute monotonic deadline in nanoseconds.
 *
 * `$intervalNs` is 0 for a one-shot. Periodic timers keep the same object and
 * move `$dueNs` forward; the id never changes.
 *
 * @internal
 */
final class Timer
{
    /**
     * @param \Closure(int): void $callback
     */
    public function __construct(
        public readonly int $id,
        public int $dueNs,
        public readonly int $intervalNs,
        public readonly \Closure $callback,
    ) {}

    public function isPeriodic(): bool
    {
        return $this->intervalNs > 0;
    }
}
