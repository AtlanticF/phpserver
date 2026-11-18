<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Master;

use PhpServer\Tests\Support\ServerdProcess;
use PHPUnit\Framework\TestCase;

final class ServerdTest extends TestCase
{
    private ?ServerdProcess $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testHelpAndStatusWithoutAPidFile(): void
    {
        $root = dirname(__DIR__, 3);
        $help = $this->runPhp([$root . '/bin/serverd', '--help']);
        self::assertSame(0, $help['code']);
        self::assertStringContainsString('Usage:', $help['stdout']);

        $status = $this->runPhp([$root . '/bin/serverd', 'status', '-c', $root . '/config/server.example.php']);
        self::assertSame(1, $status['code']);
        self::assertStringContainsString('admin is not reachable', $status['stderr']);
    }

    public function testForegroundStartsWorkersAndSkipsSetuid(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '2',
            'PHPSERVER_USER' => 'nobody',
        ]);
        $this->server->waitUntilReady(2);

        $master = $this->server->pid();
        $workers = $this->server->workerPids();
        self::assertCount(2, $workers);
        self::assertStringContainsString('phpserver: master', $this->command($master));
        self::assertStringContainsString('phpserver: worker Rpc', $this->command($workers[0]));
        self::assertStringContainsString('skipping setuid because the process is not root', $this->server->output());

        $rpc = $this->rpc($address, 'Echo.say', '["hi"]');
        self::assertSame(0, $rpc['code'], $rpc['stderr']);
        self::assertSame("\"hi\"\n", $rpc['stdout']);

        $code = $this->server->stop();
        $this->server = null;
        self::assertSame(0, $code);
    }

    public function testKilledWorkerIsReplaced(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '2',
        ]);
        $this->server->waitUntilReady(2);
        $before = $this->server->workerPids();
        posix_kill($before[0], SIGKILL);
        $this->server->waitFor('worker_restarts=1', 2.0);
        $after = $this->server->workerPids();
        self::assertGreaterThan(count($before), count($after));
        self::assertNotContains($after[count($after) - 1], $before);
        $rpc = $this->rpc($address, 'Echo.say', '["up"]');
        self::assertSame(0, $rpc['code'], $rpc['stderr']);
    }

    public function testStoppedWorkerIsForcedKilled(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
        ]);
        $this->server->waitUntilReady();
        $worker = $this->server->workerPids()[0];
        posix_kill($worker, SIGSTOP);
        try {
            $this->server->waitFor('forced_kill=1', 3.0);
        } finally {
            posix_kill($worker, SIGKILL);
        }
        self::assertStringNotContainsString('drain_timeout=', $this->server->output());
        $this->server->waitFor('worker_restarts=1', 2.0);
    }

    public function testSlowRequestFinishesOnSigterm(): void
    {
        $address = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_WORKER_RPC_WORKER_CLASS' => 'PhpServer\\Tests\\Fixture\\HandlerWorker',
            'PHPSERVER_WORKER_RPC_BOOTSTRAP' => $root . '/tests/Fixture/slow-handlers.php',
            'TEST_SLOW_SECONDS' => '0.4',
        ]);
        $this->server->waitUntilReady();
        $pending = $this->rpcAsync($address, 'Demo.slow', '[]');
        usleep(100_000);
        $this->server->signal(SIGTERM);
        $result = $this->finish($pending);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("\"slow\"\n", $result['stdout']);
        $code = $this->server->stop();
        $this->server = null;
        self::assertSame(0, $code);
    }

    public function testDrainTimeoutKillsTheWorker(): void
    {
        $address = $this->reserve();
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_WORKER_RPC_WORKER_CLASS' => 'PhpServer\\Tests\\Fixture\\HandlerWorker',
            'PHPSERVER_WORKER_RPC_BOOTSTRAP' => $root . '/tests/Fixture/slow-handlers.php',
            'TEST_SLOW_SECONDS' => '30',
        ]);
        $this->server->waitUntilReady();
        $pending = $this->rpcAsync($address, 'Demo.slow', '[]');
        usleep(100_000);
        $this->server->signal(SIGTERM);
        $this->server->waitFor('drain_timeout=1', 8.0);
        $this->finish($pending);
        $code = $this->server->waitUntilStopped(3.0);
        if ($code === null) {
            $output = $this->server->output();
            $this->server->stop();
            $this->server = null;
            self::fail("master was still running after the drain timeout\n" . $output);
        }
        $this->server = null;
        self::assertSame(0, $code);
    }

    public function testReloadKeepsCallsWorking(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '2',
        ]);
        $this->server->waitUntilReady(2);
        $this->server->signal(SIGUSR2);
        $failures = [];
        for ($index = 0; $index < 20; ++$index) {
            $rpc = $this->rpc($address, 'Echo.say', '["' . $index . '"]');
            if ($rpc['code'] !== 0) {
                $failures[] = $rpc['stderr'];
            }
        }
        $this->server->waitFor('master reload finished', 3.0);
        self::assertSame([], $failures);
    }

    public function testMaxRequestsReplacesTheWorker(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
            'PHPSERVER_WORKER_RPC_MAX_REQUESTS' => '50',
        ]);
        $this->server->waitUntilReady();
        $first = $this->server->workerPids()[0];
        for ($index = 0; $index < 50; ++$index) {
            $rpc = $this->rpc($address, 'Echo.say', '["x"]');
            self::assertSame(0, $rpc['code'], $rpc['stderr']);
        }
        $this->server->waitFor('worker_restarts=1', 2.0);
        $replaced = $this->rpcRetry($address, 'Echo.say', '["y"]');
        self::assertSame("\"y\"\n", $replaced['stdout']);
        $pids = $this->server->workerPids();
        self::assertNotSame($first, $pids[count($pids) - 1]);
    }

    public function testDefaultRecycleNeverReplacesTheWorker(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '1',
        ]);
        $this->server->waitUntilReady();
        for ($index = 0; $index < 5; ++$index) {
            $rpc = $this->rpc($address, 'Echo.say', '["x"]');
            self::assertSame(0, $rpc['code'], $rpc['stderr']);
        }
        self::assertCount(1, $this->server->workerPids());
        self::assertStringNotContainsString('worker restarted', $this->server->output());
    }

    public function testMasterDeathEndsTheWorkers(): void
    {
        $address = $this->reserve();
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '2',
        ]);
        $this->server->waitUntilReady(2);
        $workers = $this->server->workerPids();
        posix_kill($this->server->pid(), SIGKILL);
        $deadline = hrtime(true) + 3_000_000_000;
        while (hrtime(true) < $deadline) {
            $alive = false;
            foreach ($workers as $worker) {
                if (posix_kill($worker, 0)) {
                    $alive = true;
                }
            }
            if (!$alive) {
                $this->server->stop();
                $this->server = null;
                foreach ($workers as $worker) {
                    self::assertFalse(@posix_kill($worker, 0));
                }

                return;
            }
            usleep(20_000);
        }
        foreach ($workers as $worker) {
            posix_kill($worker, SIGKILL);
        }
        self::fail('workers stayed up after the master died');
    }

    public function testStatusAndStopUseThePidFile(): void
    {
        $address = $this->reserve();
        $pidFile = sys_get_temp_dir() . '/phpserver-' . bin2hex(random_bytes(4)) . '.pid';
        $root = dirname(__DIR__, 3);
        $this->server = ServerdProcess::start($address, [
            'PHPSERVER_WORKER_RPC_CHILD_COUNT' => '2',
            'PHPSERVER_PID_FILE' => $pidFile,
        ]);
        $this->server->waitUntilReady(2);
        try {
            $stop = $this->runPhp([$root . '/bin/serverd', 'stop', '-c', $root . '/config/server.example.php'], [
                'PHPSERVER_PID_FILE' => $pidFile,
            ]);
            self::assertSame(0, $stop['code'], $stop['stderr']);
            self::assertStringContainsString('master stopped', $stop['stdout']);
        } finally {
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
        }
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

    private function command(int $pid): string
    {
        $text = shell_exec('ps -p ' . $pid . ' -o args=');

        return is_string($text) ? trim($text) : '';
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPhp(array $arguments, array $env = []): array
    {
        return $this->finish($this->open($arguments, $env));
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function rpc(string $address, string $method, string $params): array
    {
        $root = dirname(__DIR__, 3);

        return $this->runPhp([
            $root . '/bin/phpserver-rpc',
            $address,
            $method,
            $params,
            '--timeout=2000',
        ]);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function rpcRetry(string $address, string $method, string $params): array
    {
        $last = ['code' => 1, 'stdout' => '', 'stderr' => ''];
        $deadline = hrtime(true) + 2_000_000_000;
        while (hrtime(true) < $deadline) {
            $last = $this->rpc($address, $method, $params);
            if ($last['code'] === 0) {
                return $last;
            }
            usleep(20_000);
        }

        return $last;
    }

    /**
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function rpcAsync(string $address, string $method, string $params): array
    {
        $root = dirname(__DIR__, 3);

        return $this->open([
            $root . '/bin/phpserver-rpc',
            $address,
            $method,
            $params,
            '--timeout=8000',
        ]);
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
        ], $pipes, dirname(__DIR__, 3), $env === [] ? null : $this->environment($env));
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

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function environment(array $overrides): array
    {
        $base = getenv() ?: [];
        foreach ($overrides as $key => $value) {
            $base[$key] = $value;
        }

        return $base;
    }
}
