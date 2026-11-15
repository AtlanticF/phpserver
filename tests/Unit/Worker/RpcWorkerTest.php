<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Worker;

use PhpServer\Config\WorkerConfig;
use PhpServer\Event\SelectLoop;
use PhpServer\Metrics\Counters;
use PhpServer\Protocol\TextRpc\ErrorCode;
use PhpServer\Protocol\TextRpc\Frame;
use PhpServer\Protocol\TextRpc\FrameDecoder;
use PhpServer\Protocol\TextRpc\FrameEncoder;
use PhpServer\Protocol\TextRpc\FrameKind;
use PhpServer\Protocol\TextRpc\Header;
use PhpServer\Protocol\TextRpc\JsonCodec;
use PhpServer\Support\SystemClock;
use PhpServer\Tests\Support\MemoryLogger;
use PhpServer\Tests\Support\MutableClock;
use PhpServer\Worker\RpcWorker;
use PHPUnit\Framework\TestCase;

final class RpcWorkerTest extends TestCase
{
    private ?SelectLoop $loop = null;

    private ?Counters $counters = null;

    private ?MemoryLogger $logger = null;

    private ?RpcWorker $worker = null;

    /** @var resource|null */
    private mixed $server = null;

    /** @var resource|null */
    private mixed $peer = null;

    private string $got = '';

    protected function setUp(): void
    {
        $this->counters = new Counters();
        $this->logger = new MemoryLogger();
        $this->loop = new SelectLoop(new SystemClock());
        $this->worker = new class ($this->config(), $this->logger(), $this->loop(), new SystemClock(), $this->counters()) extends RpcWorker {};
        $this->worker()->on('Echo.say', static function (?array $params, string $traceId): mixed {
            if ($params === null || !array_is_list($params)) {
                return $params;
            }

            return $params[0] ?? null;
        });
        $this->worker()->on('Demo.boom', static function (?array $params, string $traceId): never {
            throw new \RuntimeException('boom-marker');
        });
        [$this->server, $this->peer] = $this->pair();
        self::assertTrue($this->worker()->watch($this->server));
        $loop = $this->loop();
        $this->loop()->onReadable($this->peer, function (mixed $stream) use ($loop): void {
            $this->readPeer($stream);
            $loop->stop();
        });
    }

    protected function tearDown(): void
    {
        $this->close($this->server, $this->peer);
    }

    public function testEchoKeepsTheTraceIdAndClearsTheFrame(): void
    {
        $raw = $this->ask($this->request(1, 'Echo.say', ['hi'], 'web-1a2b3c') . $this->request(2, 'Echo.say', ['b']));
        $frames = $this->frames($raw);
        self::assertCount(2, $frames);
        $codec = new JsonCodec();
        self::assertSame('hi', $codec->decodeResult($frames[0]->body));
        self::assertSame('web-1a2b3c', $frames[0]->header->traceId);
        self::assertSame(1, $frames[0]->header->id);
        self::assertSame('b', $codec->decodeResult($frames[1]->body));
        self::assertNotNull($frames[1]->header->traceId);
        self::assertSame(2, $this->counters()->get('requests'));
        self::assertSame(0, $this->counters()->get('errors'));
        self::assertTrue(is_resource($this->server));
    }

    public function testPingBecomesPong(): void
    {
        $header = new Header(FrameKind::Ping, 9, '-', 0, null);
        $raw = $this->ask((new FrameEncoder())->encode(new Frame($header, '')));
        $frames = $this->frames($raw);
        self::assertCount(1, $frames);
        self::assertSame(FrameKind::Pong, $frames[0]->header->kind);
        self::assertSame(9, $frames[0]->header->id);
        self::assertSame('-', $frames[0]->header->codec);
        self::assertSame('', $frames[0]->body);
    }

    public function testHandlerFailureStaysOnTheConnectionWithTheSameTraceId(): void
    {
        $raw = $this->ask($this->request(4, 'Demo.boom', [], 'boom-1'));
        $frame = $this->frames($raw)[0];
        $error = (new JsonCodec())->decodeError($frame->body);
        self::assertSame(ErrorCode::InternalError->value, $error->code);
        self::assertSame('Internal error', $error->message);
        self::assertSame('boom-1', $frame->header->traceId);
        self::assertStringNotContainsString('boom-marker', $raw);
        $logged = $this->errorRecord();
        self::assertSame('boom-1', $logged['request_id']);
        self::assertSame('boom-marker', $logged['error']);
        self::assertSame(1, $this->counters()->get('errors'));

        $again = $this->ask($this->request(5, 'Echo.say', ['still']));
        self::assertSame('still', (new JsonCodec())->decodeResult($this->frames($again)[0]->body));
        self::assertSame(2, $this->counters()->get('requests'));
        self::assertTrue(is_resource($this->server));
    }

    public function testFrameTooLargeRepliesAndCloses(): void
    {
        $raw = $this->ask("TRPC/1 REQ 7 json 100000\n");
        $frame = $this->frames($raw)[0];
        $error = (new JsonCodec())->decodeError($frame->body);
        self::assertSame(ErrorCode::FrameTooLarge->value, $error->code);
        self::assertSame(7, $frame->header->id);
        self::assertSame(1, $this->counters()->get('errors'));
        self::assertSame(0, $this->counters()->get('requests'));
        self::assertFalse(is_resource($this->server));
    }

    public function testBadHeaderUsesIdZeroAndCloses(): void
    {
        $raw = $this->ask("HELLO\n");
        $frame = $this->frames($raw)[0];
        $error = (new JsonCodec())->decodeError($frame->body);
        self::assertSame(ErrorCode::InvalidRequest->value, $error->code);
        self::assertSame(0, $frame->header->id);
        self::assertSame(1, $this->counters()->get('errors'));
        self::assertFalse(is_resource($this->server));
    }

