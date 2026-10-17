<?php

declare(strict_types=1);

namespace PhpServer\Event;

use PhpServer\Log\LoggerInterface;
use PhpServer\Support\ClockInterface;

/**
 * `stream_select` event loop.
 *
 * Tick order:
 * 1. dispatch signals queued by the process handler
 * 2. run due timers (one monotonic read for the whole pass)
 * 3. return if {@see self::stop()} was called
 * 4. return if nothing is watched
 * 5. wait until the next timer, or block
 * 6. with no streams, sleep; never call `stream_select` on empty sets
 * 7. `stream_select` on copies of the stream sets
 * 8. readable callbacks, then writable callbacks
 *
 * A timer created during step 2 waits until the next tick. `stop()` from a
 * stream callback finishes the current I/O dispatch, then one more timer pass,
 * then returns. Callback exceptions propagate out of {@see self::run()}.
 *
 * @internal
 */
final class SelectLoop implements LoopInterface
{
    private const int FD_SETSIZE = 1024;

    private const int SIGNAL_ONLY_SLEEP_SECONDS = 1;

    private readonly TimerQueue $timers;

    private readonly int $slowThresholdNs;

    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, \Closure(resource): void> */
    private array $readCallbacks = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, \Closure(resource): void> */
    private array $writeCallbacks = [];

    /** @var array<int, \Closure(int): void> */
    private array $signalCallbacks = [];

    /** @var list<int> */
    private array $pendingSignals = [];

    private int $nextTimerId = 0;

    private bool $running = false;

    private bool $stop = false;

    private bool $asyncSignals = false;

    private bool $fdLimitWarned = false;

    private int $ticks = 0;

    private int $callbacksRun = 0;

