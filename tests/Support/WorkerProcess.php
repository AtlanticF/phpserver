<?php

declare(strict_types=1);

namespace PhpServer\Tests\Support;

/**
 * A foreground `bin/worker` child.
 */
final class WorkerProcess
{
    private bool $stopped = false;

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
     * @param array<string, string> $env PHPSERVER_* overrides for the child
     */
    public static function start(string $config, string $name, string $address, array $env = [], bool $verbose = false): self
    {
        $root = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $root . '/bin/worker', '--config', $config, $name];
        if ($verbose) {
            array_splice($command, 2, 0, ['-v']);
        }
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, self::environment($env));
        if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            throw new \RuntimeException('could not start bin/worker');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[1], $pipes[2], $address);
    }

    public function waitUntilReady(): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        while (hrtime(true) < $deadline) {
            $this->drain();
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                throw new \RuntimeException($this->failure('worker exited before listening'));
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

        throw new \RuntimeException($this->failure('worker did not listen on ' . $this->address));
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

    public function stop(): int
    {
        if ($this->stopped) {
            return 0;
        }
        $this->stopped = true;
        $this->drain();
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, SIGTERM);
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
                $this->drain();
            } while (hrtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($this->process, SIGKILL);
            }
        }
        $this->drain();
        fclose($this->stdout);
        fclose($this->stderr);
        $code = proc_close($this->process);

        return $code === -1 ? $status['exitcode'] : $code;
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
