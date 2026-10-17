<?php

declare(strict_types=1);

namespace PhpServer\Client;

use PhpServer\Exception\PhpServerException;

/**
 * The TCP connection failed, timed out, or closed before a frame was finished.
 *
 * A call that ends this way has an unknown result: the request may already
 * have run. Callers must not retry a non-idempotent method automatically.
 *
 * @api
 */
final class NetworkException extends \RuntimeException implements PhpServerException {}
