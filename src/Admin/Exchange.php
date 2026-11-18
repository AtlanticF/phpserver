<?php

declare(strict_types=1);

namespace PhpServer\Admin;

/**
 * One admin HTTP client. It is closed as soon as the response is written,
 * or when the read deadline passes.
 *
 * @internal
 */
final class Exchange
{
    public string $buffer = '';

    public ?int $timer = null;

    /**
     * @param resource $stream
     */
    public function __construct(public mixed $stream) {}
}
