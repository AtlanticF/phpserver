<?php

declare(strict_types=1);

namespace PhpServer\Tests\Fixture;

use PhpServer\Worker\RpcWorker;

/**
 * Concrete RPC worker for integration tests. Handlers come from a bootstrap file.
 */
final class HandlerWorker extends RpcWorker {}
