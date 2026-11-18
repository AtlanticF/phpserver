<?php

declare(strict_types=1);

namespace PhpServer\Master;

use PhpServer\Config\WorkerConfig;
use PhpServer\Ipc\Codec;

/**
 * One forked worker and the master's end of its socketpair.
 *
 * @internal
 */
final class Slot
{
    public int $misses = 0;

    public bool $reloading = false;

    public bool $killed = false;

    public readonly Codec $codec;

    /**
     * @param resource $ipc
     * @param resource $listen
     */
    public function __construct(
        public int $pid,
        public mixed $ipc,
        public mixed $listen,
        public WorkerConfig $config,
        public string $name,
    ) {
        $this->codec = new Codec();
    }
}
