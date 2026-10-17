<?php

declare(strict_types=1);

namespace PhpServer\Event;

/**
 * Single-threaded event loop: streams, timers, and signals.
 *
 * Stream arguments are resources. PHP has no `resource` parameter type, so the
 * signature uses `mixed` and rejects anything else.
 * Callbacks are closures, not `callable`, so they are not bound to an object
 * method that could change under the loop. Re-registering a stream replaces
 * the previous callback. `off*` and `cancelTimer` are idempotent and do not
 * close streams or throw when the id is unknown.
 *
 * @api
 */
interface LoopInterface
{
    /**
     * Watch a stream for readability. A second registration replaces the first.
     *
     * @param resource                  $stream
     * @param \Closure(resource): void  $callback
     */
    public function onReadable(mixed $stream, \Closure $callback): void;

    /**
     * Watch a stream for writability. A second registration replaces the first.
     *
     * @param resource                  $stream
     * @param \Closure(resource): void  $callback
     */
    public function onWritable(mixed $stream, \Closure $callback): void;

    /**
     * Stop watching a stream for readability. Does not close it.
     *
     * @param resource $stream
     */
    public function offReadable(mixed $stream): void;

    /**
     * Stop watching a stream for writability. Does not close it.
     *
     * @param resource $stream
     */
    public function offWritable(mixed $stream): void;

    /**
     * Stop watching a stream for both readability and writability.
     *
     * The caller closes the stream after this returns.
     *
     * @param resource $stream
     */
    public function removeStream(mixed $stream): void;

    /**
     * Run `$callback` once after `$seconds`. `0` means the next timer pass.
     *
     * The id starts at 1 and is never reused.
     *
     * @param \Closure(int): void $callback receives the timer id
     *
     * @throws \InvalidArgumentException when $seconds is negative or not finite
     */
    public function addTimer(float $seconds, \Closure $callback): int;

    /**
     * Run `$callback` repeatedly. The first fire is one interval from now.
     *
     * @param \Closure(int): void $callback receives the timer id
     *
     * @throws \InvalidArgumentException when $seconds is not greater than 0
     */
    public function addPeriodicTimer(float $seconds, \Closure $callback): int;

    /**
     * Cancel a timer. Safe to call from that timer's own callback.
     */
    public function cancelTimer(int $timerId): void;

    /**
     * Handle a signal. One callback per signal number; the latest call wins.
     *
     * The process signal handler only records the signal. The callback runs
     * on the loop thread at the start of a tick.
     *
     * @param \Closure(int): void $callback receives the signal number
     */
    public function onSignal(int $signo, \Closure $callback): void;

    /**
     * Drop the callback and restore `SIG_DFL` for `$signo`.
     */
    public function offSignal(int $signo): void;

    /**
     * Run until `stop()`, until a callback throws, or until nothing is watched.
     *
     * @throws \LogicException   when a loop is already running
     * @throws \RuntimeException when `stream_select` fails for a reason other than EINTR
     */
    public function run(): void;

    /**
     * Ask `run()` to return. The current tick finishes first.
     */
    public function stop(): void;
}
