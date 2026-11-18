<?php

declare(strict_types=1);

namespace PhpServer\Master;

use PhpServer\Config\Config;
use PhpServer\Config\ConfigLoader;
use PhpServer\Exception\ConfigException;
use PhpServer\Server;

/**
 * `bin/serverd`. `start --foreground` is the process that stays in front.
 * A start without it daemonizes. The pid file is written only when configured.
 *
 * @internal
 */
final class Console
{
    public const int EXIT_OK = 0;

    public const int EXIT_ERROR = 1;

    public const int EXIT_USAGE = 64;

    public const string HELP = <<<'HELP'
        Usage:
          serverd start [--foreground] [-c <file>]
          serverd stop [-c <file>]
          serverd restart [--foreground] [-c <file>]
          serverd reload [-c <file>]
          serverd status [-c <file>]

        Options:
          -h, --help         Show this help and exit
          -c, --config       Configuration file (default config/server.example.php)
          --foreground       Stay in the foreground and log to stdout

        status reports whether the master pid is alive and how many workers
        it has. stop, restart, reload, and status need pid_file. An empty
        pid_file writes nothing.

        Exit codes:
          0   success
          1   configuration or runtime error
          64  usage error

        HELP;

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            return $this->execute($argv);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, 'serverd: ' . $exception->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigException $exception) {
            fwrite(STDERR, 'serverd: configuration error: ' . $exception->getMessage() . "\n");

            return self::EXIT_ERROR;
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'serverd: ' . $exception->getMessage() . "\n");

            return self::EXIT_ERROR;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function execute(array $argv): int
    {
        $parsed = $this->parse(array_slice($argv, 1));
        if ($parsed['help']) {
            fwrite(STDOUT, self::HELP);

            return self::EXIT_OK;
        }
        $config = (new ConfigLoader())->load($parsed['config']);
        foreach ($config->warnings as $warning) {
            fwrite(STDERR, 'serverd: warning: ' . $warning . "\n");
        }

        return match ($parsed['command']) {
            'start' => $this->start($config, $parsed['foreground']),
            'stop' => $this->stop($config),
            'restart' => $this->restart($config, $parsed['foreground']),
            'reload' => $this->signal($config, SIGUSR2),
            'status' => $this->status($config),
            default => throw new \InvalidArgumentException('unknown command ' . $parsed['command']),
        };
    }

    private function start(Config $config, bool $foreground): int
    {
        $this->refuseIfRunning($config);
        if (!$foreground) {
            $this->daemonize();
        }
        $this->writePid($config->pidFile);
        try {
            return Server::fromConfig($config)->run();
        } finally {
            $this->removePid($config->pidFile);
        }
    }

    private function stop(Config $config): int
    {
        $pid = $this->readPid($config);
        if (!posix_kill($pid, SIGTERM)) {
            throw new \RuntimeException('could not signal master ' . $pid);
        }
        $deadline = hrtime(true) + 10_000_000_000;
        while (hrtime(true) < $deadline) {
            if (!$this->running($pid)) {
                fwrite(STDOUT, "master stopped pid=" . $pid . "\n");

                return self::EXIT_OK;
            }
            usleep(20_000);
        }

        throw new \RuntimeException('master ' . $pid . ' did not exit');
    }

    private function restart(Config $config, bool $foreground): int
    {
        if ($this->alivePid($config) !== null) {
            $this->stop($config);
        }

        return $this->start($config, $foreground);
    }

    private function signal(Config $config, int $signo): int
    {
        $pid = $this->readPid($config);
        if (!posix_kill($pid, $signo)) {
            throw new \RuntimeException('could not signal master ' . $pid);
        }
        fwrite(STDOUT, "signaled pid=" . $pid . "\n");

        return self::EXIT_OK;
    }

    private function status(Config $config): int
    {
        if (!$config->admin->enabled) {
            throw new \RuntimeException('admin is disabled');
        }
        $body = $this->adminGet($config, '/status');
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('admin status was not JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('admin status was not a JSON object');
        }
        fwrite(STDOUT, "master alive\n");
        fwrite(STDOUT, 'version ' . $this->statusField($decoded, 'version') . "\n");
        fwrite(STDOUT, 'uptime ' . $this->statusField($decoded, 'uptime') . "\n");
        fwrite(STDOUT, 'fds ' . $this->statusField($decoded, 'fds') . "\n");
        $workers = $decoded['workers'] ?? [];
        if (is_array($workers)) {
            foreach ($workers as $worker) {
                if (!is_array($worker)) {
                    continue;
                }
                fwrite(STDOUT, $this->statusField($worker, 'name')
                    . ' pid=' . $this->statusField($worker, 'pid')
                    . ' state=' . $this->statusField($worker, 'state')
                    . ' requests=' . $this->statusField($worker, 'requests')
                    . "\n");
            }
        }

        return self::EXIT_OK;
    }

    /**
     * @param array<mixed> $fields
     */
    private function statusField(array $fields, string $name): string
    {
        $value = $fields[$name] ?? '';
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }

        return str_replace(["\r", "\n"], '', $value);
    }

    private function adminGet(Config $config, string $path): string
    {
        $admin = $config->admin;
        $host = str_contains($admin->host, ':') ? '[' . $admin->host . ']' : $admin->host;
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_client(
            'tcp://' . $host . ':' . $admin->port,
            $errno,
            $errstr,
            2,
        );
        if (!is_resource($socket)) {
            $detail = $errstr !== '' ? $errstr : 'connect failed';

            throw new \RuntimeException('admin is not reachable: ' . $detail);
        }
        $request = 'GET ' . $path . " HTTP/1.1\r\nHost: " . $admin->host . "\r\nConnection: close\r\n";
        if ($admin->token !== '') {
            $request .= 'Authorization: Bearer ' . $admin->token . "\r\n";
        }
        $request .= "\r\n";
        fwrite($socket, $request);
        stream_set_timeout($socket, 2);
        $raw = stream_get_contents($socket);
        fclose($socket);
        if (!is_string($raw) || !str_contains($raw, "\r\n\r\n")) {
            throw new \RuntimeException('admin returned an incomplete response');
        }
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        if (!str_starts_with($head, 'HTTP/1.1 200')) {
            throw new \RuntimeException('admin status failed: ' . strtok($head, "\r\n"));
        }

        return $body;
    }

    private function refuseIfRunning(Config $config): void
    {
        if ($this->alivePid($config) !== null) {
            throw new \RuntimeException('master is already running');
        }
    }

    private function alivePid(Config $config): ?int
    {
        if ($config->pidFile === '' || !is_file($config->pidFile)) {
            return null;
        }
        $pid = $this->pidFromFile($config->pidFile);
        if ($pid === null || !$this->running($pid)) {
            return null;
        }

        return $pid;
    }

    /**
     * A zombie still answers `kill -0` until its parent reaps it. It is not running.
     */
    private function running(int $pid): bool
    {
        if (!posix_kill($pid, 0)) {
            return false;
        }
        $stat = file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat) || preg_match('/\)\s+([A-Z])/', $stat, $matches) !== 1) {
            return false;
        }

        return $matches[1] !== 'Z';
    }

    private function readPid(Config $config): int
    {
        if ($config->pidFile === '') {
            throw new \RuntimeException('pid file is not configured');
        }
        $pid = $this->alivePid($config);
        if ($pid === null) {
            throw new \RuntimeException('master is not running');
        }

        return $pid;
    }

    private function pidFromFile(string $path): ?int
    {
        $text = file_get_contents($path);
        if (!is_string($text)) {
            return null;
        }
        $text = trim($text);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    private function writePid(string $path): void
    {
        if ($path === '') {
            return;
        }
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException('pid file directory is missing: ' . $directory);
        }
        if (file_put_contents($path, (string) getmypid() . "\n") === false) {
            throw new \RuntimeException('could not write pid file: ' . $path);
        }
    }

    private function removePid(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }

    private function daemonize(): void
    {
        $first = pcntl_fork();
        if ($first < 0) {
            throw new \RuntimeException('fork failed');
        }
        if ($first > 0) {
            exit(self::EXIT_OK);
        }
        if (posix_setsid() === -1) {
            throw new \RuntimeException('setsid failed');
        }
        $second = pcntl_fork();
        if ($second < 0) {
            throw new \RuntimeException('fork failed');
        }
        if ($second > 0) {
            exit(self::EXIT_OK);
        }
        $this->detachStdio();
    }

    private function detachStdio(): void
    {
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $input = fopen('/dev/null', 'rb');
        $output = fopen('/dev/null', 'wb');
        $error = fopen('/dev/null', 'wb');
        if (!is_resource($input) || !is_resource($output) || !is_resource($error)) {
            throw new \RuntimeException('could not open /dev/null');
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{help: bool, foreground: bool, config: string, command: string}
     */
    private function parse(array $args): array
    {
        $help = false;
        $foreground = false;
        $config = 'config/server.example.php';
        $command = null;
        $count = count($args);
        for ($index = 0; $index < $count; ++$index) {
            $arg = $args[$index];
            if ($arg === '-h' || $arg === '--help') {
                $help = true;
                continue;
            }
            if ($arg === '--foreground') {
                $foreground = true;
                continue;
            }
            if ($arg === '-c' || $arg === '--config' || str_starts_with($arg, '--config=')) {
                $config = $this->optionValue($args, $index, $arg === '-c' ? '-c' : '--config');
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('unknown option ' . $arg);
            }
            if ($command !== null) {
                throw new \InvalidArgumentException('unexpected argument ' . $arg);
            }
            $command = $arg;
        }
        if ($help) {
            return ['help' => true, 'foreground' => $foreground, 'config' => $config, 'command' => ''];
        }
        if ($command === null) {
            throw new \InvalidArgumentException('give a command: start, stop, restart, reload, or status');
        }
        if (!in_array($command, ['start', 'stop', 'restart', 'reload', 'status'], true)) {
            throw new \InvalidArgumentException('unknown command ' . $command);
        }

        return [
            'help' => false,
            'foreground' => $foreground,
            'config' => $config,
            'command' => $command,
        ];
    }

    /**
     * @param list<string> $args
     */
    private function optionValue(array $args, int &$index, string $name): string
    {
        $arg = $args[$index];
        $prefix = $name . '=';
        if ($name !== '-c' && str_starts_with($arg, $prefix)) {
            $value = substr($arg, strlen($prefix));
            if ($value === '') {
                throw new \InvalidArgumentException($name . ' needs a value');
            }

            return $value;
        }
        ++$index;
        if (!isset($args[$index]) || str_starts_with($args[$index], '-')) {
            throw new \InvalidArgumentException($name . ' needs a value');
        }

        return $args[$index];
    }
}
