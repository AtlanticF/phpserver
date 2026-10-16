<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * The optional pre-process limiter rejected the call.
 *
 * The default `$code` is the text-rpc/1 RateLimited code (-32001).
 * The connection stays open; this is not a process failure.
 *
 * @api
 */
final class RateLimitedException extends \RuntimeException implements PhpServerException
{
    public function __construct(string $message = 'Rate limited', int $code = -32001, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
