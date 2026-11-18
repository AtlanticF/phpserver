<?php

declare(strict_types=1);

namespace PhpServer\Master;

use PhpServer\Config\Config;
use PhpServer\Config\WorkerConfig;
use PhpServer\Event\SelectLoop;
use PhpServer\Exception\ProcessException;
use PhpServer\Ipc\Codec;
use PhpServer\Ipc\Command;
use PhpServer\Ipc\Message;
use PhpServer\Log\LoggerInterface;
use PhpServer\Metrics\Counters;
use PhpServer\Metrics\MasterCounters;
use PhpServer\Support\ClockInterface;
use PhpServer\Support\SystemClock;
use PhpServer\Worker\Factory;
use PhpServer\Worker\ListenSocket;

/**
 * Prefork master. It binds every listen socket, forks `child_count` workers,
 * and reaps them on the event-loop thread.
 *
 * A missed heartbeat is `forced_kill`. A drain that outlives the deadline is
 * `drain_timeout`. Replacing a worker increments `worker_restarts`. The first
 * spawn of each slot does not.
 *
 * @internal
 */
final class Master
{
    public const float DEFAULT_DRAIN_TIMEOUT = 5.0;

    public const float DEFAULT_HEARTBEAT_INTERVAL = 0.5;

    public const int DEFAULT_HEARTBEAT_MISSES = 2;

    private readonly SelectLoop $loop;

    private readonly MasterCounters $counters;

    /** @var array<string, resource> */
    private array $listens = [];

    /** @var array<int, Slot> */
    private array $slots = [];

    /** @var list<int> */
    private array $reloadQueue = [];

    private bool $shuttingDown = false;

    private bool $reloadActive = false;

