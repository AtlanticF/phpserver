<?php

declare(strict_types=1);

use PhpServer\Worker\RpcWorker;

return static function (RpcWorker $worker): void {
    $worker->on('Demo.slow', static function (?array $params, string $traceId): string {
        $seconds = getenv('TEST_SLOW_SECONDS');
        $delay = is_string($seconds) && $seconds !== '' ? (float) $seconds : 0.4;
        usleep((int) round($delay * 1_000_000));

        return 'slow';
    });
    $worker->on('Echo.say', static function (?array $params, string $traceId): mixed {
        if ($params === null || !array_is_list($params)) {
            return $params;
        }

        return $params[0] ?? null;
    });
};
