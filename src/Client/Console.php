<?php

declare(strict_types=1);

namespace PhpServer\Client;

use PhpServer\Exception\ProtocolException;
use PhpServer\Log\BodyTruncator;
use PhpServer\Protocol\TextRpc\JsonCodec;

/**
 * `bin/phpserver-rpc`. The help text and the exit codes are part of the CLI contract.
 *
 * @internal
 */
final class Console
{
    public const int EXIT_OK = 0;

    public const int EXIT_RPC = 1;

    public const int EXIT_PROTOCOL = 2;

    public const int EXIT_NETWORK = 3;

    public const int EXIT_USAGE = 64;

    public const int DEFAULT_TIMEOUT_MS = 30000;

    /**
     * Same default as `log.body_truncate_bytes`.
     */
    public const int VERBOSE_BYTES = 256;

    public const string HELP = <<<'HELP'
        Usage:
          phpserver-rpc <host:port> <Method> <json-params> [-v] [--trace-id=<id>] [--timeout=<ms>] [--raw]
          phpserver-rpc <host:port> --ping [-v] [--timeout=<ms>] [--raw]

        Options:
          -h, --help         Show this help and exit
          -v                 Print sent and received frames on stderr, truncated to 256 bytes
          --raw              Print the raw response body
          --ping             Send PING and wait for PONG
          --trace-id=<id>    Trace id, 1 to 64 of [A-Za-z0-9._-]
          --timeout=<ms>     Connect and call timeout in milliseconds (default 30000)

        Exit codes:
          0   success
          1   RPC error reply
          2   protocol error
          3   network error
          64  usage error

        HELP;

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            return $this->execute($argv);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, 'phpserver-rpc: ' . $exception->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (RpcError $exception) {
            fwrite(STDERR, 'error ' . $exception->getCode() . ': ' . $exception->getMessage() . "\n");

            return self::EXIT_RPC;
        } catch (ProtocolException $exception) {
            fwrite(STDERR, 'protocol: ' . $exception->getMessage() . "\n");

            return self::EXIT_PROTOCOL;
        } catch (NetworkException $exception) {
            fwrite(STDERR, 'network: ' . $exception->getMessage() . "\n");

            return self::EXIT_NETWORK;
        } catch (\JsonException $exception) {
            fwrite(STDERR, "protocol: Cannot encode the result\n");

            return self::EXIT_PROTOCOL;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function execute(array $argv): int
    {
        $parsed = $this->parse(array_slice($argv, 1));
        if ($parsed['help']) {
            fwrite(STDOUT, self::HELP);

            return self::EXIT_OK;
        }

        $verbose = $parsed['verbose'];
        $client = new Client();
        if ($verbose) {
            $client->onWire(function (string $direction, string $bytes): void {
                $this->printWire($direction, $bytes);
            });
        }
        try {
            $client->connect($parsed['address'], $parsed['timeoutMs'] / 1000);
            if ($parsed['ping']) {
                $client->ping($parsed['timeoutMs'], $parsed['traceId']);
                if (!$parsed['raw']) {
                    fwrite(STDOUT, "pong\n");
                }

                return self::EXIT_OK;
            }
            $response = $client->call(
                $parsed['method'],
                $this->params($parsed['params']),
                $parsed['traceId'],
                $parsed['timeoutMs'],
            );
            if ($verbose) {
                fwrite(STDERR, 'trace-id: ' . $response->traceId . "\n");
            }
            if ($parsed['raw']) {
                fwrite(STDOUT, $response->rawBody);

                return self::EXIT_OK;
            }
            fwrite(STDOUT, $this->encodeResult($response->result) . "\n");

            return self::EXIT_OK;
        } catch (RpcError $exception) {
            if ($verbose) {
                fwrite(STDERR, 'trace-id: ' . $exception->traceId . "\n");
            }
            if ($parsed['raw']) {
                fwrite(STDOUT, $exception->rawBody);
            }
            throw $exception;
        } finally {
            $client->close();
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{
     *     help: bool,
     *     verbose: bool,
     *     raw: bool,
     *     ping: bool,
     *     traceId: ?string,
     *     timeoutMs: int,
     *     address: string,
     *     method: string,
     *     params: string
     * }
     */
    private function parse(array $args): array
    {
        $help = false;
        $verbose = false;
        $raw = false;
        $ping = false;
        $traceId = null;
        $timeoutMs = self::DEFAULT_TIMEOUT_MS;
        $positionals = [];
        $count = count($args);
        for ($index = 0; $index < $count; $index++) {
            $arg = $args[$index];
            if ($arg === '--') {
                for ($rest = $index + 1; $rest < $count; $rest++) {
                    $positionals[] = $args[$rest];
                }
                break;
            }
            if ($arg === '-h' || $arg === '--help') {
                $help = true;
                continue;
            }
            if ($arg === '-v') {
                $verbose = true;
                continue;
            }
            if ($arg === '--raw') {
                $raw = true;
                continue;
            }
            if ($arg === '--ping') {
                $ping = true;
                continue;
            }
            if ($arg === '--trace-id' || str_starts_with($arg, '--trace-id=')) {
                $traceId = $this->optionValue($args, $index, '--trace-id');
                continue;
            }
            if ($arg === '--timeout' || str_starts_with($arg, '--timeout=')) {
                $timeoutMs = $this->timeoutValue($this->optionValue($args, $index, '--timeout'));
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('unknown option ' . $arg);
            }
            $positionals[] = $arg;
        }

        if ($help) {
            return $this->options(true, $verbose, $raw, $ping, $traceId, $timeoutMs, '', '', '');
        }
        if ($traceId !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $traceId) !== 1) {
            throw new \InvalidArgumentException('trace-id must match [A-Za-z0-9._-]{1,64}');
        }
        if ($ping) {
            if (count($positionals) !== 1) {
                throw new \InvalidArgumentException('ping takes only host:port');
            }

            return $this->options(false, $verbose, $raw, true, $traceId, $timeoutMs, $positionals[0], '', '');
        }
        if (count($positionals) !== 3) {
            throw new \InvalidArgumentException('give host:port, method, and json-params');
        }
        if ($positionals[1] === '') {
            throw new \InvalidArgumentException('method must be a non-empty string');
        }

        return $this->options(
            false,
            $verbose,
            $raw,
            false,
            $traceId,
            $timeoutMs,
            $positionals[0],
            $positionals[1],
            $positionals[2],
        );
    }

    /**
     * @param list<string> $args
     */
    private function optionValue(array $args, int &$index, string $name): string
    {
        $arg = $args[$index];
        $prefix = $name . '=';
        if (str_starts_with($arg, $prefix)) {
            $value = substr($arg, strlen($prefix));
            if ($value === '') {
                throw new \InvalidArgumentException($name . ' needs a value');
            }

            return $value;
        }
        $index++;
        if (!isset($args[$index]) || str_starts_with($args[$index], '-')) {
            throw new \InvalidArgumentException($name . ' needs a value');
        }

        return $args[$index];
    }

    private function timeoutValue(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,8}$/', $value) !== 1) {
            throw new \InvalidArgumentException('timeout must be a positive number of milliseconds');
        }

        return (int) $value;
    }