    private bool $drainTimedOut = false;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly float $drainTimeout = self::DEFAULT_DRAIN_TIMEOUT,
        private readonly float $heartbeatInterval = self::DEFAULT_HEARTBEAT_INTERVAL,
        private readonly int $heartbeatMisses = self::DEFAULT_HEARTBEAT_MISSES,
    ) {
        if ($this->drainTimeout <= 0 || $this->heartbeatInterval <= 0 || $this->heartbeatMisses < 1) {
            throw new \InvalidArgumentException('master timings must be positive');
        }
        $this->loop = new SelectLoop($this->clock);
        $this->counters = new MasterCounters();
    }

    public function counters(): MasterCounters
    {
        return $this->counters;
    }

    /**
     * Run until SIGTERM/SIGINT finishes the drain. The exit status is 0.
     */
    public function run(): int
    {
        Privileges::drop($this->config->user, $this->config->group, $this->logger);
        $this->title('phpserver: master');
        $this->logger->info('master started', ['pid' => getmypid()]);
        $this->bindAll();
        $this->spawnInitial();
        $this->loop->onSignal(SIGCHLD, function (int $signo): void {
            $this->reap();
        });
        $this->loop->onSignal(SIGTERM, function (int $signo): void {
            $this->beginShutdown();
        });
        $this->loop->onSignal(SIGINT, function (int $signo): void {
            $this->beginShutdown();
        });
        $this->loop->onSignal(SIGUSR2, function (int $signo): void {
            $this->beginReload();
        });
        $this->loop->addPeriodicTimer($this->heartbeatInterval, function (int $timerId): void {
            $this->beat();
        });
        foreach ($this->slots as $index => $slot) {
            $this->watchIpc($index);
        }
        try {
            $this->loop->run();
        } finally {
            $this->cleanup();
        }

        return 0;
    }

    private function bindAll(): void
    {
        foreach ($this->config->workers as $name => $worker) {
            $this->listens[$name] = ListenSocket::bind($worker);
            $this->logger->info('master listening', [
                'worker' => $name,
                'listen' => $worker->listen,
            ]);
        }
    }

    private function spawnInitial(): void
    {
        foreach ($this->config->workers as $name => $worker) {
            for ($index = 0; $index < $worker->childCount; ++$index) {
                $this->slots[] = $this->forkSlot($name, $worker, $this->listens[$name], false);
            }
        }
    }

    /**
     * @param resource $listen
     */
    private function forkSlot(string $name, WorkerConfig $worker, mixed $listen, bool $restart): Slot
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new ProcessException('socketpair failed');
        }
        [$parent, $child] = $pair;
        stream_set_blocking($parent, false);
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new ProcessException('fork failed');
        }
        if ($pid === 0) {
            pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGUSR2, SIG_DFL);
            fclose($parent);
            try {
                $this->becomeWorker($name, $worker, $listen, $child);
            } catch (\Throwable $exception) {
                fwrite(STDERR, 'worker: ' . $exception->getMessage() . "\n");
                exit(1);
            }

            exit(0);
        }
        fclose($child);
        if ($restart) {
            $this->counters->incrementWorkerRestarts();
            $this->logger->info('worker restarted', [
                'worker' => $name,
                'pid' => $pid,
                'worker_restarts' => $this->counters->get('worker_restarts'),
            ]);
        } else {
            $this->logger->info('worker started', [
                'worker' => $name,
                'pid' => $pid,
            ]);
        }

        return new Slot($pid, $parent, $listen, $worker, $name);
    }

    /**
     * @param resource $listen
     * @param resource $ipc
     */
    private function becomeWorker(string $name, WorkerConfig $worker, mixed $listen, mixed $ipc): void
    {
        foreach ($this->slots as $slot) {
            if (is_resource($slot->ipc)) {
                fclose($slot->ipc);
            }
        }
        foreach ($this->listens as $listenName => $socket) {
            if ($listenName !== $name && is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->title('phpserver: worker ' . $name);
        $loop = new SelectLoop(new SystemClock());
        $clock = new SystemClock();
        $built = Factory::create(
            $worker,
            $this->logger,
            $loop,
            $clock,
            new Counters(),
            $this->config->log->bodyTruncateBytes,
        );
        $built->useListen($listen);
        $built->supervise($ipc, $this->heartbeatInterval * ($this->heartbeatMisses + 2));
        $built->run();
    }

    private function watchIpc(int $index): void
    {
        $slot = $this->slots[$index];
        $ipc = $slot->ipc;
        $this->loop->onReadable($ipc, function (mixed $stream) use ($index): void {
            $this->readIpc($index);
        });
    }

    private function readIpc(int $index): void
    {
        $slot = $this->slots[$index] ?? null;
        if ($slot === null || !is_resource($slot->ipc)) {
            return;
        }
        $chunk = $this->quietly(static function () use ($slot): mixed {
            return fread($slot->ipc, 8192);
        });
        if (!is_string($chunk) || $chunk === '') {
            if (!is_resource($slot->ipc) || $this->eof($slot->ipc)) {
                $this->loop->removeStream($slot->ipc);
            }

            return;
        }
        try {
            $slot->codec->feed($chunk);
            while (($message = $slot->codec->next()) instanceof Message) {
                $this->onWorkerMessage($slot, $message);
            }
        } catch (ProcessException $exception) {
            $this->logger->error('ipc failed', [
                'pid' => $slot->pid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function onWorkerMessage(Slot $slot, Message $message): void
    {
        if ($message->command === Command::Pong || $message->command === Command::StatusResult || $message->command === Command::MetricsResult) {
            $slot->misses = 0;
        }
    }

    private function beat(): void
    {
        if ($this->shuttingDown) {
            return;
        }
        foreach ($this->slots as $slot) {
            if ($slot->pid <= 0 || $slot->killed || !is_resource($slot->ipc)) {
                continue;
            }
            if ($slot->misses >= $this->heartbeatMisses) {
                $this->forceKill($slot);

                continue;
            }
            $this->send($slot, new Message(Command::Ping, ''));
            ++$slot->misses;
        }
    }

    private function forceKill(Slot $slot): void
    {
        if ($slot->pid <= 0 || $slot->killed) {
            return;
        }
        $slot->killed = true;
        posix_kill($slot->pid, SIGKILL);
        $this->counters->incrementForcedKill();
        $this->logger->info('worker forced kill', [
            'pid' => $slot->pid,
            'worker' => $slot->name,
            'forced_kill' => $this->counters->get('forced_kill'),
        ]);
        $slot->misses = 0;
    }

    private function reap(): void
    {
        while (true) {
            $status = 0;
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid <= 0) {
                break;
            }
            $index = $this->indexOf($pid);
            if ($index === null) {
                continue;
            }
            $slot = $this->slots[$index];
            $this->closeIpc($slot);
            $name = $slot->name;
            $worker = $slot->config;
            $listen = $slot->listen;
            $reloading = $slot->reloading;
            $slot->pid = 0;
            if ($this->shuttingDown) {
                $this->finishShutdown();

                continue;
            }
            $replacement = $this->forkSlot($name, $worker, $listen, true);
            $this->slots[$index] = $replacement;
            $this->watchIpc($index);
            if ($reloading) {
                $this->reloadNext();
            }
        }
    }

    private function beginShutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }
        $this->shuttingDown = true;
        $this->logger->info('master draining');
        foreach ($this->listens as $name => $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            unset($this->listens[$name]);
        }
        foreach ($this->slots as $slot) {
            if ($slot->pid > 0) {
                $this->send($slot, new Message(Command::Drain, ''));
            }
        }
        $this->loop->addTimer($this->drainTimeout, function (int $timerId): void {
            $this->expireDrain();
        });
        $this->finishShutdown();
    }

    private function expireDrain(): void
    {
        if (!$this->shuttingDown) {
            return;
        }
        $killed = false;
        foreach ($this->slots as $slot) {
            if ($slot->pid > 0) {
                posix_kill($slot->pid, SIGKILL);
                $killed = true;
            }
        }
        if ($killed && !$this->drainTimedOut) {
            $this->drainTimedOut = true;
            $this->counters->incrementDrainTimeout();
            $this->logger->info('drain timed out', [
                'drain_timeout' => $this->counters->get('drain_timeout'),
            ]);
        }
    }

    private function finishShutdown(): void
    {
        if (!$this->shuttingDown) {
            return;
        }
        foreach ($this->slots as $slot) {
            if ($slot->pid > 0) {
                return;
            }
        }
        $this->loop->stop();
    }

    private function beginReload(): void
    {
        if ($this->shuttingDown || $this->reloadActive) {
            return;
        }
        $this->reloadQueue = [];
        foreach ($this->slots as $index => $slot) {
            if ($slot->pid > 0) {
                $this->reloadQueue[] = $index;
            }
        }
        $this->reloadActive = $this->reloadQueue !== [];
        $this->logger->info('master reloading', ['workers' => count($this->reloadQueue)]);
        $this->reloadNext();
    }

    private function reloadNext(): void
    {
        if ($this->reloadQueue === []) {
            $this->reloadActive = false;
            $this->logger->info('master reload finished');

            return;
        }
        $index = array_shift($this->reloadQueue);
        $slot = $this->slots[$index] ?? null;
        if ($slot === null || $slot->pid <= 0) {
            $this->reloadNext();

            return;
        }
        $slot->reloading = true;
        $this->send($slot, new Message(Command::Drain, ''));
    }

    private function send(Slot $slot, Message $message): void
    {
        if (!is_resource($slot->ipc)) {
            return;
        }
        $bytes = (new Codec())->encode($message);
        $this->quietly(static function () use ($slot, $bytes): mixed {
            return fwrite($slot->ipc, $bytes);
        });
    }

    private function indexOf(int $pid): ?int
    {
        foreach ($this->slots as $index => $slot) {
            if ($slot->pid === $pid) {
                return $index;
            }
        }

        return null;
    }

    private function closeIpc(Slot $slot): void
    {
        if (!is_resource($slot->ipc)) {
            return;
        }
        $this->loop->removeStream($slot->ipc);
        fclose($slot->ipc);
    }

    private function cleanup(): void
    {
        foreach ($this->slots as $slot) {
            $this->closeIpc($slot);
            if ($slot->pid > 0) {
                posix_kill($slot->pid, SIGKILL);
            }
        }
        foreach ($this->listens as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->listens = [];
    }

    private function title(string $title): void
    {
        if (!cli_set_process_title($title)) {
            $this->logger->warning('could not set process title', ['title' => $title]);
        }
    }

    private function eof(mixed $stream): bool
    {
        if (!is_resource($stream)) {
            return true;
        }
        $meta = stream_get_meta_data($stream);

        return $meta['eof'] === true;
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
