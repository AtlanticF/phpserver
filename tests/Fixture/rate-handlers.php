<?php

declare(strict_types=1);

use PhpServer\RateLimit\TokenBucket;
use PhpServer\Support\SystemClock;
use PhpServer\Worker\RpcWorker;

return static function (RpcWorker $worker): void {
    $worker->setLimiter(new TokenBucket(new SystemClock(), 0.0, 1));
    $worker->on('Echo.say', static function (?array $params, string $traceId): mixed {
        if ($params === null || !array_is_list($params)) {
            return $params;
        }

        return $params[0] ?? null;
    });
};
