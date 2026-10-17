<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * A frame is illegal, too large, or uses an unknown protocol version.
 *
 * `$code` is a text-rpc/1 error code when one is already known (for example
 * -32600 or -32002), and 0 when it is not. `$frameLevel` is true when the
 * connection must close: the frame boundary is no longer trustworthy, or the
 * code always closes. Body-level failures leave it false so the connection
 * stays open. The worker maps this exception to an ERR frame; it is not a
 * reason to exit the process.
 *
 * @api
 */
final class ProtocolException extends \RuntimeException implements PhpServerException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $frameLevel = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * True when the peer should send ERR (if it still can) and close.
     * False when the connection stays open and the next frame can be read.
     */
    public function isFrameLevel(): bool
    {
        return $this->frameLevel;
    }
}
