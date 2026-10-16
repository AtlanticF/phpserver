<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Log;

use PhpServer\Log\LogLevel;
use PhpServer\Log\RequestId;
use PhpServer\Log\StdoutLogger;
use PhpServer\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class StdoutLoggerTest extends TestCase
{
    /** @var array<int, resource> */
    private array $streams = [];

    public function testJsonLineHasTimestampLevelAndRequestId(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-16T15:26:00.123456+08:00'));
        $logger = $this->logger($clock, json: true);
        $logger->setRequestId('r-7f3a9c01');
        $logger->info('accepted');

        $record = $this->oneRecord($logger);

        self::assertSame('2026-10-16T07:26:00.123456+00:00', $record['ts']);
        self::assertSame('info', $record['level']);
        self::assertSame('accepted', $record['message']);
        self::assertSame('r-7f3a9c01', $record['request_id']);
        self::assertInstanceOf(
            \DateTimeImmutable::class,
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $record['ts']),
        );
    }

    public function testJsonOmitsRequestIdWhenNoneIsSet(): void
    {
        $logger = $this->logger(new MutableClock(), json: true);
        $logger->warning('no correlation id');

        $record = $this->oneRecord($logger);

        self::assertArrayHasKey('ts', $record);
        self::assertSame('warning', $record['level']);
        self::assertArrayNotHasKey('request_id', $record);
    }

    public function testContextRequestIdOverridesTheLoggerAndEachLineIsJson(): void
    {
        $logger = $this->logger(new MutableClock(), json: true);
        $logger->setRequestId(RequestId::fromString('r-from-logger'));
        $logger->info('first', ['request_id' => 'web-1a2b3c']);
        $logger->error('second');

        $records = $this->records($logger);

        self::assertCount(2, $records);
        self::assertSame('web-1a2b3c', $records[0]['request_id']);
        self::assertSame('info', $records[0]['level']);
        self::assertSame('r-from-logger', $records[1]['request_id']);
        self::assertSame('error', $records[1]['level']);
        self::assertArrayNotHasKey('context', $records[0]);
    }

    public function testInvalidContextRequestIdIsDropped(): void
    {
        $logger = $this->logger(new MutableClock(), json: true);
        $logger->info('hello', ['request_id' => "bad id\n"]);

        $record = $this->oneRecord($logger);

        self::assertArrayNotHasKey('request_id', $record);
        self::assertArrayNotHasKey('context', $record);
    }

    public function testDebugIsDroppedBelowTheMinimumLevel(): void
    {
        $logger = $this->logger(new MutableClock(), json: true, minimum: LogLevel::Info);
        $logger->debug('hidden');
        $logger->info('shown');

        $records = $this->records($logger);

        self::assertCount(1, $records);
        self::assertSame('shown', $records[0]['message']);
    }

    public function testStringLevelAndPlaceholders(): void
    {
        $logger = $this->logger(new MutableClock(), json: true, minimum: LogLevel::Debug);
        $logger->log('error', 'listen {port}', ['port' => 9000, 'left' => 'kept']);

        $record = $this->oneRecord($logger);

        self::assertSame('error', $record['level']);
        self::assertSame('listen 9000', $record['message']);
        self::assertSame(['left' => 'kept'], $record['context']);
    }

    public function testUnknownLevelThrows(): void
    {
        $logger = $this->logger(new MutableClock(), json: true);

        $this->expectException(\InvalidArgumentException::class);
        $logger->log('verbose', 'nope');
    }

    public function testTextLineCarriesTheRequestId(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-16T07:26:00+00:00'));
        $logger = $this->logger($clock, json: false);
        $logger->setRequestId('r-abc');
        $logger->notice("line\nbreak");

        $line = $this->raw($logger);

        self::assertSame(
            "2026-10-16T07:26:00.000000+00:00 notice request_id=r-abc line\\nbreak\n",
            $line,
        );
    }

    private function logger(MutableClock $clock, bool $json, LogLevel $minimum = LogLevel::Debug): StdoutLogger
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $logger = new StdoutLogger($minimum, $json, $clock, $stream, 'UTC');
        // Keep the stream reachable through the logger only. Tests rewind via reflection-free capture
        // by stashing it on the logger instance through a test property stored here.
        $this->streams[spl_object_id($logger)] = $stream;

        return $logger;
    }

    /**
     * @return array<string, mixed>
     */
    private function oneRecord(StdoutLogger $logger): array
    {
        $records = $this->records($logger);
        self::assertCount(1, $records);

        return $records[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(StdoutLogger $logger): array
    {
        $raw = trim($this->raw($logger));
        if ($raw === '') {
            return [];
        }

        $records = [];
        foreach (explode("\n", $raw) as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $records[] = $decoded;
        }

        return $records;
    }

    private function raw(StdoutLogger $logger): string
    {
        $stream = $this->streams[spl_object_id($logger)];
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
