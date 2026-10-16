<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Exception;

use PhpServer\Exception\ConfigException;
use PhpServer\Exception\PhpServerException;
use PhpServer\Exception\ProcessException;
use PhpServer\Exception\ProtocolException;
use PhpServer\Exception\RateLimitedException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function testConcreteExceptionsAreRuntimeFailuresAndMarkers(): void
    {
        $exceptions = [
            new ConfigException('bad config'),
            new ProtocolException('bad frame', -32002),
            new RateLimitedException(),
            new ProcessException('fork failed'),
        ];

        foreach ($exceptions as $exception) {
            self::assertInstanceOf(\RuntimeException::class, $exception);
            self::assertInstanceOf(PhpServerException::class, $exception);
        }
    }

    public function testRateLimitedUsesTheProtocolCode(): void
    {
        $exception = new RateLimitedException();

        self::assertSame(-32001, $exception->getCode());
        self::assertSame('Rate limited', $exception->getMessage());
    }

    public function testProtocolCodeIsPreserved(): void
    {
        $exception = new ProtocolException('frame too large', -32002);

        self::assertSame(-32002, $exception->getCode());
    }
}
