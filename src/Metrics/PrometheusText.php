<?php

declare(strict_types=1);

namespace PhpServer\Metrics;

/**
 * Prometheus text for the admin `/metrics` scrape.
 *
 * Names use the `phpserver_` prefix and the `_total` suffix. This is the
 * only renderer; the counters themselves stay numeric.
 *
 * @internal
 */
final class PrometheusText
{
    /**
     * @var array<string, string>
     */
    private const array METRICS = [
        'accept' => 'Accepted business connections',
        'requests' => 'RPC requests handled',
        'errors' => 'Failed RPC requests',
        'worker_restarts' => 'Worker processes started after the initial set',
        'conn_rejected' => 'Connections rejected at the worker limit',
        'rejected' => 'Requests rejected by the rate limiter',
        'degraded' => 'Requests served as a degraded result',
        'drain_timeout' => 'Drains that ended in SIGKILL',
    ];

    /**
     * @param array<string, int> $samples short counter name => value
     */
    public static function render(array $samples): string
    {
        $lines = [];
        foreach (self::METRICS as $name => $help) {
            $metric = 'phpserver_' . $name . '_total';
            $lines[] = '# HELP ' . $metric . ' ' . $help;
            $lines[] = '# TYPE ' . $metric . ' counter';
            $lines[] = $metric . ' ' . ($samples[$name] ?? 0);
        }

        return implode("\n", $lines) . "\n";
    }
}
