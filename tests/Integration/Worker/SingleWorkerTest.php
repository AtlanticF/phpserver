<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Worker;

use PhpServer\Tests\Support\WorkerProcess;
use PHPUnit\Framework\TestCase;

final class SingleWorkerTest extends TestCase
{
    private ?WorkerProcess $worker = null;

    protected function tearDown(): void
    {
        $this->worker?->stop();
    }

    public function testHelpAndUsage(): void
    {
        $root = dirname(__DIR__, 3);
        $help = $this->runPhp([$root . '/bin/worker', '--help']);
        self::assertSame(0, $help['code']);
        self::assertStringContainsString('Usage:', $help['stdout']);

        $usage = $this->runPhp([$root . '/bin/worker']);
        self::assertSame(64, $usage['code']);
        self::assertStringContainsString('worker:', $usage['stderr']);
    }

    public function testEchoReturnsHello(): void
    {
        $address = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->worker = WorkerProcess::start(
            $root . '/config/server.echo.php',
            'Echo',
            $address,
            ['PHPSERVER_WORKER_ECHO_LISTEN' => $address],
        );
        $this->worker->waitUntilReady();

        $socket = stream_socket_client('tcp://' . $address, $errno, $errstr, 2);
        self::assertIsResource($socket);
        fwrite($socket, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        stream_set_timeout($socket, 3);
        $raw = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($raw);
        self::assertStringContainsString("\r\n\r\nhello", $raw);
        self::assertStringContainsString('Content-Length: 5', $raw);
    }

    public function testRpcCallPrintsTheResultAndTraceId(): void
    {
        $address = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->worker = WorkerProcess::start(
            $root . '/config/server.example.php',
            'Rpc',
            $address,
            ['PHPSERVER_WORKER_RPC_LISTEN' => $address],
            true,
        );
        $this->worker->waitUntilReady();

        $cli = $this->runPhp([
            $root . '/bin/phpserver-rpc',
            $address,
            'Echo.say',
            '["hi"]',
            '-v',
            '--trace-id=demo-1',
        ]);
        self::assertSame(0, $cli['code'], $cli['stderr']);
        self::assertSame("\"hi\"\n", $cli['stdout']);
        self::assertStringContainsString('trace-id: demo-1', $cli['stderr']);
        self::assertStringContainsString('request_id=demo-1', $this->worker->output());
    }

    public function testHandlerExceptionKeepsTheWorkerUp(): void
    {
        $address = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->worker = WorkerProcess::start(
            $root . '/config/server.example.php',
            'Rpc',
            $address,
            [
                'PHPSERVER_WORKER_RPC_LISTEN' => $address,
                'PHPSERVER_WORKER_RPC_WORKER_CLASS' => 'PhpServer\\Tests\\Fixture\\HandlerWorker',
                'PHPSERVER_WORKER_RPC_BOOTSTRAP' => $root . '/tests/Fixture/handlers.php',
            ],
        );
        $this->worker->waitUntilReady();

        $boom = $this->runPhp([
            $root . '/bin/phpserver-rpc',
            $address,
            'Demo.boom',
            '[]',
            '-v',
            '--trace-id=boom-1',
        ]);
        self::assertSame(1, $boom['code'], $boom['stderr']);
        self::assertStringContainsString('error -32603:', $boom['stderr']);
        self::assertStringContainsString('trace-id: boom-1', $boom['stderr']);
        self::assertStringNotContainsString('boom-marker', $boom['stdout']);
        $log = $this->worker->output();
        self::assertStringContainsString('error', $log);
        self::assertStringContainsString('request_id=boom-1', $log);
        self::assertStringContainsString('boom-marker', $log);

        $ok = $this->runPhp([
            $root . '/bin/phpserver-rpc',
            $address,
            'Demo.ok',
            '[]',
        ]);
        self::assertSame(0, $ok['code'], $ok['stderr']);
        self::assertSame("\"ok\"\n", $ok['stdout']);
    }

    public function testOversizedFrameAndPartialRead(): void
    {
        $address = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->worker = WorkerProcess::start(
            $root . '/config/server.example.php',
            'Rpc',
            $address,
            [
                'PHPSERVER_WORKER_RPC_LISTEN' => $address,
                'PHPSERVER_WORKER_RPC_MAX_BODY_BYTES' => '256',
                'PHPSERVER_WORKER_RPC_MAX_FRAME_BYTES' => '516',
                'PHPSERVER_WORKER_RPC_RECV_TIMEOUT' => '0.4',
            ],
        );
        $this->worker->waitUntilReady();

        $tooBig = stream_socket_client('tcp://' . $address, $errno, $errstr, 2);
        self::assertIsResource($tooBig);
        fwrite($tooBig, "TRPC/1 REQ 7 json 1000\n");
        stream_set_timeout($tooBig, 2);
        $raw = stream_get_contents($tooBig);
        $meta = stream_get_meta_data($tooBig);
        fclose($tooBig);
        self::assertIsString($raw);
        self::assertStringContainsString('-32002', $raw);
        self::assertTrue($meta['eof']);
        self::assertStringContainsString('TRPC/1 ERR 7 json', $raw);

        $partial = stream_socket_client('tcp://' . $address, $errno, $errstr, 2);
        self::assertIsResource($partial);
        fwrite($partial, "TRPC/1 REQ 1 json 20\n");
        $deadline = hrtime(true) + 2_000_000_000;
        $rest = '';
        stream_set_blocking($partial, false);
        $closed = false;
        while (hrtime(true) < $deadline) {
            $chunk = fread($partial, 128);
            if (is_string($chunk) && $chunk !== '') {
                $rest .= $chunk;
            }
            $meta = stream_get_meta_data($partial);
            if ($meta['eof']) {
                $closed = true;
                break;
            }
            usleep(20_000);
        }
        fclose($partial);
        self::assertTrue($closed);
        self::assertSame('', $rest);

        $again = $this->runPhp([
            $root . '/bin/phpserver-rpc',
            $address,
            'Echo.say',
            '["hi"]',
        ]);
        self::assertSame(0, $again['code'], $again['stderr']);
        self::assertSame("\"hi\"\n", $again['stdout']);
    }

    private function reserve(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($server)) {
            self::fail('cannot reserve a port');
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if (!is_string($name)) {
            self::fail('reserved socket has no name');
        }

        return $name;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPhp(array $arguments): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, ...$arguments], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3));
        if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            self::fail('proc_open failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
