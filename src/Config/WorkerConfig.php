<?php

declare(strict_types=1);

namespace PhpServer\Config;

/**
 * Validated settings for one named worker.
 *
 * Timeouts are seconds. `maxRequests` and `maxWorkTime` of 0 mean the recycle
 * valve is off. Body and frame limits follow text-rpc/1: the body hard cap is
 * 64 MiB, and the frame buffer also holds a 256-byte header line plus CR LF
 * around the header and the body (260 bytes).
 *
 * @internal
 */
final readonly class WorkerConfig
{
    public const int MAX_BODY_BYTES = 67108864;

    public const int DEFAULT_MAX_BODY_BYTES = 1048576;

    /**
     * Header line (256) plus a CR LF after the header and a CR LF after the body.
     */
    public const int FRAME_OVERHEAD_BYTES = 260;

    public function __construct(
        public string $name,
        public string $listen,
        public string $host,
        public int $port,
        public string $workerClass,
        public string $bootstrap,
        public int $childCount,
        public int $backlog,
        public int $maxConnections,
        public float $recvTimeout,
        public float $sendTimeout,
        public float $idleTimeout,
        public float $processTimeout,
        public int $maxBodyBytes,
        public int $maxFrameBytes,
        public int $pipelineBacklog,
        public int $maxRequests,
        public float $maxWorkTime,
        public float $recycleStagger,
    ) {}
}
