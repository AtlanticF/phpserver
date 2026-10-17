<?php

declare(strict_types=1);

namespace PhpServer\Client;

use PhpServer\Exception\ProtocolException;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Protocol\TextRpc\Frame;
use PhpServer\Protocol\TextRpc\FrameDecoder;
use PhpServer\Protocol\TextRpc\FrameEncoder;
use PhpServer\Protocol\TextRpc\FrameKind;
use PhpServer\Protocol\TextRpc\Header;
use PhpServer\Protocol\TextRpc\JsonCodec;
use PhpServer\Protocol\TextRpc\Version;
use PhpServer\Support\ClockInterface;
use PhpServer\Support\SystemClock;

/**
 * Blocking text-rpc/1 client. One call is in flight at a time; v1 does not
 * pipeline. Deadlines are monotonic nanoseconds from {@see ClockInterface}.
 * `connect()` takes a timeout in seconds, the same unit as `stream_socket_client`.
 * `call()` and `ping()` take milliseconds and fall back to the connect timeout.
 *
 * A PING that arrives while a reply is outstanding is answered with PONG and
 * does not complete the call. Any other unexpected kind, or a mismatched id,
 * closes the connection.
 *
 * @api
 */
final class Client
{
    private const int READ_CHUNK = 8192;

    private readonly ClockInterface $clock;

    private readonly JsonCodec $codec;

    private readonly FrameEncoder $encoder;

    private readonly int $maxBodyBytes;

    private int $ioTimeoutNs = 30_000_000_000;

    private int $nextId = 1;

    /** @var resource|null */
    private $socket = null;

    private ?FrameDecoder $decoder = null;

    /** @var (\Closure(string, string): void)|null */
    private ?\Closure $wireTap = null;

    private ?string $lastWarning = null;

    public function __construct(
        ?ClockInterface $clock = null,
        int $maxBodyBytes = FrameDecoder::DEFAULT_MAX_BODY_BYTES,
    ) {
        if ($maxBodyBytes < 1 || $maxBodyBytes > FrameDecoder::HARD_MAX_BODY_BYTES) {
            throw new \InvalidArgumentException(
                'maxBodyBytes must be between 1 and ' . FrameDecoder::HARD_MAX_BODY_BYTES,
            );
        }
        $this->clock = $clock ?? new SystemClock();
        $this->codec = new JsonCodec();
        $this->encoder = new FrameEncoder();
        $this->maxBodyBytes = $maxBodyBytes;
    }

    /**
     * @param \Closure(string $direction, string $bytes): void $tap
     *        `$direction` is `send` or `recv`. `$bytes` are the raw socket bytes.
     */
    public function onWire(\Closure $tap): void
    {
        $this->wireTap = $tap;
    }

    /**
     * @throws NetworkException when the TCP handshake fails or times out
     * @throws \InvalidArgumentException when `$address` or `$timeoutSeconds` is illegal
     * @throws \LogicException when a connection is already open
     */
    public function connect(string $address, float $timeoutSeconds): void
    {
        if ($this->socket !== null) {
            throw new \LogicException('Client is already connected');
        }
        $this->ioTimeoutNs = $this->secondsToNs($timeoutSeconds);
        $remote = $this->remoteAddress($address);
        $errno = 0;
        $errstr = '';
        $socket = $this->quietly(function () use ($remote, $timeoutSeconds, &$errno, &$errstr): mixed {
            return stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
            );
        });
        if (!is_resource($socket)) {
            $message = is_string($errstr) && $errstr !== '' ? $errstr : 'Connect failed';
            throw new NetworkException($message, is_int($errno) ? $errno : 0);
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->decoder = new FrameDecoder($this->maxBodyBytes);
        $this->nextId = 1;
    }

    /**
     * @param array<array-key, mixed>|\stdClass|null $params null omits the key.
     *        A list is a JSON array. An associative array or stdClass is a JSON object.
     *
     * @throws RpcError when the server replies ERR
     * @throws ProtocolException when a frame or a JSON body is illegal
     * @throws NetworkException when the socket times out or closes
     * @throws \InvalidArgumentException when the method, trace id, timeout, or params cannot be sent
     * @throws \LogicException when the client is not connected
     */
    public function call(
        string $method,
        array|\stdClass|null $params = null,
        ?string $traceId = null,
        ?int $timeoutMs = null,
    ): Response {
        $body = $this->codec->encodeRequest($method, $params);
        if (strlen($body) > $this->maxBodyBytes) {
            throw new \InvalidArgumentException('request body exceeds max body bytes');
        }
        $id = $this->allocateId();
        $frame = new Frame(new Header(FrameKind::Req, $id, JsonCodec::NAME, strlen($body), $traceId, []), $body);
        $deadlineNs = $this->deadline($timeoutMs);
        $this->writeFrame($frame, $deadlineNs);
        $reply = $this->readReply($id, FrameKind::Req, $deadlineNs);

        return $this->acceptReply($reply);
    }

