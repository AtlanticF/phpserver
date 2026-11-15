<?php

declare(strict_types=1);

namespace PhpServer\Worker;

/**
 * Result of inspecting one connection's read buffer.
 *
 * The worker owns the buffer. A ready result names how many leading bytes
 * form one message; those bytes are removed before the next read, including
 * on a persistent connection.
 *
 * @api
 */
final readonly class Input
{
    private function __construct(
        public InputState $state,
        public int $consumed,
        public string $reply,
        public bool $close,
    ) {}

    public static function needMore(): self
    {
        return new self(InputState::NeedMore, 0, '', false);
    }

    /**
     * @throws \InvalidArgumentException when $consumed is less than 1
     */
    public static function ready(int $consumed): self
    {
        if ($consumed < 1) {
            throw new \InvalidArgumentException('a complete message consumes at least 1 byte');
        }

        return new self(InputState::Ready, $consumed, '', false);
    }

    /**
     * @throws \InvalidArgumentException when $consumed is negative
     */
    public static function failed(int $consumed, string $reply, bool $close): self
    {
        if ($consumed < 0) {
            throw new \InvalidArgumentException('consumed must be >= 0');
        }

        return new self(InputState::Failed, $consumed, $reply, $close);
    }
}
