<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * Machine-readable business error carried in a text-rpc `ERR` `data` object.
 *
 * The shape follows the AIP-193 / A2A `ErrorInfo` fields an agent can branch
 * on (`reason`, `domain`) and the MCP rule that a tool failure must say
 * whether the same call should be repeated (`retryable`). `message` is for
 * the model to read. `param` and `hint` name what to change. Nothing here is
 * a stack trace.
 *
 * `code` is a text-rpc business code, an integer greater than or equal to 1.
 *
 * @api
 */
final readonly class AgentError
{
    public function __construct(
        public int $code,
        public string $message,
        public string $reason,
        public string $domain,
        public bool $retryable = false,
        public ?string $param = null,
        public ?string $hint = null,
    ) {
        if ($code < 1 || $code > 999_999) {
            throw new \InvalidArgumentException('business error code must be from 1 to 999999');
        }
        self::boundedText($message, 'message');
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $reason) !== 1) {
            throw new \InvalidArgumentException('reason must be UPPER_SNAKE, at most 64 characters');
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $domain) !== 1) {
            throw new \InvalidArgumentException('domain must be a short lowercase token');
        }
        if ($param !== null && preg_match('/^[A-Za-z][A-Za-z0-9_.]{0,63}$/', $param) !== 1) {
            throw new \InvalidArgumentException('param must be a short field name');
        }
        if ($hint !== null) {
            self::boundedText($hint, 'hint');
        }
    }

    /**
     * @return array<string, bool|string>
     */
    public function data(): array
    {
        $data = [
            'reason' => $this->reason,
            'domain' => $this->domain,
            'retryable' => $this->retryable,
        ];
        if ($this->param !== null) {
            $data['param'] = $this->param;
        }
        if ($this->hint !== null) {
            $data['hint'] = $this->hint;
        }

        return $data;
    }

    private static function boundedText(string $value, string $name): void
    {
        if ($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException($name . ' must be 1 to 200 characters without control characters');
        }
    }
}
