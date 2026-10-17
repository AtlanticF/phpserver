<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * One text-rpc/1 frame: header plus the exact body bytes.
 *
 * The body is opaque here. UTF-8 and JSON are checked by the codec, not by
 * the frame layer, so a binary body round-trips.
 *
 * @internal
 */
final readonly class Frame
{
    public function __construct(
        public Header $header,
        public string $body,
    ) {
        if (strlen($body) !== $header->len) {
            throw new \InvalidArgumentException(
                'body length ' . strlen($body) . ' does not match header len ' . $header->len,
            );
        }
    }
}
