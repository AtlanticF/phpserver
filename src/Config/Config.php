<?php

declare(strict_types=1);

namespace PhpServer\Config;

use PhpServer\Exception\ConfigException;

/**
 * Immutable snapshot of a validated configuration file.
 *
 * Unknown keys are not copied onto this object. They are listed in `$warnings`.
 *
 * @internal
 */
final readonly class Config
{
    /**
     * @param array<string, WorkerConfig> $workers
     * @param list<string>                 $warnings
     */
    public function __construct(
        public LogConfig $log,
        public AdminConfig $admin,
        public string $pidFile,
        public string $user,
        public string $group,
        public array $workers,
        public array $warnings,
    ) {}

    /**
     * @throws ConfigException when $name was not in the file
     */
    public function worker(string $name): WorkerConfig
    {
        if (!isset($this->workers[$name])) {
            throw new ConfigException('Unknown worker "' . $name . '"');
        }

        return $this->workers[$name];
    }
}
