<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Master;

use PhpServer\Master\Privileges;
use PhpServer\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class PrivilegesTest extends TestCase
{
    public function testNonRootSkipsSetuid(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('this process is root');
        }
        $logger = new MemoryLogger();
        Privileges::drop('nobody', 'nogroup', $logger);
        self::assertSame(posix_geteuid(), posix_geteuid());
        $messages = array_column($logger->records, 'message');
        self::assertContains('skipping setuid because the process is not root', $messages);
    }
}
