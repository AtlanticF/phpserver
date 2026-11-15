<?php

declare(strict_types=1);

namespace PhpServer\Worker;

/**
 * Bytes to write for one message, and whether the connection ends afterwards.
 *
 * @api
 */
final readonly class Output
{
    public function __construct(
        public string $bytes,
        public bool $close,
    ) {}
}