    /**
     * @throws ProtocolException when the peer does not answer with a matching PONG
     * @throws NetworkException when the socket times out or closes
     * @throws \LogicException when the client is not connected
     */
    public function ping(?int $timeoutMs = null, ?string $traceId = null): void
    {
        $id = $this->allocateId();
        $frame = new Frame(new Header(FrameKind::Ping, $id, Version::EMPTY_CODEC, 0, $traceId, []), '');
        $deadlineNs = $this->deadline($timeoutMs);
        $this->writeFrame($frame, $deadlineNs);
        $reply = $this->readReply($id, FrameKind::Ping, $deadlineNs);
        if ($reply->header->codec !== Version::EMPTY_CODEC || $reply->body !== '') {
            $this->fail(new ProtocolException(
                'PONG must use codec "-" and an empty body',
                ErrorCode::InvalidRequest->value,
                null,
                true,
            ));
        }
    }

    public function close(): void
    {
        $socket = $this->socket;
        $this->socket = null;
        $this->decoder = null;
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function allocateId(): int
    {
        if ($this->socket === null || $this->decoder === null) {
            throw new \LogicException('Client is not connected');
        }
        if ($this->nextId < 1) {
            throw new \LogicException('request id space is exhausted');
        }
        $id = $this->nextId;
        if ($id === PHP_INT_MAX) {
            $this->nextId = 0;
        } else {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    private function acceptReply(Frame $reply): Response
    {
        $traceId = $reply->header->traceId;
        if ($traceId === null) {
            $this->fail(new ProtocolException(
                'RES and ERR must carry a trace-id',
                ErrorCode::InvalidRequest->value,
                null,
                false,
            ));
        }
        if ($reply->header->codec !== JsonCodec::NAME) {
            $this->fail(new ProtocolException(
                'Unsupported codec "' . $reply->header->codec . '"',
                ErrorCode::UnsupportedVersionOrCodec->value,
                null,
                false,
            ));
        }
        if ($reply->header->kind === FrameKind::Err) {
            $error = $this->codec->decodeError($reply->body);
            if (ErrorCode::closesFor($error->code, false)) {
                $this->close();
            }
            throw new RpcError(
                $error->message,
                $error->code,
                $error->data,
                $error->hasData,
                $reply->header->id,
                $traceId,
                $reply->body,
            );
        }
        if ($reply->header->kind !== FrameKind::Res) {
            $this->fail(new ProtocolException(
                'Expected RES or ERR, got ' . $reply->header->kind->value,
                ErrorCode::InvalidRequest->value,
                null,
                true,
            ));
        }

        return new Response(
            $this->codec->decodeResult($reply->body),
            $reply->header->id,
            $traceId,
            $reply->body,
        );
    }

    private function readReply(int $id, FrameKind $sent, int $deadlineNs): Frame
    {
        while (true) {
            $frame = $this->readFrame($deadlineNs);
            if ($frame->header->kind === FrameKind::Ping) {
                $this->writeFrame($this->pongFor($frame), $deadlineNs);
                continue;
            }
            if ($frame->header->id !== $id) {
                $this->fail(new ProtocolException(
                    'Response id does not match the request',
                    ErrorCode::InvalidRequest->value,
                    null,
                    true,
                ));
            }
            if ($sent === FrameKind::Ping) {
                if ($frame->header->kind !== FrameKind::Pong) {
                    $this->fail(new ProtocolException(
                        'Expected PONG, got ' . $frame->header->kind->value,
                        ErrorCode::InvalidRequest->value,
                        null,
                        true,
                    ));
                }

                return $frame;
            }
            if ($frame->header->kind === FrameKind::Res || $frame->header->kind === FrameKind::Err) {
                return $frame;
            }
            $this->fail(new ProtocolException(
                'Unexpected frame ' . $frame->header->kind->value,
                ErrorCode::InvalidRequest->value,
                null,
                true,
            ));
        }
    }

    private function pongFor(Frame $ping): Frame
    {
        return new Frame(new Header(
            FrameKind::Pong,
            $ping->header->id,
            Version::EMPTY_CODEC,
            0,
            $ping->header->traceId,
            [],
        ), '');
    }

    private function writeFrame(Frame $frame, int $deadlineNs): void
    {
        $this->writeAll($this->encoder->encode($frame), $deadlineNs);
    }

    private function writeAll(string $bytes, int $deadlineNs): void
    {
        $socket = $this->socket();
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $this->await($deadlineNs, true);
            $pending = substr($bytes, $offset);
            $written = $this->quietly(function () use ($socket, $pending): mixed {
                return fwrite($socket, $pending);
            });
            if (!is_int($written)) {
                throw new NetworkException('Write failed');
            }
            if ($written === 0) {
                if (feof($socket)) {
                    throw new NetworkException('Connection closed while writing');
                }
                continue;
            }
            $offset += $written;
        }
        $this->tap('send', $bytes);
    }

    private function readFrame(int $deadlineNs): Frame
    {
        $decoder = $this->decoder;
        if ($decoder === null) {
            throw new \LogicException('Client is not connected');
        }
        while (true) {
            try {
                $frame = $decoder->next();
            } catch (ProtocolException $exception) {
                $this->fail($exception);
            }
            if ($frame !== null) {
                return $frame;
            }
            $this->readSome($decoder, $deadlineNs);
        }
    }

    private function readSome(FrameDecoder $decoder, int $deadlineNs): void
    {
        $socket = $this->socket();
        $this->await($deadlineNs, false);
        $chunk = $this->quietly(function () use ($socket): mixed {
            return fread($socket, self::READ_CHUNK);
        });
        if (!is_string($chunk)) {
            throw new NetworkException('Read failed');
        }
        if ($chunk === '') {
            if (feof($socket)) {
                throw new NetworkException('Connection closed');
            }

            return;
        }
        $this->tap('recv', $chunk);
        try {
            $decoder->feed($chunk);
        } catch (ProtocolException $exception) {
            $this->fail($exception);
        }
    }

    /**
     * @return resource
     */
    private function socket()
    {
        $socket = $this->socket;
        if (!is_resource($socket)) {
            throw new \LogicException('Client is not connected');
        }

        return $socket;
    }

    private function await(int $deadlineNs, bool $write): void
    {
        $socket = $this->socket();
        while (true) {
            $left = $deadlineNs - $this->clock->monotonic();
            if ($left <= 0) {
                throw new NetworkException($write ? 'Write timed out' : 'Read timed out');
            }
            $seconds = intdiv($left, 1_000_000_000);
            $micros = intdiv($left % 1_000_000_000, 1_000);
            if ($seconds === 0 && $micros === 0) {
                throw new NetworkException($write ? 'Write timed out' : 'Read timed out');
            }
            $read = $write ? [] : [$socket];
            $writing = $write ? [$socket] : [];
            $except = null;
            $selected = $this->quietly(function () use (&$read, &$writing, &$except, $seconds, $micros): mixed {
                return stream_select($read, $writing, $except, $seconds, $micros);
            });
            if ($selected === false) {
                $message = $this->lastWarning ?? '';
                if ($this->isInterrupted($message)) {
                    continue;
                }
                throw new NetworkException(
                    ($write ? 'Write' : 'Read') . ' failed'
                    . ($message !== '' ? ': ' . $message : ''),
                );
            }
            if ($selected === 0) {
                continue;
            }

            return;
        }
    }

    /**
     * @param \Closure(): mixed $run
     */
    private function quietly(\Closure $run): mixed
    {
        $this->lastWarning = null;
        set_error_handler(function (int $severity, string $message): bool {
            $this->lastWarning = $message;

            return true;
        });
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }

    private function isInterrupted(string $message): bool
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'interrupted system call')) {
            return true;
        }

