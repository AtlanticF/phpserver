<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Log;

use PhpServer\Log\RequestId;
use PHPUnit\Framework\TestCase;

final class RequestIdTest extends TestCase
{
    public function testGeneratedIdMatchesTheTraceIdCharset(): void
    {
        $id = RequestId::generate();

        self::assertMatchesRegularExpression('/^r-[0-9a-f]{16}$/', $id->value);
        self::assertTrue(RequestId::isValid($id->value));
        self::assertSame($id->value, (string) $id);
    }

    public function testFromStringAcceptsAClientTraceId(): void
    {
        $id = RequestId::fromString('web-1a2b3c');

        self::assertSame('web-1a2b3c', $id->value);
    }

    public function testInvalidIdsAreRejected(): void
    {
        self::assertFalse(RequestId::isValid(''));
        self::assertFalse(RequestId::isValid('has space'));
        self::assertFalse(RequestId::isValid("line\nbreak"));
        self::assertFalse(RequestId::isValid(str_repeat('a', 65)));

        $this->expectException(\InvalidArgumentException::class);
        RequestId::fromString('has space');
    }
}
