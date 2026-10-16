<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * Fork, signal, or IPC setup failed.
 *
 * @api
 */
final class ProcessException extends \RuntimeException implements PhpServerException {}
