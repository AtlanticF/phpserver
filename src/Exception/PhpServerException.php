<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * Marker for every exception raised by this package.
 *
 * Catch this when a caller wants any failure that originated inside phpserver,
 * without forcing those failures onto a single base class.
 *
 * @api
 */
interface PhpServerException extends \Throwable {}
