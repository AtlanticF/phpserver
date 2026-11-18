<?php

declare(strict_types=1);

namespace PhpServer\Ipc;

use PhpServer\Exception\ProcessException;

/**
 * Fixed 8-byte header plus a raw body.
 *
 * `PIP1` | command | flags | length uint16 big-endian | body.
 * Length is the body size in bytes. The body is not inspected and is never
 * produced with PHP `serialize`.
 *
 * @internal
 */
final class Codec
{
    public const string MAGIC = 'PIP1';

    public const int HEADER_BYTES = 8;

    public const int MAX_BODY_BYTES = 65535;

    private string $buffer = '';

    public function encode(Message $message): string
    {
        $length = strlen($message->body);
        if ($length > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('ipc body exceeds ' . self::MAX_BODY_BYTES . ' bytes');
        }

        return self::MAGIC
            . chr($message->command->value)
            . "\0"
            . pack('n', $length)
            . $message->body;
    }

    public function feed(string $bytes): void
    {
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }
    }

    /**
     * @throws ProcessException when the header is not an IPC frame
     */
    public function next(): ?Message
    {
        if (strlen($this->buffer) < self::HEADER_BYTES) {
            return null;
        }
        if (substr($this->buffer, 0, 4) !== self::MAGIC) {
            throw new ProcessException('ipc frame has a bad magic');
        }
        $command = Command::tryFrom(ord($this->buffer[4]));
        if ($command === null) {
            throw new ProcessException('ipc frame has a bad command');
        }
        $unpacked = unpack('nlength', substr($this->buffer, 6, 2));
        if ($unpacked === false || !is_int($unpacked['length'])) {
            throw new ProcessException('ipc frame has a bad length');
        }
        $length = $unpacked['length'];
        if (strlen($this->buffer) < self::HEADER_BYTES + $length) {
            return null;
        }
        $body = substr($this->buffer, self::HEADER_BYTES, $length);
        $this->buffer = substr($this->buffer, self::HEADER_BYTES + $length);

        return new Message($command, $body);
    }
}
