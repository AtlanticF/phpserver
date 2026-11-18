<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Metrics;

use PhpServer\Metrics\PrometheusText;
use PHPUnit\Framework\TestCase;

final class PrometheusTextTest extends TestCase
{
    public function testRenderUsesThePrefixAndCounterSuffix(): void
    {
        $text = PrometheusText::render([
            'requests' => 100,
            'errors' => 5,
        ]);
        self::assertStringContainsString("# TYPE phpserver_requests_total counter\nphpserver_requests_total 100\n", $text);
        self::assertStringContainsString("phpserver_errors_total 5\n", $text);
        self::assertStringContainsString("phpserver_rejected_total 0\n", $text);
        self::assertStringContainsString("phpserver_drain_timeout_total 0\n", $text);

        $promtool = $this->promtool();
        if ($promtool === null) {
            return;
        }
        $pipes = [];
        $process = proc_open([$promtool, 'check', 'metrics'], [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            self::fail('could not run promtool');
        }
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertSame(0, $code, is_string($stderr) ? $stderr : '');
    }

    private function promtool(): ?string
    {
        $path = shell_exec('command -v promtool');
        if (!is_string($path)) {
            return null;
        }
        $path = trim($path);

        return $path === '' ? null : $path;
    }
}
