<?php

declare(strict_types=1);

namespace PhpServer\Tests\Unit\Config;

use PhpServer\Config\ConfigLoader;
use PhpServer\Config\WorkerConfig;
use PhpServer\Exception\ConfigException;
use PhpServer\Log\LogLevel;
use PhpServer\Log\StdoutLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testExampleConfigsLoadWithDefaults(): void
    {
        $loader = new ConfigLoader();
        $root = dirname(__DIR__, 3);

        $echo = $loader->load($root . '/config/server.echo.php', []);
        self::assertSame([], $echo->warnings);
        self::assertSame(LogLevel::Info, $echo->log->level);
        self::assertFalse($echo->log->json);
        self::assertSame('UTC', $echo->log->timezone);
        self::assertSame(256, $echo->log->bodyTruncateBytes);
        self::assertTrue($echo->admin->enabled);
        self::assertSame('127.0.0.1:9100', $echo->admin->listen);
        self::assertTrue($echo->admin->metrics);

        $worker = $echo->worker('Echo');
        self::assertSame('127.0.0.1:9000', $worker->listen);
        self::assertSame('PhpServer\\Examples\\Echo\\EchoWorker', $worker->workerClass);
        self::assertSame(1, $worker->childCount);
        self::assertSame(30.0, $worker->recvTimeout);
        self::assertSame(30.0, $worker->sendTimeout);
        self::assertSame(60.0, $worker->idleTimeout);
        self::assertSame(30.0, $worker->processTimeout);
        self::assertSame(WorkerConfig::DEFAULT_MAX_BODY_BYTES, $worker->maxBodyBytes);
        self::assertSame(
            WorkerConfig::DEFAULT_MAX_BODY_BYTES + WorkerConfig::FRAME_OVERHEAD_BYTES,
            $worker->maxFrameBytes,
        );
        self::assertSame(32, $worker->pipelineBacklog);
        self::assertSame(0, $worker->maxRequests);
        self::assertSame(0.0, $worker->maxWorkTime);

        $example = $loader->load($root . '/config/server.example.php', []);
        self::assertSame([], $example->warnings);
        self::assertSame(4, $example->worker('Rpc')->childCount);
        self::assertSame(1048836, $example->worker('Rpc')->maxFrameBytes);
    }

    public function testEnvironmentOverridesTheFile(): void
    {
        $path = $this->dump([
            'log' => ['level' => 'error', 'json' => false],
            'workers' => [
                'Echo' => [
                    'listen' => '127.0.0.1:9000',
                    'worker_class' => 'Foo',
                    'child_count' => 4,
                ],
                'Rpc' => [
                    'listen' => '127.0.0.1:9001',
                    'worker_class' => 'Bar',
                    'child_count' => 4,
                ],
            ],
        ]);

        $config = (new ConfigLoader())->load($path, [
            'PHPSERVER_LOG_LEVEL' => 'DEBUG',
            'PHPSERVER_LOG_JSON' => 'true',
            'PHPSERVER_CHILD_COUNT' => '2',
            'PHPSERVER_WORKER_ECHO_CHILD_COUNT' => '5',
            'PHPSERVER_LISTEN' => '127.0.0.1:9101',
            'PHPSERVER_WORKER_RPC_LISTEN' => '10.0.0.8:9102',
        ]);

        self::assertSame(LogLevel::Debug, $config->log->level);
        self::assertTrue($config->log->json);
        self::assertSame(5, $config->worker('Echo')->childCount);
        self::assertSame('127.0.0.1:9101', $config->worker('Echo')->listen);
        self::assertSame(2, $config->worker('Rpc')->childCount);
        self::assertSame('10.0.0.8:9102', $config->worker('Rpc')->listen);
    }

    public function testFileWinsWhenEnvironmentIsEmpty(): void
    {
        $path = $this->dump(self::withWorker(['child_count' => 4]));
        $config = (new ConfigLoader())->load($path, []);

        self::assertSame(4, $config->worker('Echo')->childCount);
    }

    public function testUnknownKeysWarnAndDoNotFail(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $logger = new StdoutLogger(LogLevel::Warning, true, null, $stream, 'UTC');
        $path = $this->dump([
            'nope' => true,
            'log' => ['level' => 'info', 'also_nope' => 1],
            'workers' => [
                'Echo' => [
                    'listen' => '127.0.0.1:9000',
                    'worker_class' => 'Foo',
                    'extra' => 'x',
                ],
            ],
        ]);

        $config = (new ConfigLoader($logger))->load($path, ['PHPSERVER_NOT_A_KEY' => '1']);

        self::assertSame('127.0.0.1:9000', $config->worker('Echo')->listen);
        $text = implode("\n", $config->warnings);
        self::assertStringContainsString('Unknown configuration key "nope"', $text);
        self::assertStringContainsString('Unknown configuration key "log.also_nope"', $text);
        self::assertStringContainsString('Unknown configuration key "workers.Echo.extra"', $text);
        self::assertStringContainsString('Unknown environment variable "PHPSERVER_NOT_A_KEY"', $text);

        rewind($stream);
        $raw = stream_get_contents($stream);
        self::assertIsString($raw);
        $lines = array_values(array_filter(explode("\n", $raw), static fn(string $line): bool => $line !== ''));
        self::assertCount(4, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame('warning', $decoded['level']);
            self::assertArrayHasKey('ts', $decoded);
            self::assertIsString($decoded['ts']);
            self::assertInstanceOf(
                \DateTimeImmutable::class,
                \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $decoded['ts']),
            );
        }
    }

    public function testDisabledAdminMayReuseTheWorkerPort(): void
    {
        $path = $this->dump([
            'admin' => [
                'enabled' => false,
                'listen' => '127.0.0.1:9000',
            ],
            'workers' => [
                'Echo' => [
                    'listen' => '127.0.0.1:9000',
                    'worker_class' => 'Foo',
                ],
            ],
        ]);

        $config = (new ConfigLoader())->load($path, []);

        self::assertFalse($config->admin->enabled);
        self::assertSame(9000, $config->admin->port);
        self::assertSame(9000, $config->worker('Echo')->port);
    }

    public function testDistinctHostsMayShareAPort(): void
    {
        $path = $this->dump([
            'workers' => [
                'Echo' => ['listen' => '127.0.0.1:9000', 'worker_class' => 'Foo'],
                'Rpc' => ['listen' => '10.0.0.8:9000', 'worker_class' => 'Bar'],
            ],
        ]);

        $config = (new ConfigLoader())->load($path, []);

        self::assertSame(9000, $config->worker('Echo')->port);
        self::assertSame(9000, $config->worker('Rpc')->port);
    }

    public function testIpv6ListenIsCanonical(): void
    {
        $path = $this->dump(self::withWorker(['listen' => '[::1]:9000']));
        $config = (new ConfigLoader())->load($path, []);

        self::assertSame('[::1]:9000', $config->worker('Echo')->listen);
        self::assertSame('::1', $config->worker('Echo')->host);
        self::assertSame(9000, $config->worker('Echo')->port);
    }

    public function testMissingFileAndNonArrayFileFail(): void
    {
        $loader = new ConfigLoader();

        try {
            $loader->load('/no/such/phpserver-config.php', []);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString('not readable', $e->getMessage());
        }

        $path = $this->dumpRaw("<?php\n\ndeclare(strict_types=1);\n\nreturn 1;\n");
        try {
            $loader->load($path, []);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString('must return an array', $e->getMessage());
        }
    }

    public function testIllegalEnvironmentTimeoutFails(): void
    {
        $path = $this->dump(self::withWorker([]));

        $this->expectException(ConfigException::class);
        (new ConfigLoader())->load($path, ['PHPSERVER_RECV_TIMEOUT' => 'soon']);
    }

    public function testUnknownWorkerThrows(): void
    {
        $path = $this->dump(self::withWorker([]));
        $config = (new ConfigLoader())->load($path, []);

        $this->expectException(ConfigException::class);
        $config->worker('Missing');
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigThrows(array $config, string $needle): void
    {
        $loader = new ConfigLoader();

        try {
            $loader->load($this->dump($config), []);
            self::fail('expected ConfigException containing ' . $needle);
        } catch (ConfigException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'child count zero' => [self::withWorker(['child_count' => 0]), 'child_count'];
        yield 'child count negative' => [self::withWorker(['child_count' => -2]), 'child_count'];
        yield 'missing worker class' => [self::workerWithout('worker_class'), 'worker_class'];
        yield 'empty worker class' => [self::withWorker(['worker_class' => '']), 'worker_class'];
        yield 'recv timeout zero' => [self::withWorker(['recv_timeout' => 0]), 'recv_timeout'];
        yield 'recv timeout negative' => [self::withWorker(['recv_timeout' => -1]), 'recv_timeout'];
        yield 'idle timeout not finite' => [self::withWorker(['idle_timeout' => INF]), 'idle_timeout'];
        yield 'send timeout not a number' => [self::withWorker(['send_timeout' => '30']), 'send_timeout'];
        yield 'process timeout zero' => [self::withWorker(['process_timeout' => 0.0]), 'process_timeout'];
        yield 'same listen on two workers' => [[
            'workers' => [
                'Echo' => ['listen' => '127.0.0.1:9000', 'worker_class' => 'Foo'],
                'Rpc' => ['listen' => '127.0.0.1:9000', 'worker_class' => 'Bar'],
            ],
        ], 'conflict'];
        yield 'admin shares the worker port' => [[
            'admin' => ['listen' => '127.0.0.1:9000'],
            'workers' => [
                'Echo' => ['listen' => '127.0.0.1:9000', 'worker_class' => 'Foo'],
            ],
        ], 'conflict'];
        yield 'wildcard overlaps a specific host' => [[
            'workers' => [
                'Echo' => ['listen' => '0.0.0.0:9000', 'worker_class' => 'Foo'],
                'Rpc' => ['listen' => '127.0.0.1:9000', 'worker_class' => 'Bar'],
            ],
        ], 'conflict'];
        yield 'ipv6 wildcard overlaps' => [[
            'admin' => ['listen' => '[::]:9100'],
            'workers' => [
                'Echo' => ['listen' => '127.0.0.1:9100', 'worker_class' => 'Foo'],
            ],
        ], 'conflict'];
        yield 'body above 64 MiB' => [self::withWorker(['max_body_bytes' => WorkerConfig::MAX_BODY_BYTES + 1]), 'max_body_bytes'];
        yield 'frame smaller than a legal body' => [self::withWorker(['max_frame_bytes' => WorkerConfig::DEFAULT_MAX_BODY_BYTES]), 'max_frame_bytes'];
        yield 'no workers' => [['log' => ['level' => 'info']], 'workers'];
    }

    /**
     * @param array<string, mixed> $worker
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>
     */
    private static function withWorker(array $worker, array $root = []): array
    {
        $root['workers'] = [
            'Echo' => array_merge([
                'listen' => '127.0.0.1:9000',
                'worker_class' => 'Foo',
            ], $worker),
        ];

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerWithout(string $key): array
    {
        $worker = [
            'listen' => '127.0.0.1:9000',
            'worker_class' => 'Foo',
        ];
        unset($worker[$key]);

        return ['workers' => ['Echo' => $worker]];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dump(array $config): string
    {
        return $this->dumpRaw("<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    }

    private function dumpRaw(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpserver-cfg-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        if (file_put_contents($path, $source) === false) {
            self::fail('failed to write ' . $path);
        }
        $this->files[] = $path;

        return $path;
    }
}
