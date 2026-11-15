<?php

declare(strict_types=1);

namespace PhpServer\Worker;

use PhpServer\Exception\ProtocolException;
use PhpServer\Log\BodyTruncator;
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
 * text-rpc/1 worker.
 *
 * Extend this class (it stays abstract so the application class can be
 * final) and register methods with {@see self::on()}, usually from the
 * worker's bootstrap closure. `PING` is answered with `PONG`. A handler
 * exception becomes `-32603` with the same trace-id; the message on the
 * wire is `Internal error` and the exception text stays in the log.
 *
 * @api
 */
abstract class RpcWorker extends AbstractWorker
{
    /**
     * @var array<string, \Closure(?array<array-key, mixed>, string): mixed>
     */
    private array $handlers = [];

    /**
     * @param \Closure(?array<array-key, mixed>, string): mixed $handler
     *        receives the decoded params (null when omitted) and the trace-id
     */
    public function on(string $method, \Closure $handler): void
    {
        if ($method === '') {
            throw new \InvalidArgumentException('method must be a non-empty string');
        }
        if (isset($this->handlers[$method])) {
            throw new \InvalidArgumentException('handler already registered for ' . $method);
        }
        $this->handlers[$method] = $handler;
    }

    public function dealInput(string $buffer): Input
    {
        if ($buffer === '') {
            return Input::needMore();
        }
        $decoder = new FrameDecoder($this->workerConfig()->maxBodyBytes);
        try {
            $decoder->feed($buffer);
            $frame = $decoder->next();
        } catch (ProtocolException $exception) {
            return $this->rejectFrame($buffer, $exception);
        }
        if (!$frame instanceof Frame) {
            return Input::needMore();
        }

        return Input::ready($this->consumed($buffer, $frame));
    }

