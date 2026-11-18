<?php

declare(strict_types=1);

namespace PhpServer\RateLimit;

/**
 * What to do with one message before `dealProcess`.
 *
 * @api
 */
enum Decision
{
    /**
     * Run the handler.
     */
    case Allow;

    /**
     * Skip the handler. The RPC worker replies `-32001` and keeps the connection.
     */
    case Reject;

    /**
     * Skip the handler. The RPC worker replies a null result and keeps the connection.
     */
    case Degrade;
}
