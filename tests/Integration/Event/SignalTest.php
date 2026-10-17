<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Event;

use PhpServer\Event\SelectLoop;
use PhpServer\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class SignalTest extends TestCase
{
    public function testSigtermCallbackStopsTheChildLoop(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }
        [$parent, $childSock] = $pair;

        $pid = pcntl_fork();
        if ($pid < 0) {
            self::fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            fclose($parent);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $code = 0;
            try {
                $loop = new SelectLoop(new SystemClock());
                $loop->onSignal(SIGTERM, function (int $signo) use ($loop): void {
                    $loop->stop();
                });
                $loop->addTimer(0.0, function (int $id) use ($childSock): void {
                    fwrite($childSock, '1');
                });
                $loop->run();
            } catch (\Throwable) {
                $code = 1;
            }
            fclose($childSock);
            exit($code);
        }

        fclose($childSock);
        $status = 0;
        $collected = false;
        try {
            stream_set_blocking($parent, true);
            stream_set_timeout($parent, 2);
            $byte = fread($parent, 1);
            self::assertSame('1', $byte);
            self::assertTrue(posix_kill($pid, SIGTERM));

            $deadline = time() + 3;
            while (time() < $deadline) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result === $pid) {
                    $collected = true;
                    break;
                }
                usleep(20_000);
            }

            self::assertTrue($collected, 'child did not exit after SIGTERM');
            if (!is_int($status)) {
                self::fail('pcntl_waitpid did not report a status');
            }
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            fclose($parent);
            if (!$collected && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }
}
