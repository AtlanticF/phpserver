<?php

declare(strict_types=1);

namespace PhpServer\Worker;

use PhpServer\Config\ConfigLoader;
use PhpServer\Event\SelectLoop;
use PhpServer\Exception\ConfigException;
use PhpServer\Log\LogLevel;
use PhpServer\Log\StdoutLogger;
use PhpServer\Metrics\Counters;
use PhpServer\Support\SystemClock;

/**
 * `bin/worker`. One foreground process, no master. Logs go to stdout.
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
          worker --config <file> <name> [-v]
          worker -c <file> <name> [-v]

        Options:
          -h, --help         Show this help and exit
          -c, --config       Configuration file
          -v                 Debug logs on stdout

        The process accepts on that worker's listen address until SIGTERM or
        SIGINT. child_count and the admin port are ignored. max_requests and
        max_work_time still drain this process when they are not 0.

        Exit codes:
          0   stopped
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
            fwrite(STDERR, 'worker: ' . $exception->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigException $exception) {
            fwrite(STDERR, 'worker: configuration error: ' . $exception->getMessage() . "\n");

            return self::EXIT_ERROR;
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'worker: ' . $exception->getMessage() . "\n");

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
            fwrite(STDERR, 'worker: warning: ' . $warning . "\n");
        }
        $workerConfig = $config->worker($parsed['name']);
        $level = $parsed['verbose'] ? LogLevel::Debug : $config->log->level;
        $logger = new StdoutLogger($level, $config->log->json, null, null, $config->log->timezone);
        $clock = new SystemClock();
        $loop = new SelectLoop($clock);
        $worker = Factory::create(
            $workerConfig,
            $logger,
            $loop,
            $clock,
            new Counters(),
            $config->log->bodyTruncateBytes,
        );
        $worker->run();

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     *
     * @return array{help: bool, verbose: bool, config: string, name: string}
     */
    private function parse(array $args): array
    {
        $help = false;
        $verbose = false;
        $config = null;
        $positionals = [];
        $count = count($args);
        for ($index = 0; $index < $count; ++$index) {
            $arg = $args[$index];
            if ($arg === '--') {
                for ($rest = $index + 1; $rest < $count; ++$rest) {
                    $positionals[] = $args[$rest];
                }
                break;
            }
            if ($arg === '-h' || $arg === '--help') {
                $help = true;
                continue;
            }
            if ($arg === '-v') {
                $verbose = true;
                continue;
            }
            if ($arg === '-c' || $arg === '--config' || str_starts_with($arg, '--config=')) {
                $config = $this->optionValue($args, $index, $arg === '-c' ? '-c' : '--config');
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('unknown option ' . $arg);
            }
            $positionals[] = $arg;
        }
        if ($help) {
            return ['help' => true, 'verbose' => $verbose, 'config' => '', 'name' => ''];
        }
        if ($config === null || $config === '') {
            throw new \InvalidArgumentException('give --config and a worker name');
        }
        if (count($positionals) !== 1 || $positionals[0] === '') {
            throw new \InvalidArgumentException('give exactly one worker name');
        }

        return [
            'help' => false,
            'verbose' => $verbose,
            'config' => $config,
            'name' => $positionals[0],
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
