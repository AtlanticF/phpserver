<?php

declare(strict_types=1);

namespace PhpServer\Protocol;

use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\TextRpc\Frame;

/**
 * Streaming text-rpc/1 decoder.
 *
 * `feed()` appends bytes from the socket. `next()` returns one complete frame,
 * or null when more bytes are required. An illegal frame throws
 * {@see ProtocolException} carrying the text-rpc/1 code; it never returns a
 * stand-in frame. After that exception the decoder stays closed.
 *
 * `bytesNeeded()` is the other half of "how many bytes / already complete":
 * 0 when a full frame is buffered, a positive count once the header is known,
 * and null while the header line is unfinished (the size is not known until
 * LF). The positive count assumes a trailing LF. A CR in that position needs
 * one more byte for the following LF.
 *
 * @api
 */
interface ProtocolInterface
{
    /**
     * Append bytes. Empty input is ignored.
     *
     * @throws ProtocolException when the bytes contain an illegal frame
     */
    public function feed(string $bytes): void;

    /**
     * Pop the next complete frame.
     *
     * @throws ProtocolException when the buffered bytes are an illegal frame
     */
    public function next(): ?Frame;

    /**
     * Additional bytes required before {@see self::next()} can return a frame.
     *
     * 0 means a frame is already complete. Null means the header line is not
     * finished. A positive integer is the body bytes still missing plus a
     * trailing LF, or 1 when that LF (or the LF after a CR) is all that remains.
     *
     * @throws ProtocolException when the decoder has already failed
     */
    public function bytesNeeded(): ?int;
}
