<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * text-rpc/1 error codes.
 *
 * Some codes always close the connection, some never do, and two are used
 * for both a bad header and a bad body. {@see self::closesConnection()}
 * resolves that. Codes this enum does not list (business codes, and the
 * reserved ranges) do not close the connection.
 *
 * @api
 */
enum ErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case InvalidParams = -32602;
    case InternalError = -32603;
    case RateLimited = -32001;
    case FrameTooLarge = -32002;
    case Timeout = -32003;
    case UnsupportedVersionOrCodec = -32004;
    case ShuttingDown = -32005;
    case Overloaded = -32006;

    /**
     * Whether a reply with this code ends the connection.
     *
     * Pass `$atFrame` as true when the header, the magic, or the frame
     * boundary failed. InvalidRequest and UnsupportedVersionOrCodec close
     * only in that case; a bad body or an unsupported codec keeps the
     * connection. Every other code ignores `$atFrame`.
     */
    public function closesConnection(bool $atFrame = false): bool
    {
        return match ($this) {
            self::FrameTooLarge, self::ShuttingDown => true,
            self::InvalidRequest, self::UnsupportedVersionOrCodec => $atFrame,
            default => false,
        };
    }

    /**
     * True when every use of this code closes the connection.
     * False when it depends on `$atFrame`, or the connection stays open.
     */
    public function alwaysClosesConnection(): bool
    {
        return match ($this) {
            self::FrameTooLarge, self::ShuttingDown => true,
            default => false,
        };
    }

    public static function closesFor(int $code, bool $atFrame = false): bool
    {
        $known = self::tryFrom($code);
        if ($known === null) {
            return false;
        }

        return $known->closesConnection($atFrame);
    }
}
