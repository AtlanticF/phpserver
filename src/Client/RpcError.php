<?php

declare(strict_types=1);

namespace PhpServer\Client;

use PhpServer\Exception\PhpServerException;

/**
 * The server replied with an ERR frame.
 *
 * {@see self::getCode()} is the text-rpc/1 error code. `traceId` is the id
 * from the response header. `hasData` is false when the body omitted `data`.
 *
 * @api
 */
final class RpcError extends \RuntimeException implements PhpServerException
{
    public function __construct(
        string $message,
        int $code,
        public readonly mixed $data,
        public readonly bool $hasData,
        public readonly int $id,
        public readonly string $traceId,
        public readonly string $rawBody,
    ) {
        parent::__construct($message, $code);
    }
}
