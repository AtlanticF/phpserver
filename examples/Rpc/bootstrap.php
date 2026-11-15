<?php

declare(strict_types=1);

use PhpServer\Examples\Rpc\EchoSayHandler;
use PhpServer\Examples\Rpc\RpcWorker;

$handler = new EchoSayHandler();

return static function (RpcWorker $worker) use ($handler): void {
    $worker->on('Echo.say', $handler->handle(...));
};
