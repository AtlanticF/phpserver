<?php

declare(strict_types=1);

namespace PhpServer\RateLimit;

use PhpServer\Support\ClockInterface;

/**
 * A token bucket built from the standard clock.
 *
 * It is not installed by the server. An application passes it to
 * `AbstractWorker::setLimiter()` from a bootstrap file. `perSecond` is how
 * fast tokens come back. `capacity` is how many can be saved.
 *
 * @api
 */
final class TokenBucket implements LimiterInterface
{
    private float $tokens;

    private int $updatedNs;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly float $perSecond,
        private readonly float $capacity,
    ) {
        if (!is_finite($this->perSecond) || $this->perSecond < 0) {
            throw new \InvalidArgumentException('perSecond must be >= 0');
        }
        if (!is_finite($this->capacity) || $this->capacity < 1) {
            throw new \InvalidArgumentException('capacity must be >= 1');
        }
        $this->tokens = $this->capacity;
        $this->updatedNs = $this->clock->monotonic();
    }

    public function decide(string $message): Decision
    {
        $now = $this->clock->monotonic();
        $elapsed = $now - $this->updatedNs;
        if ($elapsed > 0 && $this->perSecond > 0) {
            $this->tokens = min(
                $this->capacity,
                $this->tokens + ($elapsed / 1_000_000_000) * $this->perSecond,
            );
        }
        $this->updatedNs = $now;
        if ($this->tokens < 1) {
            return Decision::Reject;
        }
        $this->tokens -= 1;

        return Decision::Allow;
    }
}
