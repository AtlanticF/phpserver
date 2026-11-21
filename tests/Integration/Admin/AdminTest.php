<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Admin;

use PhpServer\Tests\Support\ServerdProcess;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase
{
    private ?ServerdProcess $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testProbesStatusAndTheBusinessPort(): void
    {
        $business = $this->reserve();
        $admin = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($business, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_ADMIN_ENABLED' => 'true',
            'PHPSERVER_ADMIN_LISTEN' => $admin,
            'PHPSERVER_ADMIN_TOKEN' => 'secret',
        ]);
        $this->server->waitUntilReady();
        $this->server->waitFor('admin listening', 2.0);

        $denied = $this->http($admin, '/healthz');
        self::assertSame(401, $denied['status']);
        $health = $this->http($admin, '/healthz', 'secret');
        self::assertSame(200, $health['status']);
        self::assertSame("ok\n", $health['body']);
        $ready = $this->http($admin, '/readyz', 'secret');
        self::assertSame(200, $ready['status']);

        $status = $this->http($admin, '/status', 'secret');
        self::assertSame(200, $status['status']);
        $json = json_decode($status['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('1.0.0', $json['version']);
        self::assertIsFloat($json['uptime']);
        self::assertIsInt($json['fds']);
        self::assertIsArray($json['workers']);
        self::assertArrayNotHasKey('files', $json);
        $worker = $json['workers'][0];
        self::assertIsArray($worker);
        self::assertSame('Rpc', $worker['name']);
        self::assertGreaterThan(0, $worker['pid']);
        self::assertSame('ready', $worker['state']);
        self::assertIsInt($worker['requests']);

        $cli = $this->runPhp([
            $root . '/bin/serverd',
            'status',
            '-c',
            $root . '/config/server.example.php',
        ], [
            'PHPSERVER_ADMIN_LISTEN' => $admin,
            'PHPSERVER_ADMIN_TOKEN' => 'secret',
        ]);
        self::assertSame(0, $cli['code'], $cli['stderr']);
        self::assertStringContainsString('version 1.0.0', $cli['stdout']);
        self::assertStringContainsString('state=ready', $cli['stdout']);
        self::assertStringContainsString('fds ', $cli['stdout']);
        self::assertDoesNotMatchRegularExpression('/pid file/', $cli['stdout']);

        $socket = stream_socket_client('tcp://' . $business, $errno, $errstr, 2);
        self::assertIsResource($socket);
        fwrite($socket, "GET /healthz HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        stream_set_timeout($socket, 2);
        $raw = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($raw);
        self::assertStringContainsString('TRPC/1 ERR', $raw);
        self::assertStringNotContainsString('HTTP/1.1 200', $raw);
    }

    public function testReadyzDropsBeforeTheProcessExits(): void
    {
        $business = $this->reserve();
        $admin = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($business, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_ADMIN_ENABLED' => 'true',
            'PHPSERVER_ADMIN_LISTEN' => $admin,
            'PHPSERVER_WORKER_RPC_WORKER_CLASS' => 'PhpServer\\Tests\\Fixture\\HandlerWorker',
            'PHPSERVER_WORKER_RPC_BOOTSTRAP' => $root . '/tests/Fixture/slow-handlers.php',
            'TEST_SLOW_SECONDS' => '1.5',
        ]);
        $this->server->waitUntilReady();
        $this->server->waitFor('admin listening', 2.0);
        $before = $this->waitHttp($admin, '/readyz', 200);
        self::assertSame(200, $before['status']);
        $pending = $this->open([
            $root . '/bin/phpserver-rpc',
            $business,
            'Demo.slow',
            '[]',
            '--timeout=5000',
        ]);
        usleep(300_000);
        $this->server->signal(SIGTERM);
        $ready = $this->waitHttp($admin, '/readyz', 503);
        $health = $this->http($admin, '/healthz');
        self::assertSame(503, $ready['status']);
        self::assertSame("not ready\n", $ready['body']);
        self::assertSame(200, $health['status']);
        $this->finish($pending);
    }

    public function testASlowClientDoesNotHoldTheSocket(): void
    {
        $business = $this->reserve();
        $admin = $this->reserve();
        $this->server = ServerdProcess::start($business, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_ADMIN_ENABLED' => 'true',
            'PHPSERVER_ADMIN_LISTEN' => $admin,
        ]);
        $this->server->waitUntilReady();
        $this->server->waitFor('admin listening', 2.0);
        $errno = 0;
        $errstr = '';
        $slow = $this->quietly(function () use ($admin, &$errno, &$errstr): mixed {
            return stream_socket_client('tcp://' . $admin, $errno, $errstr, 2);
        });
        self::assertIsResource($slow);
        fwrite($slow, 'G');
        stream_set_blocking($slow, false);
        $deadline = hrtime(true) + 1_500_000_000;
        $closed = false;
        while (hrtime(true) < $deadline) {
            $chunk = fread($slow, 64);
            $meta = stream_get_meta_data($slow);
            if ($meta['eof']) {
                $closed = true;
                break;
            }
            if (is_string($chunk) && $chunk !== '') {
                break;
            }
            usleep(20_000);
        }
        fclose($slow);
        self::assertTrue($closed);
        self::assertSame(200, $this->http($admin, '/healthz')['status']);
    }

    public function testMetricsMatchHandledRequests(): void
    {
        $business = $this->reserve();
        $admin = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($business, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_ADMIN_ENABLED' => 'true',
            'PHPSERVER_ADMIN_LISTEN' => $admin,
            'PHPSERVER_WORKER_RPC_WORKER_CLASS' => 'PhpServer\\Tests\\Fixture\\HandlerWorker',
            'PHPSERVER_WORKER_RPC_BOOTSTRAP' => $root . '/tests/Fixture/count-handlers.php',
        ]);
        $this->server->waitUntilReady();
        $this->server->waitFor('admin listening', 2.0);
        $failures = 0;
        for ($index = 0; $index < 100; ++$index) {
            $rpc = $this->runPhp([
                $root . '/bin/phpserver-rpc',
                $business,
                'Demo.count',
                '[]',
                '--timeout=2000',
            ]);
            if ($rpc['code'] !== 0) {
                ++$failures;
            }
        }
        self::assertSame(5, $failures);
        $metrics = $this->http($admin, '/metrics');
        self::assertSame(200, $metrics['status']);
        self::assertStringContainsString("phpserver_requests_total 100\n", $metrics['body']);
        self::assertStringContainsString("phpserver_errors_total 5\n", $metrics['body']);
        $promtool = shell_exec('command -v promtool');
        if (is_string($promtool) && trim($promtool) !== '') {
            $pipes = [];
            $process = proc_open([trim($promtool), 'check', 'metrics'], [
                0 => ['pipe', 'w'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fwrite($pipes[0], $metrics['body']);
            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : '');
        }
    }

    public function testRateLimitAndDisabledAdmin(): void
    {
        $business = $this->reserve();
        $admin = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($business, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_ADMIN_ENABLED' => 'true',
            'PHPSERVER_ADMIN_LISTEN' => $admin,
            'PHPSERVER_WORKER_RPC_WORKER_CLASS' => 'PhpServer\\Tests\\Fixture\\HandlerWorker',
            'PHPSERVER_WORKER_RPC_BOOTSTRAP' => $root . '/tests/Fixture/rate-handlers.php',
        ]);
        $this->server->waitUntilReady();
        $this->server->waitFor('admin listening', 2.0);
        $first = $this->runPhp([$root . '/bin/phpserver-rpc', $business, 'Echo.say', '["a"]', '--timeout=2000']);
        $second = $this->runPhp([$root . '/bin/phpserver-rpc', $business, 'Echo.say', '["b"]', '--timeout=2000']);
        self::assertSame(0, $first['code'], $first['stderr']);
        self::assertSame(1, $second['code']);
        self::assertStringContainsString('-32001', $second['stderr']);
        $metrics = $this->http($admin, '/metrics');
        self::assertStringContainsString("phpserver_rejected_total 1\n", $metrics['body']);
        $this->server->stop();
        $this->server = null;

        $closedAdmin = $this->reserve();
        $this->server = ServerdProcess::start($business, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_ADMIN_ENABLED' => 'false',
            'PHPSERVER_ADMIN_LISTEN' => $closedAdmin,
        ]);
        $this->server->waitUntilReady();
        $errno = 0;
        $errstr = '';
        $socket = $this->quietly(function () use ($closedAdmin, &$errno, &$errstr): mixed {
            return stream_socket_client('tcp://' . $closedAdmin, $errno, $errstr, 0.2);
        });
        self::assertFalse(is_resource($socket));
        $rpc = $this->runPhp([$root . '/bin/phpserver-rpc', $business, 'Echo.say', '["hi"]', '--timeout=2000']);
        self::assertSame(0, $rpc['code'], $rpc['stderr']);
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
     * @return array{status: int, body: string}
     */
    private function http(string $address, string $path, string $token = ''): array
    {
        $errno = 0;
        $errstr = '';
        $socket = $this->quietly(function () use ($address, &$errno, &$errstr): mixed {
            return stream_socket_client('tcp://' . $address, $errno, $errstr, 2);
        });
        if (!is_resource($socket)) {
            return ['status' => 0, 'body' => is_string($errstr) ? $errstr : ''];
        }
        $request = 'GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n";
        if ($token !== '') {
            $request .= 'Authorization: Bearer ' . $token . "\r\n";
        }
        fwrite($socket, $request . "\r\n");
        stream_set_timeout($socket, 2);
        $raw = stream_get_contents($socket);
        fclose($socket);
        if (!is_string($raw) || !str_contains($raw, "\r\n\r\n")) {
            return ['status' => 0, 'body' => is_string($raw) ? $raw : ''];
        }
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $status = 0;
        if (preg_match('/^HTTP\/1\.[01] (\d+)/', $head, $matches) === 1) {
            $status = (int) $matches[1];
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function waitHttp(string $address, string $path, int $status, string $token = ''): array
    {
        $deadline = hrtime(true) + 3_000_000_000;
        $last = ['status' => 0, 'body' => ''];
        while (hrtime(true) < $deadline) {
            $last = $this->http($address, $path, $token);
            if ($last['status'] === $status) {
                return $last;
            }
            usleep(20_000);
        }

        return $last;
    }

    private function quietly(\Closure $run): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            return true;
        });
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPhp(array $arguments, array $env = []): array
    {
        return $this->finish($this->open($arguments, $env));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function open(array $arguments, array $env = []): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, ...$arguments], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3), $env === [] ? null : $env);
        if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            self::fail('proc_open failed');
        }

        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{process: resource, stdout: resource, stderr: resource} $pending
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function finish(array $pending): array
    {
        $stdout = stream_get_contents($pending['stdout']);
        $stderr = stream_get_contents($pending['stderr']);
        fclose($pending['stdout']);
        fclose($pending['stderr']);

        return [
            'code' => proc_close($pending['process']),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
