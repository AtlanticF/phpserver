<?php

declare(strict_types=1);

namespace PhpServer\Admin;

use PhpServer\Config\AdminConfig;
use PhpServer\Event\LoopInterface;

/**
 * Small HTTP listener on the master. GET only, one response, then close.
 *
 * A client that does not finish the request headers inside one second is
 * dropped, so it cannot sit on the descriptor. The body is not read.
 * `/healthz` only means this process is still answering. `/readyz` is 200
 * only while the master is listening and has a live worker.
 *
 * @internal
 */
final class HttpServer
{
    public const float READ_TIMEOUT = 1.0;

    public const int MAX_BYTES = 4096;

    public const int MAX_CLIENTS = 32;

    /** @var resource|null */
    private mixed $server = null;

    /** @var array<int, Exchange> */
    private array $clients = [];

    /**
     * `$ready` reports whether business traffic can be served.
     * `$collect` gathers worker counters, then calls its closure with the
     * status JSON and the Prometheus text.
     *
     * @param \Closure(): bool $ready
     * @param \Closure(\Closure(string, string): void): void $collect
     */
    public function __construct(
        private readonly AdminConfig $config,
        private readonly LoopInterface $loop,
        private readonly \Closure $ready,
        private readonly \Closure $collect,
    ) {}

    public function start(): void
    {
        if (!$this->config->enabled || is_resource($this->server)) {
            return;
        }
        $errno = 0;
        $errstr = '';
        $socket = $this->quietly(function () use (&$errno, &$errstr): mixed {
            return stream_socket_server(
                $this->uri(),
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );
        });
        if (!is_resource($socket)) {
            $detail = $errstr !== '' ? $errstr : 'bind failed (' . $errno . ')';

            throw new \RuntimeException('cannot listen on admin ' . $this->config->listen . ': ' . $detail);
        }
        stream_set_blocking($socket, false);
        $this->server = $socket;
        $this->loop->onReadable($socket, function (mixed $stream): void {
            $this->accept();
        });
    }

