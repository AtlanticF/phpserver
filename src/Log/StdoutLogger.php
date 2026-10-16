<?php

declare(strict_types=1);

namespace PhpServer\Log;

use PhpServer\Support\ClockInterface;
use PhpServer\Support\SystemClock;

/**
 * Writes one log record per line to stdout (or a stream), as text or JSON.
 *
 * JSON objects use `ts` (ISO-8601 with microseconds and a numeric offset),
 * `level`, `message`, and `request_id` when a request id is in effect.
 * Remaining context is nested under `context`.
 *
 * The request id comes from {@see self::setRequestId()} or from a context
 * entry `request_id`. A context value wins. Values that are not legal
 * trace-ids are dropped so a log line cannot be split or injected.
 *
 * @internal
 */
final class StdoutLogger implements LoggerInterface
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly ClockInterface $clock;

    private readonly \DateTimeZone $timezone;

    /** @var resource */
    private $stream;

    private ?string $requestId = null;

    /**
     * @param resource|null $stream stdout when null
     */
    public function __construct(
        private readonly LogLevel $minimumLevel = LogLevel::Info,
        private readonly bool $json = false,
        ?ClockInterface $clock = null,
        mixed $stream = null,
        string $timezone = 'UTC',
    ) {
        $this->clock = $clock ?? new SystemClock();
        try {
            $this->timezone = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid timezone: ' . $timezone, 0, $e);
        }
        if ($stream === null) {
            $this->stream = STDOUT;
        } elseif (is_resource($stream)) {
            $this->stream = $stream;
        } else {
            throw new \InvalidArgumentException('Log stream must be a resource or null');
        }
    }

    /**
     * @throws \InvalidArgumentException when $requestId is not a legal trace-id
     */
    public function setRequestId(RequestId|string|null $requestId): void
    {
        if ($requestId === null) {
            $this->requestId = null;

            return;
        }
        if ($requestId instanceof RequestId) {
            $this->requestId = $requestId->value;

            return;
        }
        $this->requestId = RequestId::fromString($requestId)->value;
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Alert, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    public function log(LogLevel|string $level, string|\Stringable $message, array $context = []): void
    {
        $resolved = $this->resolveLevel($level);
        if ($resolved->severity() < $this->minimumLevel->severity()) {
            return;
        }

        $requestId = $this->requestId;
        if (isset($context['request_id']) && is_string($context['request_id']) && RequestId::isValid($context['request_id'])) {
            $requestId = $context['request_id'];
        }
        unset($context['request_id']);

        $rendered = $this->interpolate((string) $message, $context, $requestId);
        $timestamp = $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d\TH:i:s.uP');
        $line = $this->json
            ? $this->encodeJson($timestamp, $resolved, $rendered, $requestId, $context)
            : $this->encodeText($timestamp, $resolved, $rendered, $requestId, $context);

        $written = fwrite($this->stream, $line . "\n");
        if ($written === false) {
            throw new \RuntimeException('Failed to write log record');
        }
    }

    private function resolveLevel(LogLevel|string $level): LogLevel
    {
        if ($level instanceof LogLevel) {
            return $level;
        }

        return LogLevel::parse($level);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array &$context, ?string $requestId): string
    {
        if ($requestId !== null && str_contains($message, '{request_id}')) {
            $message = str_replace('{request_id}', $requestId, $message);
        }
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $token = '{' . $key . '}';
            if (!str_contains($message, $token)) {
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable && $value !== null) {
                continue;
            }
            $replacements[$token] = (string) $value;
            unset($context[$key]);
        }

        return strtr($message, $replacements);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeJson(
        string $timestamp,
        LogLevel $level,
        string $message,
        ?string $requestId,
        array $context,
    ): string {
        $record = [
            'ts' => $timestamp,
            'level' => $level->value,
            'message' => $message,
        ];
        if ($requestId !== null) {
            $record['request_id'] = $requestId;
        }
        if ($context !== []) {
            $record['context'] = $this->normalize($context);
        }

        try {
            return json_encode($record, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            $fallback = [
                'ts' => $timestamp,
                'level' => LogLevel::Error->value,
                'message' => 'failed to encode log record',
                'context' => ['reason' => $e->getMessage()],
            ];
            if ($requestId !== null) {
                $fallback['request_id'] = $requestId;
            }

            return json_encode($fallback, self::JSON_FLAGS);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeText(
        string $timestamp,
        LogLevel $level,
        string $message,
        ?string $requestId,
        array $context,
    ): string {
        $line = $timestamp . ' ' . $level->value;
        if ($requestId !== null) {
            $line .= ' request_id=' . $requestId;
        }
        $line .= ' ' . $this->singleLine($message);
        foreach ($context as $key => $value) {
            $line .= ' ' . $key . '=' . $this->singleLine($this->formatValue($value));
        }

        return $line;
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }
        $encoded = json_encode(
            $this->normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) ? $encoded : '[unencodable]';
    }

    private function singleLine(string $value): string
    {
        return str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $value);
    }

    /**
     * @return mixed
     */
    private function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[max-depth]';
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item, $depth + 1);
            }

            return $normalized;
        }

        return '[' . get_debug_type($value) . ']';
    }
}
