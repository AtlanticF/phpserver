<?php

declare(strict_types=1);

namespace PhpServer\Ipc;

/**
 * Commands on the master/worker socketpair.
 *
 * The bytes on the wire are this integer plus a length-prefixed body.
 * The body is plain text, never PHP `serialize`.
 *
 * @internal
 */
enum Command: int
{
    case Ping = 1;
    case Pong = 2;
    case Stop = 3;
    case Drain = 4;
    case Status = 5;
    case StatusResult = 6;
    case Metrics = 7;
    case MetricsResult = 8;
}
