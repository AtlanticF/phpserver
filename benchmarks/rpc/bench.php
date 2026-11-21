#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpServer\Client\Client;
use PhpServer\Client\NetworkException;
use PhpServer\Client\RpcError;
use PhpServer\Exception\ProtocolException;

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "bench: run composer install first\n");
    exit(1);
}

require $autoload;

/** @var list<string> $argv */
$args = array_slice($argv, 1);
$count = 200;
$timeoutMs = 2000;
$positionals = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--count=')) {
        $count = positiveInt(substr($arg, strlen('--count=')), '--count');
        continue;
    }
    if (str_starts_with($arg, '--timeout=')) {
        $timeoutMs = positiveInt(substr($arg, strlen('--timeout=')), '--timeout');
        continue;
    }
    if (str_starts_with($arg, '-')) {
        usage();
    }
    $positionals[] = $arg;
}

if (count($positionals) !== 3) {
    usage();
}

[$address, $method, $json] = $positionals;
try {
    $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'bench: json-params: ' . $exception->getMessage() . "\n");
    exit(64);
}
if (!is_array($decoded) && !$decoded instanceof stdClass) {
    fwrite(STDERR, "bench: json-params must be a JSON array or object\n");
    exit(64);
}

$client = new Client();
try {
    $client->connect($address, 2.0);
} catch (NetworkException $exception) {
    fwrite(STDERR, 'bench: ' . $exception->getMessage() . "\n");
    exit(1);
}

$samples = [];
$errors = 0;
$wallStart = hrtime(true);
for ($i = 0; $i < $count; $i++) {
    $started = hrtime(true);
    try {
        $client->call($method, $decoded, null, $timeoutMs);
    } catch (RpcError) {
        $errors++;
    } catch (NetworkException|ProtocolException) {
        $errors++;
        $client->close();
        try {
            $client->connect($address, 2.0);
        } catch (NetworkException $again) {
            $samples[] = microseconds($started);
            $errors += $count - $i - 1;
            fwrite(STDERR, 'bench: ' . $again->getMessage() . "\n");
            break;
        }
    }
    $samples[] = microseconds($started);
}
$client->close();

$elapsedNs = hrtime(true) - $wallStart;
$seconds = $elapsedNs / 1_000_000_000;
$qps = $seconds > 0.0 ? count($samples) / $seconds : (float) count($samples);
sort($samples);

echo 'php=' . PHP_VERSION . "\n";
echo 'address=' . $address . "\n";
echo 'method=' . $method . "\n";
echo 'payload=' . $json . "\n";
echo 'count=' . count($samples) . "\n";
echo 'errors=' . $errors . "\n";
echo 'qps=' . number_format($qps, 1, '.', '') . "\n";
echo 'p50_us=' . percentile($samples, 0.50) . "\n";
echo 'p99_us=' . percentile($samples, 0.99) . "\n";

exit($errors === 0 ? 0 : 1);

function usage(): never
{
    fwrite(STDERR, <<<'TXT'
        Usage: php benchmarks/rpc/bench.php host:port Method json-params [--count=N] [--timeout=ms]

        Prints count, errors, qps, p50_us, and p99_us. There is no QPS gate.

        TXT);
    exit(64);
}

function positiveInt(string $raw, string $name): int
{
    if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
        fwrite(STDERR, 'bench: ' . $name . " must be a positive integer\n");
        exit(64);
    }
    $value = (int) $raw;
    if ($value < 1) {
        fwrite(STDERR, 'bench: ' . $name . " must be a positive integer\n");
        exit(64);
    }

    return $value;
}

function microseconds(int $startedNs): int
{
    $elapsed = hrtime(true) - $startedNs;
    if ($elapsed < 0) {
        return 0;
    }

    return (int) intdiv($elapsed, 1000);
}

/**
 * @param list<int> $sorted
 */
function percentile(array $sorted, float $quantile): int
{
    $n = count($sorted);
    if ($n === 0) {
        return 0;
    }
    $index = (int) ceil($quantile * $n) - 1;
    if ($index < 0) {
        $index = 0;
    }
    if ($index >= $n) {
        $index = $n - 1;
    }

    return $sorted[$index];
}
