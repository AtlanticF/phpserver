<?php

declare(strict_types=1);

use PhpServer\Worker\RpcWorker;

return static function (RpcWorker $worker): void {
    $worker->on('Demo.ok', static function (?array $params, string $traceId): string {
        return 'ok';
    });
    $worker->on('Demo.boom', static function (?array $params, string $traceId): never {
        throw new \RuntimeException('boom-marker');
    });
};
