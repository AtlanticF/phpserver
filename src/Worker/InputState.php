<?php

declare(strict_types=1);

namespace PhpServer\Worker;

/**
 * What {@see WorkerInterface::dealInput()} decided about the read buffer.
 *
 * @api
 */
enum InputState
{
    /**
     * The buffer does not contain a full message yet.
     */
    case NeedMore;

    /**
     * The first `$consumed` bytes are one message. The worker drops them
     * before {@see WorkerInterface::dealProcess()}.
     */
    case Ready;

    /**
     * The bytes are illegal. `$reply` is written, and `$close` says whether
     * the connection ends after that write.
     */
    case Failed;
}
