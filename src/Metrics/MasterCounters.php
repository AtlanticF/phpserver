<?php

declare(strict_types=1);

namespace PhpServer\Metrics;

/**
 * Counters owned by the master process.
 *
 * `worker_restarts` counts a replacement fork after a worker exits.
 * `drain_timeout` counts a shutdown that had to SIGKILL a worker still
 * running after the drain deadline. `forced_kill` counts a heartbeat miss.
 * Those two are not the same event. Nothing here renders Prometheus text.
 *
 * @internal
 */
final class MasterCounters
{
    private int $workerRestarts = 0;

    private int $drainTimeout = 0;

    private int $forcedKill = 0;

    public function incrementWorkerRestarts(): void
    {
        ++$this->workerRestarts;
    }

    public function incrementDrainTimeout(): void
    {
        ++$this->drainTimeout;
    }

    public function incrementForcedKill(): void
    {
        ++$this->forcedKill;
    }

    public function get(string $name): int
    {
        return match ($name) {
            'worker_restarts' => $this->workerRestarts,
            'drain_timeout' => $this->drainTimeout,
            'forced_kill' => $this->forcedKill,
            default => throw new \InvalidArgumentException('unknown counter "' . $name . '"'),
        };
    }

    /**
     * @return array{worker_restarts: int, drain_timeout: int, forced_kill: int}
     */
    public function snapshot(): array
    {
        return [
            'worker_restarts' => $this->workerRestarts,
            'drain_timeout' => $this->drainTimeout,
            'forced_kill' => $this->forcedKill,
        ];
    }
}
