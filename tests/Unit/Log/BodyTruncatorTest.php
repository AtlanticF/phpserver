<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Log;

use PhpServer\Log\BodyTruncator;
use PHPUnit\Framework\TestCase;

final class BodyTruncatorTest extends TestCase
{
    public function testBodyAtTheLimitIsUnchanged(): void
    {
        self::assertSame('abcd', BodyTruncator::truncate('abcd', 4));
        self::assertSame("a\0b", BodyTruncator::truncate("a\0b", 3));
    }

    public function testBodyPastTheLimitIsCutAtTheLimit(): void
    {
        self::assertSame('abcd', BodyTruncator::truncate('abcdef', 4));
        self::assertSame('', BodyTruncator::truncate('abc', 0));
        self::assertSame("a\0", BodyTruncator::truncate("a\0b\0c", 2));
    }

    public function testShorterBodyIsUnchanged(): void
    {
        self::assertSame('abc', BodyTruncator::truncate('abc', 10));
    }

    public function testCutIsByBytesNotCharacters(): void
    {
        // U+00E9 is C3 A9 in UTF-8. A one-byte limit keeps the leading byte.
        self::assertSame("\xC3", BodyTruncator::truncate('é', 1));
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BodyTruncator::truncate('a', -1);
    }
}
