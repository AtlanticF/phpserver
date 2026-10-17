<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\ProtocolInterface;

/**
 * Incremental text-rpc/1 frame parser.
 *
 * A header is held as soon as its line is complete, and `len` is checked
 * against the body limit before any body byte is copied out. LF and CRLF are
 * both accepted, including a CR that arrives in an earlier chunk than its LF.
 * The body is not inspected: invalid UTF-8 and invalid JSON are body-level
 * errors for {@see JsonCodec}, and the frame itself still decodes.
 *
 * @internal
 */
final class FrameDecoder implements ProtocolInterface
{
    public const int HEADER_LINE_BYTES = 256;

    public const int DEFAULT_MAX_BODY_BYTES = 1048576;

    public const int HARD_MAX_BODY_BYTES = 67108864;

    private string $buffer = '';

    /** @var list<Frame> */
    private array $ready = [];

    private ?Header $pending = null;

    private string $body = '';

    private bool $sawCr = false;

    private ?ProtocolException $failure = null;

    public function __construct(private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES)
    {
        if ($maxBodyBytes < 1 || $maxBodyBytes > self::HARD_MAX_BODY_BYTES) {
            throw new \InvalidArgumentException(
                'maxBodyBytes must be between 1 and ' . self::HARD_MAX_BODY_BYTES,
            );
        }
    }

    public function feed(string $bytes): void
    {
        $this->guard();
        if ($bytes !== '') {
            $this->buffer .= $bytes;
        }
        $this->pumpOrFail();
    }

    public function next(): ?Frame
    {
        $this->pumpOrFail();
        if ($this->ready === []) {
            return null;
        }

        return array_shift($this->ready);
    }

    public function bytesNeeded(): ?int
    {
        $this->pumpOrFail();
        if ($this->ready !== []) {
            return 0;
        }
        if ($this->pending === null) {
            return null;
        }
        $bodyLeft = $this->pending->len - strlen($this->body);
        if ($bodyLeft < 0) {
            $bodyLeft = 0;
        }

        return $bodyLeft + 1;
    }

