<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Event;

use PhpServer\Event\SelectLoop;
use PhpServer\Support\SystemClock;
use PhpServer\Tests\Support\MemoryLogger;
use PhpServer\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class SelectLoopTest extends TestCase
{
    public function testReadAndWriteCallbacksFireThenTheLoopExits(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$read, $write] = $this->pair();
        $events = [];
        try {
            fwrite($write, 'x');
            $loop->onReadable($read, function (mixed $stream) use (&$events, $loop): void {
                $events[] = 'read';
                $loop->offReadable($stream);
            });
            $loop->onWritable($write, function (mixed $stream) use (&$events, $loop): void {
                $events[] = 'write';
                $loop->offWritable($stream);
            });

            $loop->run();

            self::assertSame(['read', 'write'], $events);
            self::assertSame(0, $loop->metrics()->readStreams);
            self::assertSame(0, $loop->metrics()->writeStreams);
        } finally {
            $this->close($read, $write);
        }
    }

    public function testOffStopsCallbacksAndDoesNotCloseTheStream(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$read, $write] = $this->pair();
        $fired = 0;
        try {
            fwrite($write, 'x');
            $loop->onReadable($read, function (mixed $stream) use (&$fired, $loop): void {
                ++$fired;
                $loop->stop();
            });
            $loop->onWritable($write, function (mixed $stream) use (&$fired, $loop): void {
                ++$fired;
                $loop->stop();
            });
            $loop->offReadable($read);
            $loop->offReadable($read);
            $loop->offWritable($write);
            $loop->offWritable($write);
            $loop->addTimer(0.05, function (int $id) use ($loop): void {
                $loop->stop();
            });

            $loop->run();

            self::assertSame(0, $fired);
            self::assertTrue(is_resource($read));
            self::assertTrue(is_resource($write));
        } finally {
            $this->close($read, $write);
        }
    }

    public function testReregisterReplacesTheCallback(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$read, $write] = $this->pair();
        $events = [];
        try {
            fwrite($write, 'x');
            $loop->onReadable($read, function (mixed $stream) use (&$events, $loop): void {
                $events[] = 'first';
                $loop->stop();
            });
            $loop->onReadable($read, function (mixed $stream) use (&$events, $loop): void {
                $events[] = 'second';
                $loop->stop();
            });

            $loop->run();

            self::assertSame(['second'], $events);
        } finally {
            $this->close($read, $write);
        }
    }

    public function testEmptyLoopReturns(): void
    {
        $loop = new SelectLoop(new SystemClock());

        $loop->run();

        self::assertSame(1, $loop->metrics()->ticks);
        self::assertSame(0, $loop->metrics()->callbacksRun);
    }

    public function testStopFromAReadCallbackReturns(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$read, $write] = $this->pair();
        $fired = 0;
        try {
            fwrite($write, 'x');
            $loop->onReadable($read, function (mixed $stream) use (&$fired, $loop): void {
                ++$fired;
                $loop->stop();
            });

            $loop->run();

            self::assertSame(1, $fired);
        } finally {
            $this->close($read, $write);
        }
    }

    public function testStopFromAPeriodicTimerReturns(): void
    {
        $loop = new SelectLoop(new SystemClock());
        $fired = 0;
        $loop->addPeriodicTimer(0.01, function (int $id) use (&$fired, $loop): void {
            ++$fired;
            $loop->stop();
        });

        $loop->run();

        self::assertSame(1, $fired);
        self::assertSame(1, $loop->metrics()->timers);
    }

    public function testTimerOnlyRunReturnsWithoutSelecting(): void
    {
        $logger = new MemoryLogger();
        $loop = new SelectLoop(new SystemClock(), $logger);
        $fired = 0;
        $loop->addTimer(0.0, function (int $id) use (&$fired): void {
            ++$fired;
        });

        $loop->run();

        self::assertSame(1, $fired);
        self::assertSame(0, $loop->metrics()->timers);
        self::assertNotContains('select', $this->modes($logger));
    }

    public function testTimerWaitSleepsInsteadOfSelectingEmptySets(): void
    {
        $logger = new MemoryLogger();
        $loop = new SelectLoop(new SystemClock(), $logger);
        $loop->addTimer(0.02, function (int $id) use ($loop): void {
            $loop->stop();
        });

        $loop->run();

        $modes = $this->modes($logger);
        self::assertContains('sleep', $modes);
        self::assertNotContains('select', $modes);
    }

    public function testClosingAnotherReadyStreamSkipsItsCallback(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$aRead, $aWrite] = $this->pair();
        [$bRead, $bWrite] = $this->pair();
        $events = [];
        try {
            fwrite($aWrite, 'a');
            fwrite($bWrite, 'b');
            $loop->onReadable($aRead, function (mixed $stream) use (&$events, $loop, $bRead): void {
                $events[] = 'a';
                $loop->removeStream($bRead);
                fclose($bRead);
                $loop->stop();
            });
            $loop->onReadable($bRead, function (mixed $stream) use (&$events): void {
                $events[] = 'b';
            });

            $loop->run();

            self::assertSame(['a'], $events);
        } finally {
            $this->close($aRead, $aWrite, $bRead, $bWrite);
        }
    }

    public function testZeroDelayTimerAddedFromACallbackRunsOnTheNextTick(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$read, $write] = $this->pair();
        $events = [];
        try {
            fwrite($write, 'x');
            $loop->onReadable($read, function (mixed $stream) use (&$events, $loop): void {
                $events[] = 'read';
                $loop->stop();
            });
            $loop->addTimer(0.0, function (int $id) use (&$events, $loop): void {
                $events[] = 'first';
                $loop->addTimer(0.0, function (int $id) use (&$events): void {
                    $events[] = 'second';
                });
            });

            $loop->run();

            self::assertSame(['first', 'read', 'second'], $events);
        } finally {
            $this->close($read, $write);
        }
    }

    public function testTimerIdsStartAtOneAndCancelIsIdempotent(): void
    {
        $loop = new SelectLoop(new SystemClock());
        $ran = false;
        $id = $loop->addTimer(0.0, function (int $id) use (&$ran): void {
            $ran = true;
        });
        $loop->cancelTimer($id);
        $loop->cancelTimer($id);
        $loop->addTimer(0.0, function (int $id) use ($loop): void {
            $loop->stop();
        });

        $loop->run();

        self::assertSame(1, $id);
        self::assertFalse($ran);
    }

    public function testRejectsBadDelaysAndStreams(): void
    {
        $loop = new SelectLoop(new SystemClock());
        $rejected = 0;
        foreach ([-0.01, NAN, INF, -INF] as $seconds) {
            try {
                $loop->addTimer($seconds, static function (int $id): void {});
                self::fail('expected a negative or non-finite delay to be rejected');
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        try {
            $loop->addPeriodicTimer(0.0, static function (int $id): void {});
            self::fail('expected a zero periodic interval to be rejected');
        } catch (\InvalidArgumentException) {
            ++$rejected;
        }
        self::assertSame(5, $rejected);

        [$read, $write] = $this->pair();
        fclose($read);
        try {
            $loop->onReadable($read, static function (mixed $stream): void {});
            self::fail('expected a closed stream to be rejected');
        } catch (\InvalidArgumentException) {
        } finally {
            $this->close($write);
        }
    }

    public function testCallbackExceptionPropagatesAndRunCanBeCalledAgain(): void
    {
        $loop = new SelectLoop(new SystemClock());
        $loop->addTimer(0.0, static function (int $id): void {
            throw new \RuntimeException('from timer');
        });

        try {
            $loop->run();
            self::fail('expected the timer callback to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('from timer', $e->getMessage());
        }

        $ran = false;
        $loop->addTimer(0.0, function (int $id) use (&$ran, $loop): void {
            $ran = true;
            $loop->stop();
        });
        $loop->run();

        self::assertTrue($ran);
        self::assertGreaterThan(0, $loop->metrics()->callbacksRun);
    }

    public function testRunCannotReenter(): void
    {
        $loop = new SelectLoop(new SystemClock());
        $loop->addTimer(0.0, function (int $id) use ($loop): void {
            $loop->run();
        });

        $this->expectException(\LogicException::class);
        $loop->run();
    }

    public function testSlowCallbackIsLoggedFromTheMonotonicClock(): void
    {
        $clock = new MutableClock();
        $logger = new MemoryLogger();
        $loop = new SelectLoop($clock, $logger, 0.1);
        $loop->addTimer(0.0, function (int $id) use ($clock, $loop): void {
            $clock->advanceMonotonic(200_000_000);
            $loop->stop();
        });

        $loop->run();

        self::assertSame(200_000_000, $loop->metrics()->slowestCallbackNs);
        $warnings = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => $record['level'] === 'warning',
        ));
        self::assertCount(1, $warnings);
        $callback = $warnings[0]['context']['callback'] ?? null;
        if (!is_string($callback)) {
            self::fail('expected the slow-callback warning to name the closure');
        }
        self::assertStringContainsString('SelectLoopTest.php', $callback);
    }

    public function testRemoveStreamClearsBothDirections(): void
    {
        $loop = new SelectLoop(new SystemClock());
        [$read, $write] = $this->pair();
        try {
            $loop->onReadable($read, static function (mixed $stream): void {});
            $loop->onWritable($read, static function (mixed $stream): void {});
            $loop->removeStream($read);

            self::assertSame(0, $loop->metrics()->readStreams);
            self::assertSame(0, $loop->metrics()->writeStreams);
            self::assertTrue(is_resource($read));
        } finally {
            $this->close($read, $write);
        }
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }
        $left = $pair[0];
        $right = $pair[1];
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        return [$left, $right];
    }

    private function close(mixed ...$streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function modes(MemoryLogger $logger): array
    {
        $modes = [];
        foreach ($logger->records as $record) {
            $mode = $record['context']['mode'] ?? null;
            if (is_string($mode)) {
                $modes[] = $mode;
            }
        }

        return $modes;
    }
}
