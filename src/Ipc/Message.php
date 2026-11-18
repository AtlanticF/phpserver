<?php

declare(strict_types=1);

namespace PhpServer\Ipc;

/**
 * One IPC command and its raw body.
 *
 * @internal
 */
final readonly class Message
{
    public function __construct(
        public Command $command,
        public string $body,
    ) {}
}