        return str_contains($lower, 'unable to select [' . SOCKET_EINTR . ']');
    }

    private function deadline(?int $timeoutMs): int
    {
        $timeoutNs = $timeoutMs === null ? $this->ioTimeoutNs : $this->millisToNs($timeoutMs);
        $now = $this->clock->monotonic();
        if ($timeoutNs > PHP_INT_MAX - $now) {
            throw new \InvalidArgumentException('timeout is too large');
        }

        return $now + $timeoutNs;
    }

    private function secondsToNs(float $seconds): int
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than 0');
        }
        $nanoseconds = round($seconds * 1_000_000_000);
        if ($nanoseconds > PHP_INT_MAX) {
            throw new \InvalidArgumentException('timeout is too large');
        }

        return (int) $nanoseconds;
    }

    private function millisToNs(int $timeoutMs): int
    {
        if ($timeoutMs < 1) {
            throw new \InvalidArgumentException('timeout must be at least 1 ms');
        }
        if ($timeoutMs > intdiv(PHP_INT_MAX, 1_000_000)) {
            throw new \InvalidArgumentException('timeout is too large');
        }

        return $timeoutMs * 1_000_000;
    }

    private function remoteAddress(string $address): string
    {
        if (str_starts_with($address, '[')) {
            if (preg_match('/^\[([0-9A-Fa-f:.]+)\]:(\d+)$/', $address, $matches) !== 1) {
                throw new \InvalidArgumentException('address must be host:port');
            }
            $host = $matches[1];
            $port = $matches[2];
            $remote = 'tcp://[' . $host . ']:' . $this->port($port);
        } else {
            if (preg_match('/^([^:\s]+):(\d+)$/', $address, $matches) !== 1) {
                throw new \InvalidArgumentException('address must be host:port');
            }
            $remote = 'tcp://' . $matches[1] . ':' . $this->port($matches[2]);
        }

        return $remote;
    }

    private function port(string $port): int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,4})$/', $port) !== 1) {
            throw new \InvalidArgumentException('invalid port');
        }
        $number = (int) $port;
        if ($number < 1 || $number > 65535) {
            throw new \InvalidArgumentException('invalid port');
        }

        return $number;
    }

    private function tap(string $direction, string $bytes): void
    {
        $tap = $this->wireTap;
        if ($tap !== null) {
            $tap($direction, $bytes);
        }
    }

    /**
     * @throws ProtocolException
     */
    private function fail(ProtocolException $exception): never
    {
        if ($exception->isFrameLevel()) {
            $this->close();
        }
        throw $exception;
    }
}
