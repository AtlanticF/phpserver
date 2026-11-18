<?php

declare(strict_types=1);

namespace PhpServer\Worker;

use PhpServer\Config\WorkerConfig;
use PhpServer\Event\LoopInterface;
use PhpServer\Ipc\Codec;
use PhpServer\Ipc\Command;
use PhpServer\Ipc\Message;
use PhpServer\Log\LoggerInterface;
use PhpServer\Metrics\Counters;
use PhpServer\RateLimit\Decision;
use PhpServer\RateLimit\LimiterInterface;
use PhpServer\Support\ClockInterface;

/**
 * Single-process accept loop shared by every worker.
 *
 * The listen socket and each connection are non-blocking. A read buffer never
 * grows past `max_frame_bytes`. After a message is taken, those bytes leave
 * the buffer, so a persistent connection does not keep the previous frame.
 * `recv_timeout` starts when a message's first byte arrives and closes the
 * socket with no reply if the message is still incomplete. `idle_timeout`
 * closes a quiet connection and increments `idle_timeout`. `send_timeout`
 * closes a connection whose reply does not finish in time. Past
 * `max_connections`, the extra socket is accepted and closed immediately;
 * `conn_rejected` increments and nothing is buffered.
 *
 * An exception from either hook is logged and the connection is dropped.
 * The process keeps running. Without a master, `SIGTERM` and `SIGINT` stop
 * the loop. With {@see self::supervise()}, those signals and an IPC `drain`
 * stop accept and finish in-flight requests first. `max_requests` and
 * `max_work_time` of 0 stay off; any other value drains, after a random
 * delay of at most `recycle_stagger`.
 *
 * @api
 */
abstract class AbstractWorker implements WorkerInterface
{
    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var resource|null */
    private mixed $server = null;

    /** @var resource|null */
    private mixed $ipc = null;

    private ?Codec $ipcCodec = null;

    private bool $draining = false;

    private bool $recycleArmed = false;

    private float $heartbeatTimeout = 0.0;

    private int $lastPeerNs = 0;

    private ?LimiterInterface $limiter = null;

    public function __construct(
        private readonly WorkerConfig $config,
        private readonly LoggerInterface $logger,
        private readonly LoopInterface $loop,
        private readonly ClockInterface $clock,
        private readonly Counters $counters,
        private readonly int $bodyTruncateBytes = 256,
    ) {
        if ($this->bodyTruncateBytes < 0) {
            throw new \InvalidArgumentException('body truncate limit must be >= 0');
        }
    }

    /**
     * Bind, accept, and serve until the loop stops.
     */
    public function run(): void
    {
        $this->bind();
        $server = $this->server;
        if (!is_resource($server)) {
            throw new \RuntimeException('listen socket is not open');
        }
        if ($this->ipc === null) {
            $this->loop->onSignal(SIGTERM, function (int $signo): void {
                $this->logger->info('worker {worker} stopping on signal {signal}', [
                    'worker' => $this->config->name,
                    'signal' => $signo,
                ]);
                $this->loop->stop();
            });
            $this->loop->onSignal(SIGINT, function (int $signo): void {
                $this->logger->info('worker {worker} stopping on signal {signal}', [
                    'worker' => $this->config->name,
                    'signal' => $signo,
                ]);
                $this->loop->stop();
            });
        } else {
            $this->loop->onSignal(SIGTERM, function (int $signo): void {
                $this->beginDrain();
            });
            $this->loop->onSignal(SIGINT, function (int $signo): void {
                $this->beginDrain();
            });
            $this->watchIpc();
        }
        if ($this->config->maxWorkTime > 0) {
            $this->loop->addTimer($this->config->maxWorkTime, function (int $timerId): void {
                $this->scheduleDrain();
            });
        }
        $this->loop->onReadable($server, function (mixed $stream): void {
            $this->accept();
        });
        $this->logger->info('worker {worker} listening on {listen}', [
            'worker' => $this->config->name,
            'listen' => $this->config->listen,
        ]);
        try {
            $this->loop->run();
        } finally {
            $this->shutdown();
        }
    }

