<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Event;

use PhpServer\Event\Timer;
use PhpServer\Event\TimerQueue;
use PHPUnit\Framework\TestCase;

final class TimerQueueTest extends TestCase
{
    public function testOrdersByDeadlineThenId(): void
    {
        $queue = new TimerQueue();
        $queue->add($this->timer(2, 30));
        $queue->add($this->timer(1, 10));
        $queue->add($this->timer(4, 10));
        $queue->add($this->timer(3, 20));

        self::assertSame(10, $queue->nextDueNs());
        self::assertSame([1, 4, 3, 2], $this->ids($queue->popDue(100)));
        self::assertSame(0, $queue->count());
        self::assertNull($queue->nextDueNs());
    }

    public function testPopDueLeavesFutureTimers(): void
    {
        $queue = new TimerQueue();
        $queue->add($this->timer(1, 10));
        $queue->add($this->timer(2, 50));

        self::assertSame([1], $this->ids($queue->popDue(10)));
        self::assertSame(1, $queue->count());
        self::assertSame(50, $queue->nextDueNs());
    }

    public function testCancelDropsATimerBeforeItIsDue(): void
    {
        $queue = new TimerQueue();
        $queue->add($this->timer(1, 10));
        $queue->cancel(1);
        $queue->cancel(1);

        self::assertSame(0, $queue->count());
        self::assertNull($queue->nextDueNs());
        self::assertSame([], $queue->popDue(10));
    }

    public function testStaleHeapEntriesAreSkipped(): void
    {
        $queue = new TimerQueue();
        $timer = $this->timer(1, 10);
        $queue->add($timer);
        $timer->dueNs = 40;
        $queue->add($timer);

        self::assertSame(1, $queue->count());
        self::assertSame(40, $queue->nextDueNs());
        self::assertSame([], $queue->popDue(10));
        self::assertSame([1], $this->ids($queue->popDue(40)));
        self::assertSame(0, $queue->count());
    }

    public function testPeriodicTimerReschedulesFromThePlannedDeadline(): void
    {
        $queue = new TimerQueue();
        $queue->add(new Timer(1, 100, 50, static function (int $id): void {}));

        $queue->dispatchDue(100, $this->invoke(...));

        self::assertSame(1, $queue->count());
        self::assertSame(150, $queue->nextDueNs());
    }

    public function testPeriodicTimerCatchesUpAtMostOnce(): void
    {
        $queue = new TimerQueue();
        $fired = [];
        $queue->add(new Timer(1, 100, 50, function (int $id) use (&$fired): void {
            $fired[] = $id;
        }));

        $queue->dispatchDue(1_000, $this->invoke(...));

        self::assertSame([1], $fired);
        self::assertSame(1_000, $queue->nextDueNs());

        $queue->dispatchDue(1_000, $this->invoke(...));

        self::assertSame([1, 1], $fired);
        self::assertSame(1_050, $queue->nextDueNs());
    }

    public function testCancelInsideAPeriodicCallbackPreventsTheNextFire(): void
    {
        $queue = new TimerQueue();
        $fired = 0;
        $queue->add(new Timer(1, 10, 10, function (int $id) use ($queue, &$fired): void {
            ++$fired;
            $queue->cancel($id);
        }));

        $queue->dispatchDue(10, $this->invoke(...));
        $queue->dispatchDue(10_000, $this->invoke(...));

        self::assertSame(1, $fired);
        self::assertSame(0, $queue->count());
    }

    public function testCancelInsideAOneShotCallbackIsIdempotent(): void
    {
        $queue = new TimerQueue();
        $fired = 0;
        $queue->add(new Timer(7, 5, 0, function (int $id) use ($queue, &$fired): void {
            ++$fired;
            $queue->cancel($id);
            $queue->cancel($id);
        }));

        $queue->dispatchDue(5, $this->invoke(...));

        self::assertSame(1, $fired);
        self::assertSame(0, $queue->count());
        self::assertSame([], $queue->popDue(5));
    }

    public function testCancelOfALaterTimerInTheSamePassSkipsIt(): void
    {
        $queue = new TimerQueue();
        $fired = [];
        $queue->add(new Timer(1, 10, 0, function (int $id) use ($queue, &$fired): void {
            $fired[] = $id;
            $queue->cancel(2);
        }));
        $queue->add(new Timer(2, 10, 0, function (int $id) use (&$fired): void {
            $fired[] = $id;
        }));

        $queue->dispatchDue(10, $this->invoke(...));

        self::assertSame([1], $fired);
        self::assertSame(0, $queue->count());
    }

    public function testTimerAddedDuringDispatchWaitsForTheNextPass(): void
    {
        $queue = new TimerQueue();
        $fired = [];
        $added = new Timer(2, 0, 0, function (int $id) use (&$fired): void {
            $fired[] = $id;
        });
        $queue->add(new Timer(1, 0, 0, function (int $id) use ($queue, $added, &$fired): void {
            $fired[] = $id;
            $queue->add($added);
        }));

        $queue->dispatchDue(0, $this->invoke(...));
        self::assertSame([1], $fired);
        self::assertSame(1, $queue->count());

        $queue->dispatchDue(0, $this->invoke(...));
        self::assertSame([1, 2], $fired);
        self::assertSame(0, $queue->count());
    }

    public function testExceptionPutsUnrunTimersBack(): void
    {
        $queue = new TimerQueue();
        $queue->add(new Timer(1, 5, 0, static function (int $id): void {
            throw new \RuntimeException('boom');
        }));
        $queue->add(new Timer(2, 5, 0, static function (int $id): void {}));

        try {
            $queue->dispatchDue(5, $this->invoke(...));
            self::fail('expected the timer callback to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(1, $queue->count());
        self::assertSame(5, $queue->nextDueNs());
        self::assertSame([2], $this->ids($queue->popDue(5)));
    }

    private function timer(int $id, int $dueNs, int $intervalNs = 0): Timer
    {
        return new Timer($id, $dueNs, $intervalNs, static function (int $id): void {});
    }

    /**
     * @param list<Timer> $timers
     *
     * @return list<int>
     */
    private function ids(array $timers): array
    {
        return array_map(static fn(Timer $timer): int => $timer->id, $timers);
    }

    private function invoke(Timer $timer): void
    {
        ($timer->callback)($timer->id);
    }
}
