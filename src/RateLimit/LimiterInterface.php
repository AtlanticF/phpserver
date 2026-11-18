<?php

declare(strict_types=1);

namespace PhpServer\RateLimit;

/**
 * Optional check that runs before `dealProcess`.
 *
 * Nothing is installed by default. The worker does not call a limiter, and
 * does not spend time on a decision, until the application sets one.
 *
 * @api
 */
interface LimiterInterface
{
    /**
     * `$message` is the raw frame the worker is about to handle.
     */
    public function decide(string $message): Decision;
}
