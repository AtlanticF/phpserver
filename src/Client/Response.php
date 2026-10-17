<?php

declare(strict_types=1);

namespace PhpServer\Client;

/**
 * A successful RES frame.
 *
 * `result` is the decoded JSON value. `rawBody` is the body bytes as they
 * arrived, before decoding. `traceId` is copied from the response header.
 *
 * @api
 */
final readonly class Response
{
    public function __construct(
        public mixed $result,
        public int $id,
        public string $traceId,
        public string $rawBody,
    ) {}
}
