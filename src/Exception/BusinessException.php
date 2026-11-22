<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * The stock {@see AgentFailure}. Business code can throw this, or implement
 * the interface on its own exception type.
 *
 * @api
 */
final class BusinessException extends \RuntimeException implements AgentFailure, PhpServerException
{
    public function __construct(
        private readonly AgentError $error,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error->message, $error->code, $previous);
    }

    public function agentError(): AgentError
    {
        return $this->error;
    }
}