    /**
     * @return array<array-key, mixed>|\stdClass
     */
    private function params(string $json): array|\stdClass
    {
        try {
            $value = json_decode($json, false, JsonCodec::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('json-params must be a JSON array or object');
        }
        if (!is_array($value) && !$value instanceof \stdClass) {
            throw new \InvalidArgumentException('json-params must be a JSON array or object');
        }

        return $value;
    }

    private function encodeResult(mixed $result): string
    {
        return json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function printWire(string $direction, string $bytes): void
    {
        $truncated = strlen($bytes) > self::VERBOSE_BYTES;
        $shown = BodyTruncator::truncate($bytes, self::VERBOSE_BYTES);
        $prefix = $direction === 'send' ? '> ' : '< ';
        fwrite(STDERR, $prefix . $shown);
        if (!str_ends_with($shown, "\n")) {
            fwrite(STDERR, "\n");
        }
        if ($truncated) {
            fwrite(STDERR, $prefix . "(truncated to 256 bytes)\n");
        }
    }

    /**
     * @return array{
     *     help: bool,
     *     verbose: bool,
     *     raw: bool,
     *     ping: bool,
     *     traceId: ?string,
     *     timeoutMs: int,
     *     address: string,
     *     method: string,
     *     params: string
     * }
     */
    private function options(
        bool $help,
        bool $verbose,
        bool $raw,
        bool $ping,
        ?string $traceId,
        int $timeoutMs,
        string $address,
        string $method,
        string $params,
    ): array {
        return [
            'help' => $help,
            'verbose' => $verbose,
            'raw' => $raw,
            'ping' => $ping,
            'traceId' => $traceId,
            'timeoutMs' => $timeoutMs,
            'address' => $address,
            'method' => $method,
            'params' => $params,
        ];
    }
}
