<?php

declare(strict_types=1);

namespace PhpServer\Examples;

use PhpServer\Log\LoggerInterface;
use PhpServer\Log\LogLevel;

/**
 * Adapt a PSR-3 logger (Monolog, for example) to PhpServer\Log\LoggerInterface.
 *
 * This file is an example, not part of the package autoload. Copy it into the
 * application. It needs the suggested psr/log package, which phpserver does not
 * require.
 */
final class Psr3LoggerAdapter implements LoggerInterface
{
    public function __construct(private readonly \Psr\Log\LoggerInterface $psr) {}

    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Alert, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel|string $level, string|\Stringable $message, array $context = []): void
    {
        $this->psr->log($level instanceof LogLevel ? $level->value : $level, $message, $context);
    }
}
