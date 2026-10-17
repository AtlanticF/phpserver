<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * A decoded JSON error body.
 *
 * `hasData` distinguishes a missing `data` key from `"data": null`.
 *
 * @internal
 */
final readonly class ErrorBody
{
    public function __construct(
        public int $code,
        public string $message,
        public mixed $data,
        public bool $hasData,
    ) {}
}
