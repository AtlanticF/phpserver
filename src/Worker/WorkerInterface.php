<?php

declare(strict_types=1);

namespace PhpServer\Worker;

/**
 * The two hooks a business worker implements.
 *
 * `dealInput` looks at the unread buffer and either waits, names one complete
 * message, or rejects the bytes. `dealProcess` turns that message into bytes
 * to write. Accept, timeouts, and the connection table stay in
 * {@see AbstractWorker}.
 *
 * @api
 */
interface WorkerInterface
{
    public function dealInput(string $buffer): Input;

    public function dealProcess(string $message): Output;
}