    public function testPipelineBacklogSkipsTheHandler(): void
    {
        $this->loop = new SelectLoop(new SystemClock());
        $this->counters = new Counters();
        $this->logger = new MemoryLogger();
        $this->worker = new class ($this->config(pipeline: 1), $this->logger(), $this->loop(), new SystemClock(), $this->counters()) extends RpcWorker {};
        $calls = 0;
        $this->worker()->on('Echo.say', static function (?array $params, string $traceId) use (&$calls): string {
            ++$calls;

            return 'ran';
        });
        $this->close($this->server, $this->peer);
        [$this->server, $this->peer] = $this->pair();
        self::assertTrue($this->worker()->watch($this->server));
        $loop = $this->loop();
        $this->loop()->onReadable($this->peer, function (mixed $stream) use ($loop): void {
            $this->readPeer($stream);
            $loop->stop();
        });

        $raw = $this->ask($this->request(1, 'Echo.say', ['a']) . $this->request(2, 'Echo.say', ['b']));
        $frames = $this->frames($raw);
        self::assertCount(2, $frames);
        self::assertSame('ran', (new JsonCodec())->decodeResult($frames[0]->body));
        $error = (new JsonCodec())->decodeError($frames[1]->body);
        self::assertSame(ErrorCode::Overloaded->value, $error->code);
        self::assertSame(1, $calls);
        self::assertSame(1, $this->counters()->get('errors'));
    }

    public function testSlowHandlerBecomesTimeout(): void
    {
        $clock = new MutableClock();
        $this->loop = new SelectLoop($clock);
        $this->counters = new Counters();
        $this->logger = new MemoryLogger();
        $this->worker = new class ($this->config(process: 0.01), $this->logger(), $this->loop(), $clock, $this->counters()) extends RpcWorker {};
        $this->worker()->on('Demo.slow', static function (?array $params, string $traceId) use ($clock): string {
            $clock->advanceMonotonic(20_000_000);

            return 'late';
        });
        $this->close($this->server, $this->peer);
        [$this->server, $this->peer] = $this->pair();
        self::assertTrue($this->worker()->watch($this->server));
        $loop = $this->loop();
        $this->loop()->onReadable($this->peer, function (mixed $stream) use ($loop): void {
            $this->readPeer($stream);
            $loop->stop();
        });

        $raw = $this->ask($this->request(1, 'Demo.slow', []));
        $error = (new JsonCodec())->decodeError($this->frames($raw)[0]->body);
        self::assertSame(ErrorCode::Timeout->value, $error->code);
        self::assertTrue(is_resource($this->server));
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function request(int $id, string $method, array $params, ?string $traceId = null): string
    {
        $body = (new JsonCodec())->encodeRequest($method, $params);
        $header = new Header(FrameKind::Req, $id, JsonCodec::NAME, strlen($body), $traceId);

        return (new FrameEncoder())->encode(new Frame($header, $body));
    }

    private function ask(string $bytes): string
    {
        $this->got = '';
        $peer = $this->peer;
        if (!is_resource($peer)) {
            self::fail('peer is closed');
        }
        $written = fwrite($peer, $bytes);
        self::assertSame(strlen($bytes), $written);
        $this->loop()->run();

        return $this->got;
    }

    private function readPeer(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        while (true) {
            $chunk = fread($stream, 65536);
            if (!is_string($chunk) || $chunk === '') {
                return;
            }
            $this->got .= $chunk;
            if (strlen($chunk) < 65536) {
                return;
            }
        }
    }

    /**
     * @return list<Frame>
     */
    private function frames(string $bytes): array
    {
        $decoder = new FrameDecoder(1024);
        $decoder->feed($bytes);
        $frames = [];
        while (true) {
            $frame = $decoder->next();
            if (!$frame instanceof Frame) {
                return $frames;
            }
            $frames[] = $frame;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errorRecord(): array
    {
        foreach ($this->logger()->records as $record) {
            if ($record['level'] === 'error' && $record['message'] === 'handler failed') {
                return $record['context'];
            }
        }
        self::fail('missing handler error log');
    }

    private function config(int $pipeline = 32, float $process = 30.0): WorkerConfig
    {
        return new WorkerConfig(
            name: 'Rpc',
            listen: '127.0.0.1:9',
            host: '127.0.0.1',
            port: 9,
            workerClass: RpcWorker::class,
            bootstrap: '',
            childCount: 1,
            backlog: 16,
            maxConnections: 8,
            recvTimeout: 30.0,
            sendTimeout: 30.0,
            idleTimeout: 60.0,
            processTimeout: $process,
            maxBodyBytes: 64,
            maxFrameBytes: 64 + 260,
            pipelineBacklog: $pipeline,
            maxRequests: 0,
            maxWorkTime: 0.0,
            recycleStagger: 0.0,
        );
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return [$pair[0], $pair[1]];
    }

    private function close(mixed ...$streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function loop(): SelectLoop
    {
        if (!$this->loop instanceof SelectLoop) {
            throw new \LogicException('loop is not ready');
        }

        return $this->loop;
    }

    private function counters(): Counters
    {
        if (!$this->counters instanceof Counters) {
            throw new \LogicException('counters are not ready');
        }

        return $this->counters;
    }

    private function logger(): MemoryLogger
    {
        if (!$this->logger instanceof MemoryLogger) {
            throw new \LogicException('logger is not ready');
        }

        return $this->logger;
    }

    private function worker(): RpcWorker
    {
        if (!$this->worker instanceof RpcWorker) {
            throw new \LogicException('worker is not ready');
        }

        return $this->worker;
    }
}
