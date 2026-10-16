<?php

declare(strict_types=1);

namespace PhpServer\Config;

/**
 * Validated admin-port settings. The admin server itself is not part of M1.
 *
 * @internal
 */
final readonly class AdminConfig
{
    public function __construct(
        public bool $enabled,
        public string $listen,
        public string $host,
        public int $port,
        public string $token,
        public bool $metrics,
    ) {}
}
