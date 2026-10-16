<?php

declare(strict_types=1);

namespace PhpServer\Log;

/**
 * Correlation id carried in logs and, later, in text-rpc/1 trace-id.
 *
 * The character set matches the protocol trace-id: one to 64 of
 * `A-Z a-z 0-9 . _ -`. Generated ids look like `r-` plus 16 hex characters.
 *
 * @api
 */
final readonly class RequestId
{
    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self('r-' . bin2hex(random_bytes(8)));
    }

    /**
     * @throws \InvalidArgumentException when $value is not a legal trace-id
     */
    public static function fromString(string $value): self
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException(
                'request id must match [A-Za-z0-9._-]{1,64} (got "' . $value . '")',
            );
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
