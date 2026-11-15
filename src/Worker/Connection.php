<?php

declare(strict_types=1);

namespace PhpServer\Worker;

/**
 * One accepted socket and the bytes still belonging to it.
 *
 * @internal
 */
final class Connection
{
    public string $readBuffer = '';

    public string $writeBuffer = '';

    public ?int $idleTimer = null;

    public ?int $recvTimer = null;

    public ?int $sendTimer = null;

    public bool $closing = false;

    /**
     * @param resource $stream
     */
    public function __construct(public mixed $stream) {}
}