    /**
     * Watch an already-accepted connection.
     *
     * Returns false when the connection was rejected for `max_connections`
     * and closed. `accept` uses this; tests can pass a socketpair end.
     *
     * @param resource $stream
     *
     * @internal
     */
    public function watch(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('connection must be a stream');
        }
        if (count($this->connections) >= $this->config->maxConnections) {
            $this->counters->incrementConnRejected();
            $this->quietly(static function () use ($stream): void {
                fclose($stream);
            });

            return false;
        }
        stream_set_blocking($stream, false);
        $connection = new Connection($stream);
        $this->connections[(int) $stream] = $connection;
        $this->counters->incrementAccept();
        $this->loop->onReadable($stream, function (mixed $ready): void {
            $this->read($ready);
        });
        $this->armIdle($connection);

        return true;
    }

    /**
     * Accept on a socket the master already bound. Call this before {@see self::run()}.
     *
     * @param resource $socket
     */
    public function useListen(mixed $socket): void
    {
        if (!is_resource($socket)) {
            throw new \InvalidArgumentException('listen socket must be a stream');
        }
        stream_set_blocking($socket, false);
        $this->server = $socket;
    }

    /**
     * Attach the worker end of the master socketpair. Call this before {@see self::run()}.
     *
     * `$heartbeatTimeout` is how long the worker waits for any IPC byte before
     * it exits. That covers a master that died without a clean drain.
     *
     * @param resource $ipc
     */
    public function supervise(mixed $ipc, float $heartbeatTimeout): void
    {
        if (!is_resource($ipc)) {
            throw new \InvalidArgumentException('ipc socket must be a stream');
        }
        if (!is_finite($heartbeatTimeout) || $heartbeatTimeout <= 0) {
            throw new \InvalidArgumentException('heartbeat timeout must be > 0');
        }
        $this->ipc = $ipc;
        $this->heartbeatTimeout = $heartbeatTimeout;
    }

    /**
     * Stop accepting and return once the open connections finish.
     */
    public function beginDrain(): void
    {
        if ($this->draining) {
            $this->closeListen();
            $this->finishIfDraining();

            return;
        }
        $this->draining = true;
        $this->logger->info('worker draining', ['worker' => $this->config->name]);
        $this->closeListen();
        $this->finishIfDraining();
    }

    /**
     * Install a limiter. Until this is called, `dealProcess` is reached with
     * no rate-limit decision on the way.
     */
    public function setLimiter(LimiterInterface $limiter): void
    {
        $this->limiter = $limiter;
    }

    public function counters(): Counters
    {
        return $this->counters;
    }

    protected function workerConfig(): WorkerConfig
    {
        return $this->config;
    }

    protected function logger(): LoggerInterface
    {
        return $this->logger;
    }

    protected function clock(): ClockInterface
    {
        return $this->clock;
    }

    protected function bodyTruncateBytes(): int
    {
        return $this->bodyTruncateBytes;
    }

    /**
     * Reply used when the read buffer hits `max_frame_bytes` before a message
     * is complete. The default closes with no bytes. A protocol worker
     * overrides this to send an error frame.
     */
    protected function frameTooLarge(string $buffer): Output
    {
        return new Output('', true);
    }

    /**
     * Called instead of {@see dealProcess()} when this read already contains
     * more than `pipeline_backlog` messages. The default closes.
     */
    protected function pipelineOverflow(string $message): Output
    {
        return new Output('', true);
    }

    /**
     * Called instead of {@see dealProcess()} when a limiter rejects or degrades.
     * The default closes. The RPC worker replies and keeps the connection.
     */
    protected function dealLimited(string $message, Decision $decision): Output
    {
        return new Output('', true);
    }

    private function bind(): void
    {
        if (is_resource($this->server)) {
            return;
        }
        $this->server = ListenSocket::bind($this->config);
    }

    private function accept(): void
    {
        $server = $this->server;
        if (!is_resource($server)) {
            return;
        }
        while (true) {
            $client = $this->quietly(static function () use ($server): mixed {
                return stream_socket_accept($server, 0);
            });
            if (!is_resource($client)) {
                return;
            }
            try {
                $this->watch($client);
            } catch (\Throwable $exception) {
                $this->logger->error('accept failed', [
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
                if (!isset($this->connections[(int) $client])) {
                    $this->quietly(static function () use ($client): void {
                        fclose($client);
                    });
                }
            }
        }
    }

    private function read(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        $connection = $this->connections[(int) $stream] ?? null;
        if ($connection === null || $connection->closing) {
            return;
        }
        $room = $this->config->maxFrameBytes - strlen($connection->readBuffer);
        if ($room <= 0) {
            $this->overflow($connection);

            return;
        }
        $chunk = $this->quietly(static function () use ($stream, $room): mixed {
            return fread($stream, min(8192, $room));
        });
        if (!is_string($chunk) || $chunk === '') {
            if ($this->eof($stream)) {
                $this->drop($connection);
            }

            return;
        }
        $wasEmpty = $connection->readBuffer === '';
        $connection->readBuffer .= $chunk;
        if ($wasEmpty) {
            $this->cancelIdle($connection);
            $this->armRecv($connection);
        }
        try {
            $this->drain($connection);
        } catch (\Throwable $exception) {
            $this->fail($connection, $exception);
        }
    }

    private function drain(Connection $connection): void
    {
        $pipelined = 0;
        while ($connection->readBuffer !== '' && $this->open($connection)) {
            $input = $this->dealInput($connection->readBuffer);
            if ($input->state === InputState::NeedMore) {
                if (strlen($connection->readBuffer) >= $this->config->maxFrameBytes) {
                    $this->overflow($connection);
                }

                return;
            }
            $consumed = $input->consumed;
            if ($consumed > strlen($connection->readBuffer)) {
                $this->counters->incrementErrors();
                $this->drop($connection);

                return;
            }
            $message = substr($connection->readBuffer, 0, $consumed);
            $connection->readBuffer = substr($connection->readBuffer, $consumed);
            if ($input->state === InputState::Failed) {
                $this->counters->incrementErrors();
                if ($input->close || $consumed === 0) {
                    $connection->closing = true;
                    $connection->readBuffer = '';
                }
                $this->reply($connection, $input->reply);
                if (!$this->open($connection) || $connection->closing) {
                    return;
                }

                continue;
            }
            ++$pipelined;
            $output = $pipelined > $this->config->pipelineBacklog
                ? $this->pipelineOverflow($message)
                : $this->callProcess($message);
            if ($output->close) {
                $connection->closing = true;
                $connection->readBuffer = '';
            }
            $this->reply($connection, $output->bytes);
            $this->maybeRecycle();
            if (!$this->open($connection) || $connection->closing) {
                return;
            }
        }
        if ($this->open($connection) && $connection->readBuffer === '' && !$connection->closing) {
            $this->cancelRecv($connection);
            $this->armIdle($connection);
        }
    }

    private function callProcess(string $message): Output
    {
        if ($this->limiter !== null) {
            $decision = $this->limiter->decide($message);
            if ($decision === Decision::Reject) {
                $this->counters->incrementRejected();

                return $this->dealLimited($message, $decision);
            }
            if ($decision === Decision::Degrade) {
                $this->counters->incrementDegraded();

                return $this->dealLimited($message, $decision);
            }
        }
        $this->counters->incrementRequests();
        try {
            return $this->dealProcess($message);
        } catch (\Throwable $exception) {
            $this->counters->incrementErrors();
            $this->logger->error('worker hook failed', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return new Output('', true);
        }
    }

    private function overflow(Connection $connection): void
    {
        $this->counters->incrementErrors();
        $output = $this->frameTooLarge($connection->readBuffer);
        $connection->readBuffer = '';
        $connection->closing = true;
        $this->reply($connection, $output->bytes);
    }

    private function reply(Connection $connection, string $bytes): void
    {
        if (!$this->open($connection)) {
            return;
        }
        if ($bytes !== '') {
            $connection->writeBuffer .= $bytes;
        }
        $this->flush($connection);
    }

    private function flush(Connection $connection): void
    {
        if (!$this->open($connection)) {
            return;
        }
        $stream = $connection->stream;
        while ($connection->writeBuffer !== '') {
            $pending = $connection->writeBuffer;
            $written = $this->quietly(static function () use ($stream, $pending): mixed {
                return fwrite($stream, $pending);
            });
            if (!is_int($written) || $written === 0) {
                if ($this->eof($stream)) {
                    $this->drop($connection);

                    return;
                }
                $this->wantWrite($connection);
                $this->armSend($connection);

                return;
            }
            $connection->writeBuffer = substr($connection->writeBuffer, $written);
        }
        if (is_resource($stream)) {
            $this->loop->offWritable($stream);
        }
        $this->cancelSend($connection);
        if ($connection->closing) {
            $this->drop($connection);
        }
    }

    private function wantWrite(Connection $connection): void
    {
        $stream = $connection->stream;
        if (!is_resource($stream)) {
            return;
        }
        $this->loop->onWritable($stream, function (mixed $ready): void {
            if (!is_resource($ready)) {
                return;
            }
            $current = $this->connections[(int) $ready] ?? null;
            if ($current === null) {
                return;
            }
            try {
                $this->flush($current);
            } catch (\Throwable $exception) {
                $this->fail($current, $exception);
            }
        });
    }

    private function fail(Connection $connection, \Throwable $exception): void
    {
        $this->counters->incrementErrors();
        $this->logger->error('worker connection failed', [
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
        if ($this->open($connection)) {
            $this->drop($connection);
        }
    }

    private function armIdle(Connection $connection): void
    {
        $this->cancelIdle($connection);
        if (!is_resource($connection->stream)) {
            return;
        }
        $streamId = (int) $connection->stream;
        $connection->idleTimer = $this->loop->addTimer($this->config->idleTimeout, function (int $timerId) use ($streamId): void {
            $current = $this->connections[$streamId] ?? null;
            if ($current === null || $current->idleTimer !== $timerId) {
                return;
            }
            $current->idleTimer = null;
            $this->counters->incrementIdleTimeout();
            $this->drop($current);
        });
    }

    private function armRecv(Connection $connection): void
    {
        if ($connection->recvTimer !== null || !is_resource($connection->stream)) {
            return;
        }
        $streamId = (int) $connection->stream;
        $connection->recvTimer = $this->loop->addTimer($this->config->recvTimeout, function (int $timerId) use ($streamId): void {
            $current = $this->connections[$streamId] ?? null;
            if ($current === null || $current->recvTimer !== $timerId) {
                return;
            }
            $current->recvTimer = null;
            $this->logger->warning('receive timed out', [
                'worker' => $this->config->name,
            ]);
            $this->drop($current);
        });
    }

    private function armSend(Connection $connection): void
    {
        if ($connection->sendTimer !== null || !is_resource($connection->stream)) {
            return;
        }
        $streamId = (int) $connection->stream;
        $connection->sendTimer = $this->loop->addTimer($this->config->sendTimeout, function (int $timerId) use ($streamId): void {
            $current = $this->connections[$streamId] ?? null;
            if ($current === null || $current->sendTimer !== $timerId) {
                return;
            }
            $current->sendTimer = null;
            $this->logger->warning('send timed out', [
                'worker' => $this->config->name,
            ]);
            $this->drop($current);
        });
    }

    private function cancelIdle(Connection $connection): void
    {
        $this->cancel($connection->idleTimer);
        $connection->idleTimer = null;
    }

    private function cancelRecv(Connection $connection): void
    {
        $this->cancel($connection->recvTimer);
        $connection->recvTimer = null;
    }

    private function cancelSend(Connection $connection): void
    {
        $this->cancel($connection->sendTimer);
        $connection->sendTimer = null;
    }

    private function cancel(?int $timerId): void
    {
        if ($timerId !== null) {
            $this->loop->cancelTimer($timerId);
        }
    }

    private function drop(Connection $connection): void
    {
        $stream = $connection->stream;
        if (!is_resource($stream)) {
            return;
        }
        $id = (int) $stream;
        unset($this->connections[$id]);
        $this->cancel($connection->idleTimer);
        $this->cancel($connection->recvTimer);
        $this->cancel($connection->sendTimer);
        $connection->idleTimer = null;
        $connection->recvTimer = null;
        $connection->sendTimer = null;
        $this->loop->removeStream($stream);
        $this->quietly(static function () use ($stream): void {
            fclose($stream);
        });
        $this->finishIfDraining();
    }

    private function open(Connection $connection): bool
    {
        $stream = $connection->stream;

        return is_resource($stream) && isset($this->connections[(int) $stream]);
    }

    private function eof(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            return true;
        }
        $meta = stream_get_meta_data($stream);

        return $meta['eof'] === true;
    }

    private function shutdown(): void
    {
        foreach (array_values($this->connections) as $connection) {
            $this->drop($connection);
        }
        $server = $this->server;
        if (is_resource($server)) {
            $this->loop->removeStream($server);
            $this->quietly(static function () use ($server): void {
                fclose($server);
            });
        }
        $this->server = null;
    }

    private function maybeRecycle(): void
    {
        $limit = $this->config->maxRequests;
        if ($limit > 0 && $this->counters->get('requests') >= $limit) {
            $this->scheduleDrain();
        }
    }

    private function scheduleDrain(): void
    {
        if ($this->draining || $this->recycleArmed) {
            return;
        }
        $this->recycleArmed = true;
        $this->closeListen();
        $this->loop->addTimer($this->staggerSeconds(), function (int $timerId): void {
            $this->beginDrain();
        });
    }

    private function staggerSeconds(): float
    {
        $limit = $this->config->recycleStagger;
        if ($limit <= 0) {
            return 0.0;
        }
        $millis = (int) round($limit * 1000);
        if ($millis < 1) {
            return 0.0;
        }

        return random_int(0, $millis) / 1000;
    }

    private function closeListen(): void
    {
        $server = $this->server;
        if (!is_resource($server)) {
            return;
        }
        $this->loop->removeStream($server);
        $this->quietly(static function () use ($server): void {
            fclose($server);
        });
        $this->server = null;
    }

    private function finishIfDraining(): void
    {
        if ($this->draining && $this->connections === []) {
            $this->loop->stop();
        }
    }

    private function watchIpc(): void
    {
        $ipc = $this->ipc;
        if (!is_resource($ipc)) {
            return;
        }
        stream_set_blocking($ipc, false);
        $this->ipcCodec = new Codec();
        $this->lastPeerNs = $this->clock->monotonic();
        $this->loop->onReadable($ipc, function (mixed $stream): void {
            $this->readIpc();
        });
        $interval = min(0.2, $this->heartbeatTimeout / 2);
        $this->loop->addPeriodicTimer($interval, function (int $timerId): void {
            $limit = (int) round($this->heartbeatTimeout * 1_000_000_000);
            if ($this->clock->monotonic() - $this->lastPeerNs > $limit) {
                $this->logger->warning('master heartbeat lost', ['worker' => $this->config->name]);
                $this->loop->stop();
            }
        });
    }

    private function readIpc(): void
    {
        $ipc = $this->ipc;
        $codec = $this->ipcCodec;
        if (!is_resource($ipc) || $codec === null) {
            return;
        }
        $chunk = $this->quietly(static function () use ($ipc): mixed {
            return fread($ipc, 8192);
        });
        if (!is_string($chunk) || $chunk === '') {
            if ($this->eof($ipc)) {
                $this->logger->info('master ipc closed', ['worker' => $this->config->name]);
                $this->loop->stop();
            }

            return;
        }
        $codec->feed($chunk);
        while (true) {
            $message = $codec->next();
            if (!$message instanceof Message) {
                return;
            }
            $this->lastPeerNs = $this->clock->monotonic();
            $this->onIpc($message);
        }
    }

    private function onIpc(Message $message): void
    {
        if ($message->command === Command::Ping) {
            $this->sendIpc(new Message(Command::Pong, ''));

            return;
        }
        if ($message->command === Command::Drain || $message->command === Command::Stop) {
            $this->beginDrain();

            return;
        }
        if ($message->command === Command::Status) {
            $this->sendIpc(new Message(Command::StatusResult, $this->statusBody()));

            return;
        }
        if ($message->command === Command::Metrics) {
            $this->sendIpc(new Message(Command::MetricsResult, $this->metricsBody()));
        }
    }

    private function sendIpc(Message $message): void
    {
        $ipc = $this->ipc;
        $codec = $this->ipcCodec;
        if (!is_resource($ipc) || $codec === null) {
            return;
        }
        $bytes = $codec->encode($message);
        $this->quietly(static function () use ($ipc, $bytes): mixed {
            return fwrite($ipc, $bytes);
        });
    }

    private function statusBody(): string
    {
        return 'pid=' . getmypid() . "\nworker=" . $this->config->name . "\nrequests=" . $this->counters->get('requests');
    }

    private function metricsBody(): string
    {
        $lines = [];
        foreach ($this->counters->snapshot() as $name => $value) {
            $lines[] = $name . '=' . $value;
        }

        return implode("\n", $lines);
    }

    private function quietly(\Closure $run): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            return true;
        });
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }
}
