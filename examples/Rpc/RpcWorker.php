<?php

declare(strict_types=1);

namespace PhpServer\Examples\Rpc;

/**
 * Example RPC worker. Handlers are registered by `examples/Rpc/bootstrap.php`.
 */
final class RpcWorker extends \PhpServer\Worker\RpcWorker {}
