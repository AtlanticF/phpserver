<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * Writes one text-rpc/1 frame. The sender uses LF, never CRLF.
 *
 * @internal
 */
final class FrameEncoder
{
    public function encode(Frame $frame): string
    {
        $header = $frame->header;
        $line = Version::MAGIC
            . ' ' . $header->kind->value
            . ' ' . $header->id
            . ' ' . $header->codec
            . ' ' . $header->len;
        if ($header->traceId !== null) {
            $line .= ' ' . $header->traceId;
        }
        foreach ($header->meta as $key => $value) {
            $line .= ' ' . $key . '=' . $value;
        }
        if (strlen($line) > FrameDecoder::HEADER_LINE_BYTES) {
            throw new \InvalidArgumentException(
                'header line exceeds ' . FrameDecoder::HEADER_LINE_BYTES . ' bytes',
            );
        }

        return $line . "\n" . $frame->body . "\n";
    }
}
