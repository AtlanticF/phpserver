<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\RateLimit;

use PhpServer\RateLimit\Decision;
use PhpServer\RateLimit\TokenBucket;
use PhpServer\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class TokenBucketTest extends TestCase
{
    public function testAFullBucketAllowsThenRejectsUntilTimePasses(): void
    {
        $clock = new MutableClock();
        $bucket = new TokenBucket($clock, 1.0, 1);
        self::assertSame(Decision::Allow, $bucket->decide('one'));
        self::assertSame(Decision::Reject, $bucket->decide('two'));
        $clock->advanceMonotonic(1_000_000_000);
        self::assertSame(Decision::Allow, $bucket->decide('three'));
    }
}
