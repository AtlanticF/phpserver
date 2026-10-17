<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Protocol\TextRpc;

use PhpServer\Protocol\TextRpc\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function testListedCodesMatchTheSpec(): void
    {
        self::assertSame(-32700, ErrorCode::ParseError->value);
        self::assertSame(-32600, ErrorCode::InvalidRequest->value);
        self::assertSame(-32601, ErrorCode::MethodNotFound->value);
        self::assertSame(-32602, ErrorCode::InvalidParams->value);
        self::assertSame(-32603, ErrorCode::InternalError->value);
        self::assertSame(-32001, ErrorCode::RateLimited->value);
        self::assertSame(-32002, ErrorCode::FrameTooLarge->value);
        self::assertSame(-32003, ErrorCode::Timeout->value);
        self::assertSame(-32004, ErrorCode::UnsupportedVersionOrCodec->value);
        self::assertSame(-32005, ErrorCode::ShuttingDown->value);
        self::assertSame(-32006, ErrorCode::Overloaded->value);
    }

    public function testConnectionFlagFollowsTheLevel(): void
    {
        self::assertTrue(ErrorCode::FrameTooLarge->alwaysClosesConnection());
        self::assertTrue(ErrorCode::ShuttingDown->alwaysClosesConnection());
        self::assertTrue(ErrorCode::FrameTooLarge->closesConnection(false));
        self::assertFalse(ErrorCode::ParseError->closesConnection(true));
        self::assertFalse(ErrorCode::MethodNotFound->closesConnection());
        self::assertFalse(ErrorCode::Timeout->closesConnection());
        self::assertFalse(ErrorCode::RateLimited->closesConnection());
        self::assertFalse(ErrorCode::Overloaded->closesConnection());
        self::assertFalse(ErrorCode::InternalError->closesConnection());
        self::assertFalse(ErrorCode::InvalidParams->closesConnection());

        self::assertFalse(ErrorCode::InvalidRequest->alwaysClosesConnection());
        self::assertTrue(ErrorCode::InvalidRequest->closesConnection(true));
        self::assertFalse(ErrorCode::InvalidRequest->closesConnection(false));
        self::assertTrue(ErrorCode::UnsupportedVersionOrCodec->closesConnection(true));
        self::assertFalse(ErrorCode::UnsupportedVersionOrCodec->closesConnection(false));
    }

    public function testUnknownCodesStayOpen(): void
    {
        self::assertFalse(ErrorCode::closesFor(1002, true));
        self::assertFalse(ErrorCode::closesFor(-32099, true));
        self::assertTrue(ErrorCode::closesFor(-32002, false));
        self::assertTrue(ErrorCode::closesFor(-32600, true));
        self::assertFalse(ErrorCode::closesFor(-32600, false));
    }
}
