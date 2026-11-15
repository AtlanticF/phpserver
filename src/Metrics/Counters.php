<?php

declare(strict_types=1);

namespace PhpServer\Metrics;

/**
 * In-process counters for one worker.
 *
 * The names are the contract. This class does not render Prometheus text;
 * that arrives with the admin port.
 *
 * @internal
 */
final class Counters
{
    private int $accept = 0;

    private int $requests = 0;

    private int $errors = 0;

    private int $connRejected = 0;

    private int $idleTimeout = 0;

    public function incrementAccept(): void
    {
        ++$this->accept;
    }

    public function incrementRequests(): void
    {
        ++$this->requests;
    }

    public function incrementErrors(): void
    {
        ++$this->errors;
    }

    public function incrementConnRejected(): void
    {
        ++$this->connRejected;
    }

    public function incrementIdleTimeout(): void
    {
        ++$this->idleTimeout;
    }

    public function get(string $name): int
    {
        return match ($name) {
            'accept' => $this->accept,
            'requests' => $this->requests,
            'errors' => $this->errors,
            'conn_rejected' => $this->connRejected,
            'idle_timeout' => $this->idleTimeout,
            default => throw new \InvalidArgumentException('unknown counter "' . $name . '"'),
        };
    }

    /**
     * @return array{accept: int, requests: int, errors: int, conn_rejected: int, idle_timeout: int}
     */
    public function snapshot(): array
    {
        return [
            'accept' => $this->accept,
            'requests' => $this->requests,
            'errors' => $this->errors,
            'conn_rejected' => $this->connRejected,
            'idle_timeout' => $this->idleTimeout,
        ];
    }
}
