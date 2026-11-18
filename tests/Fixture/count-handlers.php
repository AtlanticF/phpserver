<?php

declare(strict_types=1);

use PhpServer\Worker\RpcWorker;

return static function (RpcWorker $worker): void {
    $seen = 0;
    $worker->on('Demo.count', static function (?array $params, string $traceId) use (&$seen): string {
        ++$seen;
        if ($seen % 20 === 0) {
            throw new \RuntimeException('boom');
        }

        return 'ok';
    });
};
