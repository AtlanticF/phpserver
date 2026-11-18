<?php

declare(strict_types=1);

namespace PhpServer;

use PhpServer\Config\Config;
use PhpServer\Log\LoggerInterface;
use PhpServer\Log\StdoutLogger;
use PhpServer\Master\Master;

/**
 * Process entry for a prefork server.
 *
 * `bin/serverd` and any later caller start here. The master binds, forks, and
 * drains. Admin HTTP is not part of this class.
 *
 * @api
 */
final class Server
{
    public const string VERSION = 'dev';

    private function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public static function fromConfig(Config $config, ?LoggerInterface $logger = null): self
    {
        $log = $config->log;

        return new self(
            $config,
            $logger ?? new StdoutLogger($log->level, $log->json, null, null, $log->timezone),
        );
    }

    /**
     * Run the master until it drains. Returns the process exit status.
     */
    public function run(): int
    {
        return (new Master($this->config, $this->logger))->run();
    }
}
