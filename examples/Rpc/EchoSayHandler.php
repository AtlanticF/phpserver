<?php

declare(strict_types=1);

namespace PhpServer\Examples\Rpc;

/**
 * `Echo.say`: returns the first positional param, or the params object.
 */
final class EchoSayHandler
{
    /**
     * @param array<array-key, mixed>|null $params
     */
    public function handle(?array $params, string $traceId): mixed
    {
        if ($params === null || $params === []) {
            return null;
        }
        if (array_is_list($params)) {
            return $params[0] ?? null;
        }

        return $params;
    }
}
