<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Bin;

use PHPUnit\Framework\TestCase;

final class PlaceholderBinTest extends TestCase
{
    public function testBinsPrintNotImplementedAndExitNonZero(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['serverd', 'worker', 'phpserver-rpc'] as $name) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $root . '/bin/' . $name], [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, $root);
            if (!is_resource($process)) {
                self::fail('proc_open failed for ' . $name);
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            self::assertSame(1, $code, $name);
            self::assertSame('', $stdout);
            self::assertIsString($stderr);
            self::assertStringContainsString('not implemented', $stderr);
        }
    }
}
