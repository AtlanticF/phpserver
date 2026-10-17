<?php

declare(strict_types=1);

namespace PhpServer\Event;

/**
 * Point-in-time counters for one event loop.
 *
 * `readStreams`, `writeStreams`, and `timers` are current sizes.
 * `ticks`, `callbacksRun`, and `slowestCallbackNs` accumulate until the
 * process drops the loop.
 *
 * @internal
 */
final readonly class LoopMetrics
{
    public function __construct(
        public int $ticks,
        public int $readStreams,
        public int $writeStreams,
        public int $timers,
        public int $callbacksRun,
        public int $slowestCallbackNs,
    ) {}
}
