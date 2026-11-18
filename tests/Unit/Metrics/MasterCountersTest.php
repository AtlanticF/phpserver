<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Metrics;

use PhpServer\Metrics\MasterCounters;
use PHPUnit\Framework\TestCase;

final class MasterCountersTest extends TestCase
{
    public function testTheThreePathsIncrementDifferentCounters(): void
    {
        $counters = new MasterCounters();
        $counters->incrementWorkerRestarts();
        $counters->incrementDrainTimeout();
        $counters->incrementForcedKill();

        self::assertSame([
            'worker_restarts' => 1,
            'drain_timeout' => 1,
            'forced_kill' => 1,
        ], $counters->snapshot());
        self::assertSame(1, $counters->get('worker_restarts'));
        self::assertSame(1, $counters->get('drain_timeout'));
        self::assertSame(1, $counters->get('forced_kill'));
    }
}
