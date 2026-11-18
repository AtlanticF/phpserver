<?php

declare(strict_types=1);

namespace PhpServer\Tests\Support;

/**
 * A foreground `bin/serverd`.
 */
final class ServerdProcess
{
    private bool $stopped = false;

    /**
     * Real exit status. `proc_get_status()` reaps the child, so a later
     * `proc_close()` returns -1 and must not replace this value.
     */
    private ?int $exitCode = null;

    private string $output = '';

    private string $errorOutput = '';

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    private function __construct(
        private $process,
        private $stdout,
        private $stderr,
        public readonly string $address,
    ) {}

    /**
     * @param array<string, string> $env
     */
    public static function start(string $address, array $env = []): self
    {
        $root = dirname(__DIR__, 2);
        $command = [
            PHP_BINARY,
            $root . '/bin/serverd',
            'start',
            '--foreground',
            '--config',
            $root . '/config/server.example.php',
        ];
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, self::environment(array_merge([
            'PHPSERVER_WORKER_RPC_LISTEN' => $address,
            'PHPSERVER_ADMIN_ENABLED' => 'false',
        ], $env)));
        if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            throw new \RuntimeException('could not start bin/serverd');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[1], $pipes[2], $address);
    }

    public function waitUntilReady(int $workers = 1): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        while (hrtime(true) < $deadline) {
            $this->drain();
            $status = $this->status();
            if (!$status['running']) {
                throw new \RuntimeException($this->failure('serverd exited before listening'));
            }
            if (!str_contains($this->output, 'master started') || count($this->workerPids()) < $workers) {
                usleep(20_000);

                continue;
            }
            $errno = 0;
            $errstr = '';
            $socket = $this->quietly(function () use (&$errno, &$errstr): mixed {
                return stream_socket_client('tcp://' . $this->address, $errno, $errstr, 0.1);
            });
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }

        throw new \RuntimeException($this->failure('serverd did not listen on ' . $this->address));
    }

    public function output(): string
    {
        $this->drain();

        return $this->output;
    }

    public function errorOutput(): string
    {
        $this->drain();

        return $this->errorOutput;
    }

    public function pid(): int
    {
        if (preg_match('/master started pid=(\d+)/', $this->output()) !== 1) {
            throw new \RuntimeException($this->failure('master pid was not logged'));
        }

        return (int) preg_replace('/.*master started pid=(\d+).*/s', '$1', $this->output);
    }

    /**
     * @return list<int>
     */
    public function workerPids(): array
    {
        preg_match_all('/worker started\b.*?pid=(\d+)/', $this->output(), $started);
        preg_match_all('/worker restarted\b.*?pid=(\d+)/', $this->output(), $restarted);
        $pids = [];
        foreach (array_merge($started[1], $restarted[1]) as $pid) {
            $pids[] = (int) $pid;
        }

        return $pids;
    }

    public function signal(int $signo): void
    {
        posix_kill($this->pid(), $signo);
    }

    public function waitFor(string $needle, float $seconds): void
    {
        $deadline = hrtime(true) + (int) round($seconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            if (str_contains($this->output(), $needle)) {
                return;
            }
            $status = $this->status();
            if (!$status['running']) {
                break;
            }
            usleep(20_000);
        }

        throw new \RuntimeException($this->failure('timed out waiting for ' . $needle));
    }

    public function stop(): int
    {
        if ($this->stopped) {
            return $this->exitCode ?? 0;
        }
        $this->stopped = true;
        $this->drain();
        $status = $this->status();
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
            $deadline = hrtime(true) + 8_000_000_000;
            do {
                $status = $this->status();
                if (!$status['running']) {
                    break;
                }
                usleep(20_000);
                $this->drain();
            } while (hrtime(true) < $deadline);
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
                $this->killChildren($status['pid']);
                $this->status();
            }
        }
        $this->drain();
        fclose($this->stdout);
        fclose($this->stderr);
        $closed = proc_close($this->process);
        if ($this->exitCode !== null) {
            return $this->exitCode;
        }

        return $closed;
    }

    /**
     * @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}
     */
    private function status(): array
    {
        $status = proc_get_status($this->process);
        if ($status['running'] || $this->exitCode !== null) {
            return $status;
        }
        if ($status['signaled']) {
            $this->exitCode = 128 + $status['termsig'];
        } elseif ($status['exitcode'] !== -1) {
            $this->exitCode = $status['exitcode'];
        }

        return $status;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function environment(array $overrides): array
    {
        $base = getenv() ?: [];
        foreach (array_keys($base) as $key) {
            if (str_starts_with($key, 'PHPSERVER_')) {
                unset($base[$key]);
            }
        }

        return array_merge($base, $overrides);
    }

    private function killChildren(int $pid): void
    {
        $text = @file_get_contents('/proc/' . $pid . '/task/' . $pid . '/children');
        if (!is_string($text) || trim($text) === '') {
            return;
        }
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $child) {
            if (preg_match('/^[1-9][0-9]*$/', $child) === 1) {
                posix_kill((int) $child, SIGKILL);
            }
        }
    }

    private function drain(): void
    {
        $this->output .= $this->readPipe($this->stdout);
        $this->errorOutput .= $this->readPipe($this->stderr);
    }

    /**
     * @param resource $pipe
     */
    private function readPipe($pipe): string
    {
        $text = '';
        while (true) {
            $chunk = $this->quietly(static function () use ($pipe): mixed {
                return fread($pipe, 8192);
            });
            if (!is_string($chunk) || $chunk === '') {
                return $text;
            }
            $text .= $chunk;
        }
    }

    private function failure(string $message): string
    {
        $this->drain();
        $detail = trim($this->errorOutput . "\n" . $this->output);

        return $detail === '' ? $message : $message . "\n" . $detail;
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
}
