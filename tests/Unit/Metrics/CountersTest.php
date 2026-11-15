<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Metrics;

use PhpServer\Metrics\Counters;
use PHPUnit\Framework\TestCase;

final class CountersTest extends TestCase
{
    public function testSnapshotIsReadableInProcess(): void
    {
        $counters = new Counters();
        $counters->incrementAccept();
        $counters->incrementRequests();
        $counters->incrementRequests();
        $counters->incrementErrors();
        $counters->incrementConnRejected();
        $counters->incrementIdleTimeout();

        self::assertSame([
            'accept' => 1,
            'requests' => 2,
            'errors' => 1,
            'conn_rejected' => 1,
            'idle_timeout' => 1,
        ], $counters->snapshot());
        self::assertSame(1, $counters->get('conn_rejected'));
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Counters())->get('phpserver_requests_total');
    }
}
