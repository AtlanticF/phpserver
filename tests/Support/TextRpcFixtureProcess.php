<?php

declare(strict_types=1);

namespace PhpServer\Tests\Support;

/**
 * A child-process {@see \PhpServer\Tests\Fixture\TextRpcFixture}.
 */
final class TextRpcFixtureProcess
{
    private bool $stopped = false;

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    private function __construct(
        private $process,
        public readonly string $address,
        private $stdout,
        private $stderr,
    ) {}

    public static function start(string $mode): self
    {
        $script = dirname(__DIR__) . '/Fixture/serve.php';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, $mode],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process) || !isset($pipes[1], $pipes[2]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            throw new \RuntimeException('could not start the text-rpc fixture');
        }
        $line = fgets($pipes[1]);
        if (!is_string($line) || trim($line) === '') {
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new \RuntimeException(
                'fixture did not report an address' . (is_string($error) && $error !== '' ? ': ' . $error : ''),
            );
        }

        return new self($process, trim($line), $pipes[1], $pipes[2]);
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        fclose($this->stdout);
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process);
            $deadline = hrtime(true) + 1_000_000_000;
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($this->process, SIGKILL);
            }
        }
        fclose($this->stderr);
        proc_close($this->process);
    }
}
