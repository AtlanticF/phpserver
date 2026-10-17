<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * The five frame kinds of text-rpc/1.
 *
 * @internal
 */
enum FrameKind: string
{
    case Req = 'REQ';
    case Res = 'RES';
    case Err = 'ERR';
    case Ping = 'PING';
    case Pong = 'PONG';

    public function isHeartbeat(): bool
    {
        return $this === self::Ping || $this === self::Pong;
    }
}
