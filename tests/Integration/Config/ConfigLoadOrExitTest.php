<?php

declare(strict_types=1);

namespace PhpServer\Tests\Integration\Config;

use PHPUnit\Framework\TestCase;

final class ConfigLoadOrExitTest extends TestCase
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

    public function testInvalidConfigExitsNonZeroWithAClearMessage(): void
    {
        $config = $this->dump("<?php\n\ndeclare(strict_types=1);\n\nreturn ['workers' => []];\n");
        $result = $this->runLoad($config);

        self::assertSame(1, $result['code']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('phpserver: configuration error:', $result['stderr']);
        self::assertStringContainsString('workers', $result['stderr']);
        self::assertStringNotContainsString('Stack trace', $result['stderr']);
    }

    public function testValidConfigDoesNotExit(): void
    {
        $result = $this->runLoad(dirname(__DIR__, 3) . '/config/server.echo.php');

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame("ok\n", $result['stdout']);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLoad(string $configPath): array
    {
        $runner = $this->dump(
            "<?php\n\ndeclare(strict_types=1);\n\nrequire " . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ";\n"
            . "(new PhpServer\\Config\\ConfigLoader())->loadOrExit(" . var_export($configPath, true) . ", []);\n"
            . "echo \"ok\\n\";\n",
        );

        return $this->execute([PHP_BINARY, $runner]);
    }

    /**
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function execute(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3));
        if (!is_resource($process)) {
            self::fail('proc_open failed');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function dump(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpserver-exit-');
        if ($path === false || file_put_contents($path, $source) === false) {
            self::fail('failed to write temp script');
        }
        $this->files[] = $path;

        return $path;
    }
}
