<?php

declare(strict_types=1);

namespace PhpServer\Config;

use PhpServer\Log\LogLevel;

/**
 * Validated log settings.
 *
 * @internal
 */
final readonly class LogConfig
{
    public function __construct(
        public LogLevel $level,
        public bool $json,
        public string $timezone,
        public int $bodyTruncateBytes,
    ) {}
}
