<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * One text-rpc/1 header line, without the trailing EOL.
 *
 * `len` is the body size in bytes. `meta` holds extension fields in wire
 * order; unknown keys are kept so a later version can read them, and this
 * version ignores them.
 *
 * @internal
 */
final readonly class Header
{
    /**
     * @param array<string, string> $meta
     */
    public function __construct(
        public FrameKind $kind,
        public int $id,
        public string $codec,
        public int $len,
        public ?string $traceId,
        public array $meta = [],
    ) {
        if ($id < 0) {
            throw new \InvalidArgumentException('id must be >= 0');
        }
        if ($len < 0) {
            throw new \InvalidArgumentException('len must be >= 0');
        }
        if ($traceId !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $traceId) !== 1) {
            throw new \InvalidArgumentException('trace-id must match [A-Za-z0-9._-]{1,64}');
        }
        $heartbeat = $kind->isHeartbeat();
        if ($heartbeat) {
            if ($codec !== Version::EMPTY_CODEC || $len !== 0) {
                throw new \InvalidArgumentException('PING and PONG require codec "-" and len 0');
            }
        } elseif ($codec === Version::EMPTY_CODEC || preg_match('/^[a-z0-9-]{1,16}$/', $codec) !== 1) {
            throw new \InvalidArgumentException('codec must be a token of 1 to 16 lowercase letters, digits, or hyphens');
        }
        foreach ($meta as $key => $value) {
            if (preg_match('/^[a-z0-9-]{1,32}$/', $key) !== 1) {
                throw new \InvalidArgumentException('extension key must match [a-z0-9-]{1,32}');
            }
            if (preg_match('/^[\x21-\x7E]{1,128}$/', $value) !== 1) {
                throw new \InvalidArgumentException('extension value must be 1 to 128 visible ASCII characters');
            }
        }
    }
}
