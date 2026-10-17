<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * text-rpc/1 magic. The protocol version is not the library's SemVer.
 *
 * @internal
 */
final class Version
{
    public const string MAGIC = 'TRPC/1';

    /**
     * Codec used only by PING and PONG. The body length is then 0.
     */
    public const string EMPTY_CODEC = '-';

    private function __construct() {}
}
