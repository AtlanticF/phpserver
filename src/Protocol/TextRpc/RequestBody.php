<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

/**
 * A decoded JSON request body.
 *
 * `params` is null when the key was omitted. A JSON array becomes a list.
 * A JSON object becomes an associative array. An empty object and an empty
 * array both become `[]` after decoding; callers that need `{}` on the wire
 * pass a `stdClass` into {@see JsonCodec::encodeRequest()} instead.
 *
 * @internal
 */
final readonly class RequestBody
{
    /**
     * @param array<array-key, mixed>|null $params
     */
    public function __construct(
        public string $method,
        public ?array $params,
    ) {
        if ($method === '') {
            throw new \InvalidArgumentException('method must be a non-empty string');
        }
    }
}
