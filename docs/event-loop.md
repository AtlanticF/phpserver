# Event loop

M2 is a single-threaded `stream_select` loop for the workers that come later. The public contract is `PhpServer\Event\LoopInterface`. The implementation is `SelectLoop`. Pass M1's `ClockInterface` to the constructor. A logger is optional.

```php
$loop = new SelectLoop(new SystemClock(), $logger);
$loop->onReadable($client, $this->onRead(...));
$loop->addTimer(0.0, function (int $id): void {
    // Runs on the next timer pass. 0 means the next tick, not a busy wait.
});
$loop->run();
```

Every callback is a `\Closure`, not a `callable`. Calling `onReadable` or `onWritable` again for the same stream replaces the previous callback. It does not stack another one. A repeated `off*`, `removeStream`, or `cancelTimer` does nothing and does not close the stream. The caller `removeStream()`s first, then `fclose()`s.

## Order inside one tick

1. Drain signals that are already queued. The `pcntl_signal` handler only pushes the signal number onto a queue, and turns on `pcntl_async_signals(true)`. The callback runs on the loop thread.
2. Run every timer that is due, using one monotonic reading for the tick. A periodic timer updates its next due time before the callback runs. Catch-up is at most one firing: `due = max(due + interval, now)`, measured from the scheduled time, not from "now + interval".
3. If `stop()` was called, the tick ends here and `run()` returns.
4. If nothing is registered (no read stream, write stream, timer, or signal handler), `run()` returns on its own.
5. Compute how long to wait for the next timer. With no timer, block until something else wakes the process.
6. When both the read set and the write set are empty, sleep with `time_nanosleep` until the next timer. If there is only a signal and no timer, sleep at most 1 second and then look at the queue again. This path does not call `stream_select`, and it does not park a placeholder fd (the old implementation crashed on PHP 8).
7. Otherwise `stream_select` the copies of the two sets. `EINTR` quietly starts the next tick. Any other failure logs an error and throws `RuntimeException`.
8. Read callbacks first, then write callbacks. Within one direction, callbacks run in registration order. Before each call the loop looks the stream up again by `(int) $stream`. If an earlier callback in the same tick already `removeStream()`d it, the later callback is skipped.

`stop()` only sets a flag. Called from a timer callback, `run()` returns after the current batch of timers. Called from a stream callback, the current read and write callbacks still finish, the next tick runs timers once more, and then `run()` returns. `run()` can be called again after it returns.

An exception thrown from a callback is not swallowed. It propagates out of `run()`. Before it propagates, that timer's own state is already updated (a one-shot timer is removed, a periodic timer is scheduled again).

## Time

Delays and intervals in the public API are seconds (`float`). `0` means the timer pass of the next tick. A periodic interval must be greater than 0. A negative or non-finite number throws `InvalidArgumentException`.

Internally everything is integer nanoseconds from `ClockInterface::monotonic()` (`hrtime`). Timer ids start at 1 and are not reused.

## Signals

Each signal number has one callback. A later registration replaces the earlier one. `offSignal()` drops the callback and restores `SIG_DFL`. `pcntl_async_signals(true)` is process-wide. The first signal registration turns it on, and it stays on. The integration test registers `SIGTERM` in a child, calls `stop()` from the callback, and the parent then sends the signal.

## Other

- If one callback's monotonic time exceeds the constructor argument `slowCallbackThreshold` (default 0.1 seconds), the loop logs a warning with the closure's file and line.
- When the number of watched streams (reads and writes deduped by id) exceeds 1024 (`FD_SETSIZE`), the loop logs one warning.
- `SelectLoop::metrics()` returns `LoopMetrics`: tick count, current read/write/timer counts, callbacks already run, and the slowest callback in nanoseconds. This method is not on `LoopInterface`.
