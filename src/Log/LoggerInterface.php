<?php

declare(strict_types=1);

namespace PhpServer\Log;

/**
 * Logger whose methods match PSR-3, without depending on psr/log.
 *
 * `$level` of {@see self::log()} accepts the PSR-3 level string or {@see LogLevel}.
 * An unknown level throws `\InvalidArgumentException`.
 * Placeholder tokens `{key}` in the message are replaced from `$context` when the
 * value is a scalar, null, or `Stringable`.
 *
 * @api
 */
interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string|\Stringable $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     *
     * @throws \InvalidArgumentException when $level is not a known level
     */
    public function log(LogLevel|string $level, string|\Stringable $message, array $context = []): void;
}
