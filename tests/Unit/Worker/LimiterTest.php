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
use PhpServer\RateLimit\Decision;
use PhpServer\RateLimit\LimiterInterface;
use PhpServer\RateLimit\TokenBucket;
use PhpServer\Support\SystemClock;
use PhpServer\Tests\Support\MemoryLogger;
use PhpServer\Tests\Support\MutableClock;
use PhpServer\Worker\RpcWorker;
use PHPUnit\Framework\TestCase;

final class LimiterTest extends TestCase
{
    public function testWithoutALimiterNothingIsRejected(): void
    {
        $counters = new Counters();
        $calls = 0;
        $raw = $this->exchange($counters, null, $this->request('Echo.say', ['hi']), $calls);
        self::assertSame('hi', (new JsonCodec())->decodeResult($this->frame($raw)->body));
        self::assertSame(0, $counters->get('rejected'));
        self::assertSame(0, $counters->get('degraded'));
    }

    public function testATightBucketRejectsAndSkipsTheHandler(): void
    {
        $counters = new Counters();
        $calls = 0;
        $limiter = new TokenBucket(new MutableClock(), 0.0, 1);
        $first = $this->exchange($counters, $limiter, $this->request('Echo.say', ['a']), $calls);
        $second = $this->exchange($counters, $limiter, $this->request('Echo.say', ['b']), $calls);
        self::assertSame('a', (new JsonCodec())->decodeResult($this->frame($first)->body));
        $error = (new JsonCodec())->decodeError($this->frame($second)->body);
        self::assertSame(ErrorCode::RateLimited->value, $error->code);
        self::assertSame(1, $calls);
        self::assertSame(1, $counters->get('rejected'));
    }

    public function testDegradeSkipsTheHandler(): void
    {
        $counters = new Counters();
        $calls = 0;
        $limiter = new class implements LimiterInterface {
            public function decide(string $message): Decision
            {
                return Decision::Degrade;
            }
        };
        $raw = $this->exchange($counters, $limiter, $this->request('Echo.say', ['a']), $calls);
        self::assertNull((new JsonCodec())->decodeResult($this->frame($raw)->body));
        self::assertSame(0, $calls);
        self::assertSame(1, $counters->get('degraded'));
        self::assertSame(0, $counters->get('rejected'));
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function request(string $method, array $params): string
    {
        $body = (new JsonCodec())->encodeRequest($method, $params);
        $header = new Header(FrameKind::Req, 1, JsonCodec::NAME, strlen($body), 'rate-1');

        return (new FrameEncoder())->encode(new Frame($header, $body));
    }

    private function exchange(Counters $counters, ?LimiterInterface $limiter, string $bytes, int &$calls): string
    {
        $loop = new SelectLoop(new SystemClock());
        $worker = new class ($this->config(), new MemoryLogger(), $loop, new SystemClock(), $counters) extends RpcWorker {};
        $worker->on('Echo.say', static function (?array $params, string $traceId) use (&$calls): string {
            ++$calls;

            $value = is_array($params) && array_is_list($params) ? ($params[0] ?? '') : '';

            return is_string($value) ? $value : '';
        });
        if ($limiter !== null) {
            $worker->setLimiter($limiter);
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('stream_socket_pair failed');
        }
        [$server, $peer] = $pair;
        stream_set_blocking($server, false);
        stream_set_blocking($peer, false);
        try {
            self::assertTrue($worker->watch($server));
            $got = '';
            $loop->onReadable($peer, function (mixed $stream) use ($loop, &$got): void {
                if (!is_resource($stream)) {
                    return;
                }
                $chunk = fread($stream, 65536);
                if (is_string($chunk)) {
                    $got .= $chunk;
                }
                $loop->stop();
            });
            self::assertSame(strlen($bytes), fwrite($peer, $bytes));
            $loop->run();

            return $got;
        } finally {
            if (is_resource($server)) {
                fclose($server);
            }
            if (is_resource($peer)) {
                fclose($peer);
            }
        }
    }

    private function frame(string $bytes): Frame
    {
        $decoder = new FrameDecoder(1024);
        $decoder->feed($bytes);
        $frame = $decoder->next();
        self::assertInstanceOf(Frame::class, $frame);

        return $frame;
    }

    private function config(): WorkerConfig
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
            maxConnections: 4,
            recvTimeout: 30.0,
            sendTimeout: 30.0,
            idleTimeout: 60.0,
            processTimeout: 30.0,
            maxBodyBytes: 1024,
            maxFrameBytes: 1284,
            pipelineBacklog: 32,
            maxRequests: 0,
            maxWorkTime: 0.0,
            recycleStagger: 0.0,
        );
    }
}