    public function dealProcess(string $message): Output
    {
        $frame = $this->oneFrame($message);
        $traceId = $frame->header->traceId ?? RequestId::generate()->value;
        if ($frame->header->kind === FrameKind::Ping) {
            return new Output($this->encodePong($frame), false);
        }
        if ($frame->header->kind !== FrameKind::Req) {
            $this->counters()->incrementErrors();

            return new Output($this->encodeError(
                $frame->header->id,
                $traceId,
                ErrorCode::InvalidRequest,
                'Unexpected frame kind',
                null,
            ), true);
        }
        if ($frame->header->codec !== JsonCodec::NAME) {
            $this->counters()->incrementErrors();

            return new Output($this->encodeError(
                $frame->header->id,
                $traceId,
                ErrorCode::UnsupportedVersionOrCodec,
                'Unsupported codec',
                null,
            ), false);
        }

        try {
            $request = (new JsonCodec())->decodeRequest($frame->body);
        } catch (ProtocolException $exception) {
            $this->counters()->incrementErrors();
            $code = ErrorCode::tryFrom($exception->getCode()) ?? ErrorCode::ParseError;
            $text = $code === ErrorCode::ParseError ? 'Parse error' : 'Invalid request';

            return new Output($this->encodeError($frame->header->id, $traceId, $code, $text, null), false);
        }

        $this->logger()->info('rpc {method}', [
            'request_id' => $traceId,
            'method' => $request->method,
        ]);
        $this->logger()->debug('rpc body {body}', [
            'request_id' => $traceId,
            'body' => BodyTruncator::truncate($frame->body, $this->bodyTruncateBytes()),
        ]);

        $handler = $this->handlers[$request->method] ?? null;
        if ($handler === null) {
            $this->counters()->incrementErrors();
            $this->logger()->info('method not found', [
                'request_id' => $traceId,
                'method' => $request->method,
            ]);

            return new Output($this->encodeError(
                $frame->header->id,
                $traceId,
                ErrorCode::MethodNotFound,
                'Method not found',
                ['method' => $request->method],
            ), false);
        }

        $started = $this->clock()->monotonic();
        try {
            $result = $handler($request->params, $traceId);
            $body = (new JsonCodec())->encodeResult($result);
        } catch (\Throwable $exception) {
            $this->counters()->incrementErrors();
            $this->logger()->error('handler failed', [
                'request_id' => $traceId,
                'method' => $request->method,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return new Output($this->encodeError(
                $frame->header->id,
                $traceId,
                ErrorCode::InternalError,
                'Internal error',
                null,
            ), false);
        }
        $elapsed = $this->clock()->monotonic() - $started;
        if ($elapsed < 0) {
            $elapsed = 0;
        }
        $limit = (int) round($this->workerConfig()->processTimeout * 1_000_000_000);
        if ($elapsed > $limit) {
            $this->counters()->incrementErrors();
            $this->logger()->error('handler timed out', [
                'request_id' => $traceId,
                'method' => $request->method,
            ]);

            return new Output($this->encodeError(
                $frame->header->id,
                $traceId,
                ErrorCode::Timeout,
                'Timed out',
                null,
            ), false);
        }

        $header = new Header(FrameKind::Res, $frame->header->id, JsonCodec::NAME, strlen($body), $traceId);

        return new Output((new FrameEncoder())->encode(new Frame($header, $body)), false);
    }

    protected function frameTooLarge(string $buffer): Output
    {
        $traceId = RequestId::generate()->value;
        $this->logger()->error('frame rejected', [
            'request_id' => $traceId,
            'code' => ErrorCode::FrameTooLarge->value,
        ]);

        return new Output($this->encodeError(
            $this->peekId($buffer),
            $traceId,
            ErrorCode::FrameTooLarge,
            'Frame too large',
            ['max_body_bytes' => $this->workerConfig()->maxBodyBytes],
        ), true);
    }

    protected function pipelineOverflow(string $message): Output
    {
        $frame = $this->oneFrame($message);
        $traceId = $frame->header->traceId ?? RequestId::generate()->value;
        $this->counters()->incrementErrors();
        $this->logger()->warning('pipeline backlog exceeded', [
            'request_id' => $traceId,
        ]);

        return new Output($this->encodeError(
            $frame->header->id,
            $traceId,
            ErrorCode::Overloaded,
            'Too many pipelined requests',
            ['pipeline_backlog' => $this->workerConfig()->pipelineBacklog],
        ), false);
    }

    private function rejectFrame(string $buffer, ProtocolException $exception): Input
    {
        $code = ErrorCode::tryFrom($exception->getCode()) ?? ErrorCode::InvalidRequest;
        $traceId = RequestId::generate()->value;
        $message = 'Invalid frame header';
        $data = null;
        if ($code === ErrorCode::FrameTooLarge) {
            $message = 'Frame too large';
            $data = ['max_body_bytes' => $this->workerConfig()->maxBodyBytes];
        } elseif ($code === ErrorCode::UnsupportedVersionOrCodec) {
            $message = 'Unsupported protocol version';
        }
        $this->logger()->error('frame rejected', [
            'request_id' => $traceId,
            'code' => $code->value,
        ]);

        return Input::failed(
            strlen($buffer),
            $this->encodeError($this->peekId($buffer), $traceId, $code, $message, $data),
            true,
        );
    }

    private function oneFrame(string $message): Frame
    {
        $decoder = new FrameDecoder($this->workerConfig()->maxBodyBytes);
        $decoder->feed($message);
        $frame = $decoder->next();
        if (!$frame instanceof Frame) {
            throw new \RuntimeException('worker passed an incomplete frame');
        }

        return $frame;
    }

    private function consumed(string $buffer, Frame $frame): int
    {
        $lf = strpos($buffer, "\n");
        if ($lf === false) {
            throw new \LogicException('complete frame is missing a header newline');
        }
        $offset = $lf + 1 + $frame->header->len;
        if ($offset >= strlen($buffer)) {
            throw new \LogicException('complete frame is missing a trailing newline');
        }
        if ($buffer[$offset] === "\r") {
            ++$offset;
        }
        if (!isset($buffer[$offset]) || $buffer[$offset] !== "\n") {
            throw new \LogicException('complete frame is missing a trailing newline');
        }

        return $offset + 1;
    }

    private function peekId(string $buffer): int
    {
        $line = $buffer;
        $lf = strpos($buffer, "\n");
        if ($lf !== false) {
            $line = substr($buffer, 0, $lf);
        }
        $line = rtrim($line, "\r");
        $parts = explode(' ', $line);
        if (count($parts) < 3 || $parts[0] !== Version::MAGIC) {
            return 0;
        }
        $token = $parts[2];
        if (preg_match('/^(0|[1-9][0-9]{0,18})$/', $token) !== 1) {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($token) > strlen($max) || (strlen($token) === strlen($max) && $token > $max)) {
            return 0;
        }

        return (int) $token;
    }

    private function encodePong(Frame $frame): string
    {
        $header = new Header(
            FrameKind::Pong,
            $frame->header->id,
            Version::EMPTY_CODEC,
            0,
            $frame->header->traceId,
        );

        return (new FrameEncoder())->encode(new Frame($header, ''));
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function encodeError(
        int $id,
        string $traceId,
        ErrorCode $code,
        string $message,
        ?array $data,
    ): string {
        $body = (new JsonCodec())->encodeError(new ErrorBody(
            $code->value,
            $message,
            $data,
            $data !== null,
        ));
        $header = new Header(FrameKind::Err, $id, JsonCodec::NAME, strlen($body), $traceId);

        return (new FrameEncoder())->encode(new Frame($header, $body));
    }
}