    private function guard(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    private function pumpOrFail(): void
    {
        $this->guard();
        try {
            $this->pump();
        } catch (ProtocolException $exception) {
            $this->failure = $exception;
            $this->buffer = '';
            $this->pending = null;
            $this->body = '';
            $this->ready = [];
            $this->sawCr = false;
            throw $exception;
        }
    }

    private function pump(): void
    {
        while (true) {
            if ($this->pending === null) {
                $header = $this->tryHeader();
                if ($header === null) {
                    return;
                }
                if ($header->len > $this->maxBodyBytes) {
                    throw $this->fail(
                        ErrorCode::FrameTooLarge,
                        'Frame too large (len ' . $header->len . ', max ' . $this->maxBodyBytes . ')',
                        true,
                    );
                }
                $this->pending = $header;
                $this->body = '';
                $this->sawCr = false;
            }

            $need = $this->pending->len - strlen($this->body);
            if ($need > 0) {
                if ($this->buffer === '') {
                    return;
                }
                $take = min($need, strlen($this->buffer));
                $this->body .= substr($this->buffer, 0, $take);
                $this->buffer = substr($this->buffer, $take);
                if (strlen($this->body) < $this->pending->len) {
                    return;
                }
            }

            if ($this->buffer === '') {
                return;
            }
            $first = $this->buffer[0];
            if ($this->sawCr) {
                if ($first !== "\n") {
                    throw $this->invalidHeader('Frame is missing the trailing newline');
                }
                $this->buffer = substr($this->buffer, 1);
                $this->finishPending();
                continue;
            }
            if ($first === "\n") {
                $this->buffer = substr($this->buffer, 1);
                $this->finishPending();
                continue;
            }
            if ($first === "\r") {
                if (strlen($this->buffer) === 1) {
                    $this->buffer = '';
                    $this->sawCr = true;

                    return;
                }
                if ($this->buffer[1] !== "\n") {
                    throw $this->invalidHeader('Frame is missing the trailing newline');
                }
                $this->buffer = substr($this->buffer, 2);
                $this->finishPending();
                continue;
            }
            throw $this->invalidHeader('Frame is missing the trailing newline');
        }
    }

    private function finishPending(): void
    {
        $header = $this->pending;
        if ($header === null) {
            throw new \LogicException('no pending frame');
        }
        $this->ready[] = new Frame($header, $this->body);
        $this->pending = null;
        $this->body = '';
        $this->sawCr = false;
    }

    private function tryHeader(): ?Header
    {
        $length = strlen($this->buffer);
        $window = min($length, self::HEADER_LINE_BYTES + 2);
        $slice = substr($this->buffer, 0, $window);
        $lf = strpos($slice, "\n");
        if ($lf === false) {
            if ($length > self::HEADER_LINE_BYTES + 1) {
                throw $this->tooLarge('Header line exceeds ' . self::HEADER_LINE_BYTES . ' bytes');
            }
            if ($length === self::HEADER_LINE_BYTES + 1 && $this->buffer[self::HEADER_LINE_BYTES] !== "\r") {
                throw $this->tooLarge('Header line exceeds ' . self::HEADER_LINE_BYTES . ' bytes');
            }

            return null;
        }

        $lineEnd = $lf;
        if ($lf > 0 && $slice[$lf - 1] === "\r") {
            $lineEnd = $lf - 1;
        }
        $line = substr($slice, 0, $lineEnd);
        if (strlen($line) > self::HEADER_LINE_BYTES) {
            throw $this->tooLarge('Header line exceeds ' . self::HEADER_LINE_BYTES . ' bytes');
        }
        $header = $this->parseHeaderLine($line);
        $this->buffer = substr($this->buffer, $lf + 1);

        return $header;
    }

    private function parseHeaderLine(string $line): Header
    {
        if ($line === '') {
            throw $this->invalidHeader('Invalid frame header');
        }
        $parts = explode(' ', $line);
        if (in_array('', $parts, true)) {
            throw $this->invalidHeader('Invalid frame header');
        }
        $magic = $parts[0];
        if ($magic !== Version::MAGIC) {
            if (preg_match('/^TRPC\/[0-9]+$/', $magic) === 1) {
                throw $this->fail(ErrorCode::UnsupportedVersionOrCodec, 'Unsupported protocol version', true);
            }
            throw $this->invalidHeader('Invalid frame header');
        }
        if (count($parts) < 5) {
            throw $this->invalidHeader('Invalid frame header');
        }

        $kind = FrameKind::tryFrom($parts[1]);
        if ($kind === null) {
            throw $this->invalidHeader('Unknown frame kind');
        }
        $id = $this->parseId($parts[2]);
        $codec = $parts[3];
        $len = $this->parseLen($parts[4]);
        [$traceId, $meta] = $this->parseTail(array_slice($parts, 5));

        try {
            return new Header($kind, $id, $codec, $len, $traceId, $meta);
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalidHeader($exception->getMessage());
        }
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function parseTail(array $tokens): array
    {
        $traceId = null;
        $meta = [];
        foreach ($tokens as $index => $token) {
            $equals = strpos($token, '=');
            if ($equals === false) {
                if ($index !== 0 || $traceId !== null) {
                    throw $this->invalidHeader('trace-id must follow len and appear once');
                }
                if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $token) !== 1) {
                    throw $this->invalidHeader('Invalid trace-id');
                }
                $traceId = $token;
                continue;
            }
            if (preg_match('/^[a-z0-9-]{1,32}=[\x21-\x7E]{1,128}$/', $token) !== 1) {
                throw $this->invalidHeader('Invalid extension field');
            }
            $key = substr($token, 0, $equals);
            $value = substr($token, $equals + 1);
            if (isset($meta[$key])) {
                throw $this->invalidHeader('Duplicate extension field');
            }
            $meta[$key] = $value;
        }

        return [$traceId, $meta];
    }

    private function parseId(string $token): int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,18})$/', $token) !== 1) {
            throw $this->invalidHeader('Invalid id');
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($token) > strlen($max) || (strlen($token) === strlen($max) && $token > $max)) {
            throw $this->invalidHeader('Invalid id');
        }

        return (int) $token;
    }

    private function parseLen(string $token): int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/', $token) !== 1) {
            throw $this->invalidHeader('Invalid len');
        }

        return (int) $token;
    }

    private function invalidHeader(string $message): ProtocolException
    {
        return $this->fail(ErrorCode::InvalidRequest, $message, true);
    }

    private function tooLarge(string $message): ProtocolException
    {
        return $this->fail(ErrorCode::FrameTooLarge, $message, true);
    }

    private function fail(ErrorCode $code, string $message, bool $frameLevel): ProtocolException
    {
        return new ProtocolException($message, $code->value, null, $frameLevel);
    }
}
