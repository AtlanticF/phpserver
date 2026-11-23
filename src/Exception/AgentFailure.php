<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * A business failure an agent is supposed to read and act on.
 *
 * Unexpected exceptions stay an internal error. Implement this, or throw
 * {@see BusinessException}, when the caller gave a bad id, a missing record,
 * or anything else it can correct. The worker copies {@see AgentError} onto
 * the text-rpc `ERR` frame and keeps the connection open.
 *
 * @api
 */
interface AgentFailure extends \Throwable
{
    public function agentError(): AgentError;
}
