<?php

declare(strict_types=1);

namespace PhpServer\Tests\Fixture;

use PhpServer\Log\RequestId;
use PhpServer\Protocol\TextRpc\ErrorBody;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Protocol\TextRpc\Frame;
use PhpServer\Protocol\TextRpc\FrameDecoder;
use PhpServer\Protocol\TextRpc\FrameEncoder;
use PhpServer\Protocol\TextRpc\FrameKind;
use PhpServer\Protocol\TextRpc\Header;
use PhpServer\Protocol\TextRpc\JsonCodec;
use PhpServer\Protocol\TextRpc\Version;

/**
 * Minimal text-rpc/1 peer for client and CLI tests.
 *
 * Listens on 127.0.0.1:0, writes `host:port` to stdout, then serves one
 * connection. Modes: echo, error, once-error, bad-frame, slow, shutdown.
 */
final class TextRpcFixture
{
    private readonly FrameDecoder $decoder;

    private readonly FrameEncoder $encoder;

    private readonly JsonCodec $codec;

    private int $requests = 0;

    public function __construct()
    {
        $this->decoder = new FrameDecoder();
        $this->encoder = new FrameEncoder();
        $this->codec = new JsonCodec();
    }

    public function serve(string $mode): int
    {
        if (!in_array($mode, ['echo', 'error', 'once-error', 'bad-frame', 'slow', 'shutdown'], true)) {
            fwrite(STDERR, 'unknown mode ' . $mode . "\n");

            return 2;
        }
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($server)) {
            $message = is_string($errstr) && $errstr !== '' ? $errstr : 'listen failed';
            fwrite(STDERR, $message . "\n");

            return 1;
        }
        $name = stream_socket_get_name($server, false);
        if (!is_string($name)) {
            fclose($server);
            fwrite(STDERR, "listen address unavailable\n");

            return 1;
        }
        fwrite(STDOUT, $name . "\n");
        fflush(STDOUT);

        $client = stream_socket_accept($server, 5);
        fclose($server);
        if (!is_resource($client)) {
            return 0;
        }
        stream_set_blocking($client, true);
        stream_set_timeout($client, 1);
        $deadline = hrtime(true) + 8_000_000_000;
        try {
            while (hrtime(true) < $deadline) {
                $frame = $this->decoder->next();
                if ($frame === null) {
                    $chunk = fread($client, 8192);
                    if (!is_string($chunk) || $chunk === '') {
                        $meta = stream_get_meta_data($client);
                        if ($meta['timed_out'] && !feof($client)) {
                            continue;
                        }
                        break;
                    }
                    $this->decoder->feed($chunk);
                    continue;
                }
                if (!$this->handle($client, $frame, $mode)) {
                    break;
                }
            }
        } finally {
            fclose($client);
        }

        return 0;
    }

    /**
     * @param resource $client
     */
    private function handle($client, Frame $frame, string $mode): bool
    {
        if ($frame->header->kind === FrameKind::Ping) {
            $this->write($client, $this->encoder->encode(new Frame(new Header(
                FrameKind::Pong,
                $frame->header->id,
                Version::EMPTY_CODEC,
                0,
                $frame->header->traceId,
                [],
            ), '')));

            return true;
        }
        if ($frame->header->kind !== FrameKind::Req) {
            return true;
        }
        $this->requests++;
        if ($mode === 'bad-frame') {
            $this->write($client, "HELLO\n");

            return false;
        }
        if ($mode === 'slow') {
            usleep(1_500_000);
        }
        $request = $this->codec->decodeRequest($frame->body);
        $traceId = $frame->header->traceId ?? RequestId::generate()->value;
        if ($mode === 'error' || ($mode === 'once-error' && $this->requests === 1)) {
            $body = $this->codec->encodeError(new ErrorBody(
                ErrorCode::MethodNotFound->value,
                'Method not found',
                ['method' => $request->method],
                true,
            ));
            $this->reply($client, FrameKind::Err, $frame->header->id, $traceId, $body);

            return true;
        }
        if ($mode === 'shutdown') {
            $body = $this->codec->encodeError(new ErrorBody(
                ErrorCode::ShuttingDown->value,
                'Shutting down',
                null,
                false,
            ));
            $this->reply($client, FrameKind::Err, $frame->header->id, $traceId, $body);

            return false;
        }

        $result = null;
        if (is_array($request->params) && array_is_list($request->params)) {
            $result = $request->params[0] ?? null;
        } elseif ($request->params !== null) {
            $result = $request->params;
        }
        $body = $this->codec->encodeResult($result);
        $this->reply($client, FrameKind::Res, $frame->header->id, $traceId, $body);

        return true;
    }

    /**
     * @param resource $client
     */
    private function reply($client, FrameKind $kind, int $id, string $traceId, string $body): void
    {
        $frame = new Frame(new Header($kind, $id, JsonCodec::NAME, strlen($body), $traceId, []), $body);
        $this->write($client, $this->encoder->encode($frame));
    }

    /**
     * @param resource $client
     */
    private function write($client, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($client, substr($bytes, $offset));
            if (!is_int($written) || $written === 0) {
                return;
            }
            $offset += $written;
        }
    }
}
