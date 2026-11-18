<?php

declare(strict_types=1);

namespace PhpServer\Worker;

use PhpServer\Config\WorkerConfig;

/**
 * Binds one worker's TCP listen socket.
 *
 * The master binds this once and forks workers that inherit the descriptor.
 * A standalone `bin/worker` binds it itself. `SO_REUSEPORT` is not set.
 *
 * @internal
 */
final class ListenSocket
{
    /**
     * @return resource
     */
    public static function bind(WorkerConfig $config): mixed
    {
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'socket' => [
                'backlog' => $config->backlog,
                'so_reuseaddr' => true,
                'tcp_nodelay' => true,
            ],
        ]);
        $server = self::quietly(function () use ($config, $context, &$errno, &$errstr): mixed {
            return stream_socket_server(
                self::uri($config),
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $context,
            );
        });
        if (!is_resource($server)) {
            $detail = $errstr !== '' ? $errstr : 'bind failed (' . $errno . ')';

            throw new \RuntimeException('cannot listen on ' . $config->listen . ': ' . $detail);
        }
        stream_set_blocking($server, false);

        return $server;
    }

    public static function uri(WorkerConfig $config): string
    {
        $host = $config->host;
        if ($host === '*' || $host === '0.0.0.0') {
            $host = '0.0.0.0';
        }
        if (str_contains($host, ':')) {
            return 'tcp://[' . $host . ']:' . $config->port;
        }

        return 'tcp://' . $host . ':' . $config->port;
    }

    private static function quietly(\Closure $run): mixed
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
