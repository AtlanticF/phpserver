<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * Configuration failed validation before the process accepts work.
 *
 * Callers that boot the server should print {@see self::getMessage()} and exit non-zero.
 * {@see \PhpServer\Config\ConfigLoader::loadOrExit()} does that.
 *
 * @api
 */
final class ConfigException extends \RuntimeException implements PhpServerException {}