    /**
     * Close this process's copy of the admin sockets after fork.
     * The parent keeps its own descriptors.
     */
    public function releaseInherited(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client->stream)) {
                fclose($client->stream);
            }
        }
        $this->clients = [];
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        $this->server = null;
    }

    public function close(): void
    {
        foreach (array_values($this->clients) as $client) {
            $this->drop($client);
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

    private function accept(): void
    {
        $server = $this->server;
        if (!is_resource($server)) {
            return;
        }
        $client = $this->quietly(static function () use ($server): mixed {
            return stream_socket_accept($server, 0);
        });
        if (!is_resource($client)) {
            return;
        }
        if (count($this->clients) >= self::MAX_CLIENTS) {
            $this->quietly(static function () use ($client): void {
                fclose($client);
            });

            return;
        }
        stream_set_blocking($client, false);
        $exchange = new Exchange($client);
        $this->clients[(int) $client] = $exchange;
        $exchange->timer = $this->loop->addTimer(self::READ_TIMEOUT, function (int $timerId) use ($exchange): void {
            if ($exchange->timer !== $timerId || !is_resource($exchange->stream)) {
                return;
            }
            $this->drop($exchange);
        });
        $this->loop->onReadable($client, function (mixed $stream) use ($exchange): void {
            $this->read($exchange);
        });
    }

    private function read(Exchange $exchange): void
    {
        if (!is_resource($exchange->stream)) {
            return;
        }
        $stream = $exchange->stream;
        $chunk = $this->quietly(static function () use ($stream): mixed {
            return fread($stream, 1024);
        });
        if (!is_string($chunk) || $chunk === '') {
            if (!is_resource($stream) || stream_get_meta_data($stream)['eof'] === true) {
                $this->drop($exchange);
            }

            return;
        }
        $exchange->buffer .= $chunk;
        if (strlen($exchange->buffer) > self::MAX_BYTES && !str_contains($exchange->buffer, "\r\n\r\n")) {
            $this->drop($exchange);

            return;
        }
        if (str_contains($exchange->buffer, "\r\n\r\n")) {
            $this->dispatch($exchange);
        }
    }

    private function dispatch(Exchange $exchange): void
    {
        $end = strpos($exchange->buffer, "\r\n\r\n");
        if ($end === false) {
            $this->drop($exchange);

            return;
        }
        $lines = explode("\r\n", substr($exchange->buffer, 0, $end));
        $request = $lines[0];
        if (preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/1\.[01]$/', $request, $matches) !== 1) {
            $this->reply($exchange, 400, 'Bad Request', "bad request\n", 'text/plain; charset=utf-8');

            return;
        }
        if ($matches[1] !== 'GET') {
            $this->reply($exchange, 405, 'Method Not Allowed', "method not allowed\n", 'text/plain; charset=utf-8');

            return;
        }
        $path = $matches[2];
        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }
        $authorization = '';
        $count = count($lines);
        for ($index = 1; $index < $count; ++$index) {
            $colon = strpos($lines[$index], ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($lines[$index], 0, $colon)));
            if ($name === 'authorization') {
                $authorization = trim(substr($lines[$index], $colon + 1));
            }
        }
        if (!$this->authorized($authorization)) {
            $this->reply($exchange, 401, 'Unauthorized', "unauthorized\n", 'text/plain; charset=utf-8');

            return;
        }
        if ($path === '/healthz') {
            $this->reply($exchange, 200, 'OK', "ok\n", 'text/plain; charset=utf-8');

            return;
        }
        if ($path === '/readyz') {
            $ready = ($this->ready)();
            if ($ready) {
                $this->reply($exchange, 200, 'OK', "ready\n", 'text/plain; charset=utf-8');

                return;
            }
            $this->reply($exchange, 503, 'Service Unavailable', "not ready\n", 'text/plain; charset=utf-8');

            return;
        }
        if ($path === '/status') {
            $this->hold($exchange, function (string $status, string $metrics) use ($exchange): void {
                $this->reply($exchange, 200, 'OK', $status, 'application/json');
            });

            return;
        }
        if ($path === '/metrics') {
            if (!$this->config->metrics) {
                $this->reply($exchange, 404, 'Not Found', "not found\n", 'text/plain; charset=utf-8');

                return;
            }
            $this->hold($exchange, function (string $status, string $metrics) use ($exchange): void {
                $this->reply($exchange, 200, 'OK', $metrics, 'text/plain; version=0.0.4; charset=utf-8');
            });

            return;
        }
        $this->reply($exchange, 404, 'Not Found', "not found\n", 'text/plain; charset=utf-8');
    }

    /**
     * @param \Closure(string, string): void $respond
     */
    private function hold(Exchange $exchange, \Closure $respond): void
    {
        $this->cancelTimer($exchange);
        if (is_resource($exchange->stream)) {
            $this->loop->offReadable($exchange->stream);
        }
        ($this->collect)($respond);
    }

    private function authorized(string $header): bool
    {
        if ($this->config->token === '') {
            return true;
        }
        if (stripos($header, 'Bearer ') !== 0) {
            return false;
        }

        return hash_equals($this->config->token, substr($header, 7));
    }

    private function reply(Exchange $exchange, int $status, string $reason, string $body, string $type): void
    {
        if (!is_resource($exchange->stream)) {
            return;
        }
        $this->cancelTimer($exchange);
        $this->loop->offReadable($exchange->stream);
        $head = 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n"
            . 'Content-Type: ' . $type . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n";
        $payload = $head . $body;
        $stream = $exchange->stream;
        $this->quietly(static function () use ($stream, $payload): mixed {
            return fwrite($stream, $payload);
        });
        $this->drop($exchange);
    }

    private function drop(Exchange $exchange): void
    {
        $stream = $exchange->stream;
        if (!is_resource($stream)) {
            return;
        }
        $id = (int) $stream;
        unset($this->clients[$id]);
        $this->cancelTimer($exchange);
        $this->loop->removeStream($stream);
        $this->quietly(static function () use ($stream): void {
            fclose($stream);
        });
    }

    private function cancelTimer(Exchange $exchange): void
    {
        if ($exchange->timer !== null) {
            $this->loop->cancelTimer($exchange->timer);
            $exchange->timer = null;
        }
    }

    private function uri(): string
    {
        $host = $this->config->host;
        if ($host === '*' || $host === '0.0.0.0') {
            $host = '0.0.0.0';
        }
        if (str_contains($host, ':')) {
            return 'tcp://[' . $host . ']:' . $this->config->port;
        }

        return 'tcp://' . $host . ':' . $this->config->port;
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