    private int $slowestCallbackNs = 0;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
        float $slowCallbackThreshold = 0.1,
    ) {
        if (!is_finite($slowCallbackThreshold) || $slowCallbackThreshold < 0) {
            throw new \InvalidArgumentException('slow callback threshold must be >= 0');
        }
        $this->slowThresholdNs = $this->secondsToNs($slowCallbackThreshold);
        $this->timers = new TimerQueue();
    }

    public function onReadable(mixed $stream, \Closure $callback): void
    {
        $stream = $this->requireOpenStream($stream);
        $id = (int) $stream;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
        $this->warnFdLimit();
    }

    public function onWritable(mixed $stream, \Closure $callback): void
    {
        $stream = $this->requireOpenStream($stream);
        $id = (int) $stream;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
        $this->warnFdLimit();
    }

    public function offReadable(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        $id = (int) $stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    public function offWritable(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        $id = (int) $stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    public function removeStream(mixed $stream): void
    {
        $this->offReadable($stream);
        $this->offWritable($stream);
    }

    public function addTimer(float $seconds, \Closure $callback): int
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new \InvalidArgumentException('timer delay must be >= 0');
        }

        return $this->schedule($this->secondsToNs($seconds), 0, $callback);
    }

    public function addPeriodicTimer(float $seconds, \Closure $callback): int
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('periodic timer interval must be > 0');
        }
        $intervalNs = $this->secondsToNs($seconds);

        return $this->schedule($intervalNs, $intervalNs, $callback);
    }

    public function cancelTimer(int $timerId): void
    {
        $this->timers->cancel($timerId);
    }

    public function onSignal(int $signo, \Closure $callback): void
    {
        if (!$this->asyncSignals) {
            pcntl_async_signals(true);
            if (pcntl_async_signals() !== true) {
                throw new \RuntimeException('pcntl_async_signals failed');
            }
            $this->asyncSignals = true;
        }
        $installed = pcntl_signal($signo, function (int $signo): void {
            $this->pendingSignals[] = $signo;
        });
        if ($installed !== true) {
            throw new \InvalidArgumentException('cannot install handler for signal ' . $signo);
        }
        $this->signalCallbacks[$signo] = $callback;
    }

    public function offSignal(int $signo): void
    {
        unset($this->signalCallbacks[$signo]);
        pcntl_signal($signo, SIG_DFL);
    }

    public function run(): void
    {
        if ($this->running) {
            throw new \LogicException('event loop is already running');
        }
        $this->running = true;
        $this->stop = false;
        try {
            while (true) {
                ++$this->ticks;
                $this->dispatchSignals();
                $nowNs = $this->clock->monotonic();
                $this->timers->dispatchDue($nowNs, function (Timer $timer): void {
                    $this->timeCall($timer->callback, function () use ($timer): void {
                        ($timer->callback)($timer->id);
                    });
                });
                if ($this->stop) {
                    break;
                }
                if (
                    $this->readStreams === []
                    && $this->writeStreams === []
                    && $this->timers->count() === 0
                    && $this->signalCallbacks === []
                ) {
                    break;
                }
                $this->wait($this->clock->monotonic());
            }
        } finally {
            $this->running = false;
        }
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    public function metrics(): LoopMetrics
    {
        return new LoopMetrics(
            $this->ticks,
            count($this->readStreams),
            count($this->writeStreams),
            $this->timers->count(),
            $this->callbacksRun,
            $this->slowestCallbackNs,
        );
    }

    private function schedule(int $delayNs, int $intervalNs, \Closure $callback): int
    {
        $id = ++$this->nextTimerId;
        $this->timers->add(new Timer(
            $id,
            $this->addNs($this->clock->monotonic(), $delayNs),
            $intervalNs,
            $callback,
        ));

        return $id;
    }

    private function dispatchSignals(): void
    {
        while ($this->pendingSignals !== []) {
            $signo = array_shift($this->pendingSignals);
            $callback = $this->signalCallbacks[$signo] ?? null;
            if ($callback !== null) {
                $this->timeCall($callback, function () use ($callback, $signo): void {
                    $callback($signo);
                });
            }
        }
    }

    private function wait(int $nowNs): void
    {
        $timeout = $this->timeout($nowNs);
        if ($this->readStreams === [] && $this->writeStreams === []) {
            $this->logger?->debug('event loop tick mode={mode}', ['mode' => 'sleep']);
            $this->sleep($timeout);

            return;
        }

        $this->logger?->debug('event loop tick mode={mode}', ['mode' => 'select']);
        $read = array_values($this->readStreams);
        $write = array_values($this->writeStreams);
        if ($read === [] && $write === []) {
            throw new \LogicException('stream_select requires a stream');
        }
        $ready = $this->select($read, $write, $timeout);
        if ($ready === null) {
            return;
        }
        $this->dispatchReady($ready[0], true);
        $this->dispatchReady($ready[1], false);
    }

    /**
     * @return array{seconds: ?int, microseconds: int}
     */
    private function timeout(int $nowNs): array
    {
        $next = $this->timers->nextDueNs();
        if ($next === null) {
            return ['seconds' => null, 'microseconds' => 0];
        }
        $waitNs = max(0, $next - $nowNs);
        $seconds = intdiv($waitNs, 1_000_000_000);
        $remainder = $waitNs % 1_000_000_000;
        $microseconds = intdiv($remainder + 999, 1_000);
        if ($microseconds === 1_000_000) {
            ++$seconds;
            $microseconds = 0;
        }

        return ['seconds' => $seconds, 'microseconds' => $microseconds];
    }

    /**
     * @param array{seconds: ?int, microseconds: int} $timeout
     */
    private function sleep(array $timeout): void
    {
        if ($timeout['seconds'] === null) {
            time_nanosleep(self::SIGNAL_ONLY_SLEEP_SECONDS, 0);

            return;
        }
        $nanoseconds = $timeout['microseconds'] * 1_000;
        if ($timeout['seconds'] === 0 && $nanoseconds === 0) {
            return;
        }
        time_nanosleep($timeout['seconds'], $nanoseconds);
    }

    /**
     * @param list<resource> $read
     * @param list<resource> $write
     * @param array{seconds: ?int, microseconds: int} $timeout
     *
     * @return array{0: list<resource>, 1: list<resource>}|null
     */
    private function select(array $read, array $write, array $timeout): ?array
    {
        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $except = null;
            $result = stream_select(
                $read,
                $write,
                $except,
                $timeout['seconds'],
                $timeout['microseconds'],
            );
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            $reason = is_string($error) ? $error : 'unknown error';
            if ($this->isInterrupted($reason)) {
                return null;
            }
            $this->logger?->error('stream_select failed: {reason}', ['reason' => $reason]);
            throw new \RuntimeException('stream_select failed: ' . $reason);
        }
        if ($result === 0) {
            return null;
        }

        return [$this->resourcesOf($read), $this->resourcesOf($write)];
    }

    /**
     * @param list<resource> $ready
     */
    private function dispatchReady(array $ready, bool $readable): void
    {
        $readyIds = [];
        foreach ($ready as $stream) {
            if (is_resource($stream)) {
                $readyIds[(int) $stream] = true;
            }
        }
        $ids = array_keys($readable ? $this->readCallbacks : $this->writeCallbacks);
        foreach ($ids as $id) {
            if (!isset($readyIds[$id])) {
                continue;
            }
            $callback = $readable ? ($this->readCallbacks[$id] ?? null) : ($this->writeCallbacks[$id] ?? null);
            $stream = $readable ? ($this->readStreams[$id] ?? null) : ($this->writeStreams[$id] ?? null);
            if ($callback === null || !is_resource($stream)) {
                continue;
            }
            $this->timeCall($callback, function () use ($callback, $stream): void {
                $callback($stream);
            });
        }
    }

    /**
     * @param \Closure(): void $run
     */
    private function timeCall(\Closure $callback, \Closure $run): void
    {
        $started = $this->clock->monotonic();
        try {
            $run();
        } finally {
            $elapsed = $this->clock->monotonic() - $started;
            if ($elapsed < 0) {
                $elapsed = 0;
            }
            ++$this->callbacksRun;
            if ($elapsed > $this->slowestCallbackNs) {
                $this->slowestCallbackNs = $elapsed;
            }
            if ($elapsed > $this->slowThresholdNs) {
                $this->logger?->warning('slow event-loop callback {callback} took {seconds}s', [
                    'callback' => $this->describe($callback),
                    'seconds' => $elapsed / 1_000_000_000,
                ]);
            }
        }
    }

    private function describe(\Closure $callback): string
    {
        $reflection = new \ReflectionFunction($callback);
        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();
        if (!is_string($file) || $file === '') {
            $file = 'closure';
        }

        return $file . ':' . (is_int($line) ? (string) $line : '0');
    }

    private function warnFdLimit(): void
    {
        if ($this->fdLimitWarned) {
            return;
        }
        $watched = count($this->readStreams + $this->writeStreams);
        if ($watched <= self::FD_SETSIZE) {
            return;
        }
        $this->fdLimitWarned = true;
        $this->logger?->warning('watched file descriptors exceed FD_SETSIZE ({limit})', [
            'limit' => self::FD_SETSIZE,
            'count' => $watched,
        ]);
    }

    /**
     * @return resource
     */
    private function requireOpenStream(mixed $stream): mixed
    {
        if (!is_resource($stream) || get_resource_type($stream) === 'Unknown') {
            throw new \InvalidArgumentException('stream must be an open resource');
        }

        return $stream;
    }

    /**
     * @param array<mixed> $streams
     *
     * @return list<resource>
     */
    private function resourcesOf(array $streams): array
    {
        $resources = [];
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                $resources[] = $stream;
            }
        }

        return $resources;
    }

    private function isInterrupted(string $message): bool
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'interrupted system call')) {
            return true;
        }

        return str_contains($lower, 'unable to select [' . SOCKET_EINTR . ']');
    }

    private function secondsToNs(float $seconds): int
    {
        $nanoseconds = round($seconds * 1_000_000_000);
        if (!is_finite($nanoseconds) || $nanoseconds > PHP_INT_MAX || $nanoseconds < 0) {
            throw new \InvalidArgumentException('timer delay is too large');
        }

        return (int) $nanoseconds;
    }

    private function addNs(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \InvalidArgumentException('timer deadline overflows');
        }

        return $left + $right;
    }
}
