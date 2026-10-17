<?php

declare(strict_types=1);

namespace PhpServer\Event;

/**
 * Min-heap of timer deadlines plus an id table.
 *
 * The table is the source of truth. Cancel removes the id in O(1) and leaves
 * the heap entry behind. Entries whose id is gone, or whose deadline does not
 * match the table, are discarded when they reach the top.
 *
 * @internal
 */
final class TimerQueue
{
    /** @var array<int, Timer> */
    private array $table = [];

    /** @var \SplMinHeap<array{0: int, 1: int}> */
    private \SplMinHeap $heap;

    private bool $dispatching = false;

    /** @var array<int, true> */
    private array $skip = [];

    public function __construct()
    {
        $this->heap = new class extends \SplMinHeap {
            protected function compare(mixed $value1, mixed $value2): int
            {
                if (!is_array($value1) || !is_array($value2)) {
                    return 0;
                }
                $due1 = $value1[0] ?? 0;
                $due2 = $value2[0] ?? 0;
                $id1 = $value1[1] ?? 0;
                $id2 = $value2[1] ?? 0;
                if (!is_int($due1) || !is_int($due2) || !is_int($id1) || !is_int($id2)) {
                    return 0;
                }

                // SplMinHeap treats a positive result as "value1 is lower".
                return $due2 <=> $due1 ?: $id2 <=> $id1;
            }
        };
    }

    public function add(Timer $timer): void
    {
        $this->table[$timer->id] = $timer;
        $this->heap->insert([$timer->dueNs, $timer->id]);
    }

    /**
     * Drop a timer. Unknown ids do nothing. Safe while that timer's callback runs.
     */
    public function cancel(int $id): void
    {
        unset($this->table[$id]);
        if ($this->dispatching) {
            $this->skip[$id] = true;
        }
    }

    /**
     * Earliest live deadline, or null when no timers remain.
     */
    public function nextDueNs(): ?int
    {
        return $this->peekValid()?->dueNs;
    }

    /**
     * Remove every live timer with `dueNs <= $nowNs` from the table and the heap.
     *
     * The returned objects are detached. A periodic timer must be `add()`ed again
     * after its deadline moves. Timers inserted after this call are not included,
     * so a zero-delay timer created while the list is processed waits for the
     * next pass.
     *
     * @return list<Timer>
     */
    public function popDue(int $nowNs): array
    {
        $due = [];
        while (true) {
            $timer = $this->peekValid();
            if ($timer === null || $timer->dueNs > $nowNs) {
                break;
            }
            $this->heap->extract();
            unset($this->table[$timer->id]);
            $due[] = $timer;
        }

        return $due;
    }

    /**
     * Fire every timer due at `$nowNs`.
     *
     * Periodic timers are rescheduled before their callback, from the planned
     * deadline, and catch up at most one interval (`max(due + interval, now)`).
     * State for the timer being invoked is updated first. If the callback throws,
     * timers later in this pass are put back so they can run on a later pass.
     *
     * @param \Closure(Timer): void $invoke
     */
    public function dispatchDue(int $nowNs, \Closure $invoke): void
    {
        $batch = $this->popDue($nowNs);
        $index = 0;
        $this->dispatching = true;
        $this->skip = [];
        try {
            foreach ($batch as $timer) {
                if (isset($this->skip[$timer->id])) {
                    ++$index;
                    continue;
                }
                if ($timer->isPeriodic()) {
                    $timer->dueNs = max($this->addNs($timer->dueNs, $timer->intervalNs), $nowNs);
                    $this->add($timer);
                }
                ++$index;
                $invoke($timer);
            }
        } finally {
            for ($i = $index, $count = count($batch); $i < $count; ++$i) {
                $pending = $batch[$i];
                if (!isset($this->skip[$pending->id])) {
                    $this->add($pending);
                }
            }
            $this->dispatching = false;
            $this->skip = [];
        }
    }

    public function count(): int
    {
        return count($this->table);
    }

    private function peekValid(): ?Timer
    {
        while (!$this->heap->isEmpty()) {
            $entry = $this->heap->top();
            $timer = $this->table[$entry[1]] ?? null;
            if ($timer === null || $timer->dueNs !== $entry[0]) {
                $this->heap->extract();
                continue;
            }

            return $timer;
        }

        return null;
    }

    private function addNs(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            return PHP_INT_MAX;
        }

        return $left + $right;
    }
}
