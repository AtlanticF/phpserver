<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Worker;

use PhpServer\Config\WorkerConfig;
use PhpServer\Event\SelectLoop;
use PhpServer\Metrics\Counters;
use PhpServer\Support\SystemClock;
use PhpServer\Tests\Support\MemoryLogger;
use PhpServer\Tests\Support\MutableClock;
use PhpServer\Worker\AbstractWorker;
use PhpServer\Worker\Input;
use PhpServer\Worker\Output;
use PHPUnit\Framework\TestCase;

final class AbstractWorkerTest extends TestCase
{
    public function testMaxConnectionsRejectsWithoutBuffering(): void
    {
        $counters = new Counters();
        $worker = $this->worker($counters, maxConnections: 1);
        [$first, $firstPeer] = $this->pair();
        [$second, $secondPeer] = $this->pair();
        try {
            fwrite($secondPeer, str_repeat('x', 1000));
            self::assertTrue($worker->watch($first));
            self::assertFalse($worker->watch($second));
            self::assertSame(1, $counters->get('accept'));
            self::assertSame(1, $counters->get('conn_rejected'));
            self::assertFalse(is_resource($second));
            self::assertSame(0, $counters->get('requests'));
        } finally {
            $this->close($first, $firstPeer, $second, $secondPeer);
        }
    }

    public function testReadBufferStopsAtMaxFrameBytes(): void
    {
        $counters = new Counters();
        $loop = new SelectLoop(new SystemClock());
        $seen = 0;
        $worker = new class ($this->config(maxFrame: 16), new MemoryLogger(), $loop, new SystemClock(), $counters) extends AbstractWorker {
            public int $seen = 0;

            public function dealInput(string $buffer): Input
            {
                $this->seen = max($this->seen, strlen($buffer));

                return Input::needMore();
            }

            public function dealProcess(string $message): Output
            {
                return new Output('', true);
            }
        };
        [$server, $peer] = $this->pair();
        try {
            self::assertTrue($worker->watch($server));
            fwrite($peer, str_repeat('x', 100));
            $loop->run();
            $seen = $worker->seen;
            self::assertLessThanOrEqual(16, $seen);
            self::assertSame(1, $counters->get('errors'));
            self::assertFalse(is_resource($server));
        } finally {
            $this->close($server, $peer);
        }
    }

    public function testIdleTimeoutClosesAndCounts(): void
    {
        $clock = new MutableClock();
        $counters = new Counters();
        $loop = new SelectLoop($clock);
        $worker = $this->worker($counters, $loop, $clock, idle: 5.0);
        [$server, $peer] = $this->pair();
        try {
            self::assertTrue($worker->watch($server));
            $clock->advanceMonotonic(5_000_000_001);
            $loop->run();
            self::assertSame(1, $counters->get('idle_timeout'));
            self::assertSame(0, $counters->get('errors'));
            self::assertFalse(is_resource($server));
        } finally {
            $this->close($server, $peer);
        }
    }

    public function testPartialReadClosesAtRecvTimeout(): void
    {
        $logger = new MemoryLogger();
        $counters = new Counters();
        $loop = new SelectLoop(new SystemClock());
        $worker = $this->worker($counters, $loop, logger: $logger, recv: 0.2, idle: 2.0);
        [$server, $peer] = $this->pair();
        try {
            self::assertTrue($worker->watch($server));
            fwrite($peer, "TRPC/1 REQ 1 json 5\n");
            $loop->run();
            $messages = array_column($logger->records, 'message');
            self::assertContains('receive timed out', $messages);
            self::assertSame(0, $counters->get('idle_timeout'));
            self::assertFalse(is_resource($server));
            $rest = is_resource($peer) ? fread($peer, 128) : '';
            self::assertTrue($rest === '' || $rest === false);
        } finally {
            $this->close($server, $peer);
        }
    }

    private function worker(
        Counters $counters,
        ?SelectLoop $loop = null,
        ?MutableClock $clock = null,
        ?MemoryLogger $logger = null,
        int $maxConnections = 8,
        float $idle = 60.0,
        float $recv = 30.0,
    ): AbstractWorker {
        $clock ??= new MutableClock();
        $loop ??= new SelectLoop($clock);

        return new class ($this->config(maxConnections: $maxConnections, idle: $idle, recv: $recv), $logger ?? new MemoryLogger(), $loop, $clock, $counters) extends AbstractWorker {
            public function dealInput(string $buffer): Input
            {
                return Input::needMore();
            }

            public function dealProcess(string $message): Output
            {
                return new Output('', true);
            }
        };
    }

    private function config(
        int $maxConnections = 8,
        int $maxFrame = 1284,
        float $idle = 60.0,
        float $recv = 30.0,
    ): WorkerConfig {
        return new WorkerConfig(
            name: 'Test',
            listen: '127.0.0.1:9',
            host: '127.0.0.1',
            port: 9,
            workerClass: AbstractWorker::class,
            bootstrap: '',
            childCount: 1,
            backlog: 16,
            maxConnections: $maxConnections,
            recvTimeout: $recv,
            sendTimeout: 30.0,
            idleTimeout: $idle,
            processTimeout: 30.0,
            maxBodyBytes: 1024,
            maxFrameBytes: $maxFrame,
            pipelineBacklog: 32,
            maxRequests: 0,
            maxWorkTime: 0.0,
            recycleStagger: 0.0,
        );
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
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return [$pair[0], $pair[1]];
    }

    private function close(mixed ...$streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
