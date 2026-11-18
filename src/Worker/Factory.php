<?php

declare(strict_types=1);

namespace PhpServer\Worker;

use PhpServer\Config\WorkerConfig;
use PhpServer\Event\LoopInterface;
use PhpServer\Log\LoggerInterface;
use PhpServer\Metrics\Counters;
use PhpServer\Support\ClockInterface;

/**
 * Builds the configured worker class and runs its bootstrap file.
 *
 * @internal
 */
final class Factory
{
    public static function create(
        WorkerConfig $config,
        LoggerInterface $logger,
        LoopInterface $loop,
        ClockInterface $clock,
        Counters $counters,
        int $bodyTruncateBytes,
    ): AbstractWorker {
        $class = $config->workerClass;
        if (!class_exists($class)) {
            throw new \RuntimeException('worker class is not loaded: ' . $class);
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isSubclassOf(AbstractWorker::class) || $reflection->isAbstract()) {
            throw new \RuntimeException(
                'worker class must be a concrete subclass of ' . AbstractWorker::class . ': ' . $class,
            );
        }
        $worker = $reflection->newInstance(
            $config,
            $logger,
            $loop,
            $clock,
            $counters,
            $bodyTruncateBytes,
        );
        self::loadBootstrap($config->bootstrap, $worker);

        return $worker;
    }

    public static function loadBootstrap(string $path, AbstractWorker $worker): void
    {
        if ($path === '') {
            return;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('bootstrap is not readable: ' . $path);
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        if ($loaded instanceof \Closure) {
            $loaded($worker);

            return;
        }
        if ($loaded === 1 || $loaded === null) {
            return;
        }

        throw new \RuntimeException('bootstrap must return a closure or nothing');
    }
}
