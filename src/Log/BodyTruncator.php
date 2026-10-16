<?php

declare(strict_types=1);

namespace PhpServer\Log;

/**
 * Cuts a logged body to a byte limit.
 *
 * The cut is by bytes (`strlen` / `substr`), matching the protocol's byte lengths.
 * A multibyte character may be split. There is no suffix: the result is never
 * longer than the limit, and a body that is exactly the limit is unchanged.
 * Callers truncate before logging. The logger does not do it implicitly.
 *
 * @internal
 */
final class BodyTruncator
{
    private function __construct() {}

    public static function truncate(string $body, int $limit): string
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Body truncate limit must be >= 0');
        }
        if (strlen($body) <= $limit) {
            return $body;
        }

        return substr($body, 0, $limit);
    }
}
