<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Bin;

use PhpServer\Client\Console;
use PhpServer\Tests\Support\TextRpcFixtureProcess;
use PHPUnit\Framework\TestCase;

final class RpcCliTest extends TestCase
{
    public function testHelpIsStable(): void
    {
        $result = $this->runCli(['--help']);

        self::assertSame(0, $result['code']);
        self::assertSame('', $result['stderr']);
        self::assertSame(Console::HELP, $result['stdout']);
    }

    public function testUsageExits64(): void
    {
        $result = $this->runCli([]);

        self::assertSame(64, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('phpserver-rpc:', $result['stderr']);
    }

    public function testEcho(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        try {
            $result = $this->runCli([
                $fixture->address,
                'Echo.say',
                '["hi"]',
                '--trace-id=web-1a2b3c',
            ]);
        } finally {
            $fixture->stop();
        }

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("\"hi\"\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testRawAndVerbose(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        try {
            $result = $this->runCli([
                $fixture->address,
                'Echo.say',
                '["hi"]',
                '-v',
                '--raw',
                '--trace-id=web-1a2b3c',
            ]);
        } finally {
            $fixture->stop();
        }

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('{"result":"hi"}', $result['stdout']);
        self::assertStringContainsString('TRPC/1 REQ 1 json', $result['stderr']);
        self::assertStringContainsString('TRPC/1 RES 1 json', $result['stderr']);
        self::assertStringContainsString('trace-id: web-1a2b3c', $result['stderr']);
        self::assertStringContainsString('web-1a2b3c', $result['stderr']);
    }

    public function testVerboseTruncatesALongFrame(): void
    {
        $payload = '["' . str_repeat('a', 400) . 'ENDMARKER"]';
        $fixture = TextRpcFixtureProcess::start('echo');
        try {
            $result = $this->runCli([
                $fixture->address,
                'Echo.say',
                $payload,
                '-v',
            ]);
        } finally {
            $fixture->stop();
        }

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('(truncated to 256 bytes)', $result['stderr']);
        self::assertStringNotContainsString('ENDMARKER', $result['stderr']);
    }

    public function testRpcErrorExits1(): void
    {
        $fixture = TextRpcFixtureProcess::start('error');
        try {
            $result = $this->runCli([
                $fixture->address,
                'Echo.sya',
                '["hi"]',
                '-v',
            ]);
        } finally {
            $fixture->stop();
        }

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('error -32601: Method not found', $result['stderr']);
        self::assertStringContainsString('trace-id:', $result['stderr']);
    }

    public function testProtocolErrorExits2(): void
    {
        $fixture = TextRpcFixtureProcess::start('bad-frame');
        try {
            $result = $this->runCli([$fixture->address, 'Echo.say', '["hi"]']);
        } finally {
            $fixture->stop();
        }

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('protocol:', $result['stderr']);
    }

    public function testNetworkErrorExits3(): void
    {
        $fixture = TextRpcFixtureProcess::start('slow');
        try {
            $result = $this->runCli([
                $fixture->address,
                'Echo.say',
                '["hi"]',
                '--timeout=200',
            ]);
        } finally {
            $fixture->stop();
        }

        self::assertSame(3, $result['code'], $result['stderr']);
        self::assertStringContainsString('network:', $result['stderr']);
    }

    public function testPing(): void
    {
        $fixture = TextRpcFixtureProcess::start('echo');
        try {
            $result = $this->runCli([$fixture->address, '--ping', '-v']);
        } finally {
            $fixture->stop();
        }

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("pong\n", $result['stdout']);
        self::assertStringContainsString('TRPC/1 PING', $result['stderr']);
        self::assertStringContainsString('TRPC/1 PONG', $result['stderr']);
    }

    public function testUnknownOptionExits64(): void
    {
        $result = $this->runCli(['--nope']);

        self::assertSame(64, $result['code']);
        self::assertStringContainsString('unknown option', $result['stderr']);
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCli(array $args): array
    {
        $root = dirname(__DIR__, 3);
        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY, $root . '/bin/phpserver-rpc'], $args),
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            self::fail('proc_open failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
