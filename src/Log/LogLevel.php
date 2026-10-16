<?php

declare(strict_types=1);

namespace PhpServer\Log;

/**
 * Log severity. Values match the PSR-3 level strings.
 *
 * @api
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * Higher means more severe. Debug is 0, emergency is 7.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }

    /**
     * Parse a level name. Case is ignored.
     *
     * @throws \InvalidArgumentException when $value is not one of the eight level names
     */
    public static function parse(string $value): self
    {
        $level = self::tryFrom(strtolower($value));
        if ($level === null) {
            throw new \InvalidArgumentException(
                'log level must be one of debug, info, notice, warning, error, critical, alert, emergency (got "' . $value . '")',
            );
        }

        return $level;
    }
}
