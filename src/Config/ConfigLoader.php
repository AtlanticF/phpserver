<?php

declare(strict_types=1);

namespace PhpServer\Config;

use PhpServer\Exception\ConfigException;
use PhpServer\Log\LoggerInterface;
use PhpServer\Log\LogLevel;

/**
 * Loads a PHP array file, applies `PHPSERVER_*` overrides, and validates.
 *
 * Environment variables replace file values. A PHP file uses real types
 * (int, float, bool, string). Environment values are strings and are parsed:
 * integers are base-10 without leading zeros, numbers are `30` or `30.5`,
 * booleans are `true`/`false`/`1`/`0`/`yes`/`no`/`on`/`off`.
 *
 * Unknown file keys and unknown `PHPSERVER_*` variables are ignored and recorded
 * as warnings. They are also written to the logger when one was passed in.
 * Invalid values throw {@see ConfigException}. {@see self::loadOrExit()} prints
 * that message to stderr and exits with status 1.
 *
 * @internal
 */
final class ConfigLoader
{
    /** @var list<string> */
    private const array ROOT_KEYS = ['log', 'admin', 'pid_file', 'user', 'group', 'workers'];

    /** @var list<string> */
    private const array LOG_KEYS = ['level', 'json', 'timezone', 'body_truncate_bytes'];

    /** @var list<string> */
    private const array ADMIN_KEYS = ['enabled', 'listen', 'token', 'metrics'];

    /** @var list<string> */
    private const array WORKER_KEYS = [
        'listen',
        'worker_class',
        'bootstrap',
        'child_count',
        'backlog',
        'max_connections',
        'recv_timeout',
        'send_timeout',
        'idle_timeout',
        'process_timeout',
        'max_body_bytes',
        'max_frame_bytes',
        'pipeline_backlog',
        'max_requests',
        'max_work_time',
        'recycle_stagger',
    ];

    /**
     * Dot-path => type. Types are `string`, `int`, `float`, or `bool`.
     *
     * @var array<string, array{path: string, type: string}>
     */
    private const array GLOBAL_ENV = [
        'PHPSERVER_PID_FILE' => ['path' => 'pid_file', 'type' => 'string'],
        'PHPSERVER_USER' => ['path' => 'user', 'type' => 'string'],
        'PHPSERVER_GROUP' => ['path' => 'group', 'type' => 'string'],
        'PHPSERVER_LOG_LEVEL' => ['path' => 'log.level', 'type' => 'string'],
        'PHPSERVER_LOG_JSON' => ['path' => 'log.json', 'type' => 'bool'],
        'PHPSERVER_LOG_TIMEZONE' => ['path' => 'log.timezone', 'type' => 'string'],
        'PHPSERVER_LOG_BODY_TRUNCATE_BYTES' => ['path' => 'log.body_truncate_bytes', 'type' => 'int'],
        'PHPSERVER_ADMIN_ENABLED' => ['path' => 'admin.enabled', 'type' => 'bool'],
        'PHPSERVER_ADMIN_LISTEN' => ['path' => 'admin.listen', 'type' => 'string'],
        'PHPSERVER_ADMIN_TOKEN' => ['path' => 'admin.token', 'type' => 'string'],
        'PHPSERVER_ADMIN_METRICS' => ['path' => 'admin.metrics', 'type' => 'bool'],
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, string>|null $env null reads `PHPSERVER_*` from the process environment
     *
     * @throws ConfigException when the file cannot be read or validation fails
     */
    public function load(string $path, ?array $env = null): Config
    {
        $data = $this->readFile($path);
        $warnings = [];
        $data = $this->stripUnknown($data, $warnings);
        $this->applyEnvironment($data, $this->readEnvironment($env), $warnings);

        return $this->build($data, $warnings);
    }

    /**
     * Load configuration or terminate the process.
     *
     * On {@see ConfigException}, writes `phpserver: configuration error: ...`
     * to stderr and exits with status 1. Other throwables are not caught.
     *
     * @param array<string, string>|null $env
     */
    public function loadOrExit(string $path, ?array $env = null): Config
    {
        try {
            return $this->load($path, $env);
        } catch (ConfigException $e) {
            fwrite(STDERR, 'phpserver: configuration error: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new ConfigException('Configuration file is not readable: ' . $path);
        }

        try {
            /** @var mixed $data */
            $data = require $path;
        } catch (ConfigException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConfigException(
                'Configuration file failed to load: ' . $path . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($data)) {
            throw new ConfigException('Configuration file must return an array: ' . $path);
        }
        if ($data !== [] && array_is_list($data)) {
            throw new ConfigException('Configuration file must return an associative array: ' . $path);
        }

        $assoc = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new ConfigException('Configuration file keys must be strings: ' . $path);
            }
            $assoc[$key] = $value;
        }

        return $assoc;
    }

    /**
     * Values are checked here because a caller can ignore the public phpdoc.
     *
     * @param array<mixed, mixed>|null $env
     *
     * @return array<string, string>
     */
    private function readEnvironment(?array $env): array
    {
        if ($env !== null) {
            $checked = [];
            foreach ($env as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new ConfigException('Environment map must use string keys and string values');
                }
                $checked[$key] = $value;
            }

            return $checked;
        }

        $all = getenv();
        $out = [];
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'PHPSERVER_')) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $warnings
     *
     * @return array<string, mixed>
     */
    private function stripUnknown(array $data, array &$warnings): array
    {
        $data = $this->stripKeys($data, self::ROOT_KEYS, '', $warnings);

        if (isset($data['log']) && is_array($data['log'])) {
            $data['log'] = $this->stripKeys($data['log'], self::LOG_KEYS, 'log.', $warnings);
        }
        if (isset($data['admin']) && is_array($data['admin'])) {
            $data['admin'] = $this->stripKeys($data['admin'], self::ADMIN_KEYS, 'admin.', $warnings);
        }
        if (!isset($data['workers']) || !is_array($data['workers'])) {
            return $data;
        }

        $workers = [];
        foreach ($data['workers'] as $name => $options) {
            if (is_string($name) && is_array($options)) {
                $workers[$name] = $this->stripKeys($options, self::WORKER_KEYS, 'workers.' . $name . '.', $warnings);
                continue;
            }
            $workers[$name] = $options;
        }
        $data['workers'] = $workers;

        return $data;
    }

    /**
     * @param array<mixed, mixed> $section
     * @param list<string>        $allowed
     * @param list<string>        $warnings
     *
     * @return array<string, mixed>
     */
    private function stripKeys(array $section, array $allowed, string $prefix, array &$warnings): array
    {
        $kept = [];
        foreach ($section as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $label = is_string($key) ? $key : (string) $key;
                $warnings[] = 'Unknown configuration key "' . $prefix . $label . '"; ignored';
                continue;
            }
            $kept[$key] = $value;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $env
     * @param list<string>          $warnings
     */
    private function applyEnvironment(array &$data, array $env, array &$warnings): void
    {
        /** @var array<string, true> $used */
        $used = [];

        foreach (self::GLOBAL_ENV as $variable => $spec) {
            if (!array_key_exists($variable, $env)) {
                continue;
            }
            $used[$variable] = true;
            $this->setPath($data, $spec['path'], $this->castEnv($variable, $env[$variable], $spec['type']));
        }

        $names = $this->workerNames($data);
        if ($names === []) {
            $this->noteUnappliedWorkerEnv($env, $used, $warnings);
        } else {
            $this->applyWorkerEnv($data, $env, $names, $used);
        }

        foreach ($env as $variable => $value) {
            if (!str_starts_with($variable, 'PHPSERVER_') || isset($used[$variable])) {
                continue;
            }
            $warnings[] = 'Unknown environment variable "' . $variable . '"; ignored';
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function workerNames(array $data): array
    {
        if (!isset($data['workers']) || !is_array($data['workers'])) {
            return [];
        }

        $names = [];
        foreach ($data['workers'] as $name => $options) {
            if (is_string($name) && is_array($options)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, string> $env
     * @param array<string, true>   $used
     * @param list<string>          $warnings
     */
    private function noteUnappliedWorkerEnv(array $env, array &$used, array &$warnings): void
    {
        foreach (self::WORKER_KEYS as $key) {
            $variable = 'PHPSERVER_' . strtoupper($key);
            if (!array_key_exists($variable, $env) || isset($used[$variable])) {
                continue;
            }
            $used[$variable] = true;
            $warnings[] = 'Environment variable "' . $variable . '" was not applied because no workers are configured';
        }

        foreach ($env as $variable => $value) {
            if (!str_starts_with($variable, 'PHPSERVER_WORKER_') || isset($used[$variable])) {
                continue;
            }
            $used[$variable] = true;
            $warnings[] = 'Environment variable "' . $variable . '" was not applied because no workers are configured';
        }
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $env
     * @param list<string>          $names
     * @param array<string, true>   $used
     */
    private function applyWorkerEnv(array &$data, array $env, array $names, array &$used): void
    {
        if (!isset($data['workers']) || !is_array($data['workers'])) {
            return;
        }

        /** @var array<string, array<string, mixed>> $workers */
        $workers = $data['workers'];

        foreach ($names as $name) {
            foreach (self::WORKER_KEYS as $key) {
                $variable = 'PHPSERVER_' . strtoupper($key);
                if (!array_key_exists($variable, $env)) {
                    continue;
                }
                $used[$variable] = true;
                $workers[$name][$key] = $this->castEnv($variable, $env[$variable], $this->valueType($key));
            }
        }

        foreach ($names as $name) {
            $prefix = 'PHPSERVER_WORKER_' . strtoupper($name) . '_';
            foreach (self::WORKER_KEYS as $key) {
                $variable = $prefix . strtoupper($key);
                if (!array_key_exists($variable, $env)) {
                    continue;
                }
                $used[$variable] = true;
                $workers[$name][$key] = $this->castEnv($variable, $env[$variable], $this->valueType($key));
            }
        }

        $data['workers'] = $workers;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setPath(array &$data, string $path, int|float|bool|string $value): void
    {
        $parts = explode('.', $path, 2);
        if (count($parts) === 1) {
            $data[$parts[0]] = $value;

            return;
        }

        [$head, $tail] = $parts;
        if (array_key_exists($head, $data) && !is_array($data[$head])) {
            throw new ConfigException(
                'Cannot apply environment override ' . $path . ' because ' . $head . ' is not an array',
            );
        }

        /** @var array<string, mixed> $child */
        $child = [];
        if (isset($data[$head]) && is_array($data[$head])) {
            foreach ($data[$head] as $key => $item) {
                if (is_string($key)) {
                    $child[$key] = $item;
                }
            }
        }
        $child[$tail] = $value;
        $data[$head] = $child;
    }

    private function valueType(string $key): string
    {
        return match ($key) {
            'child_count', 'backlog', 'max_connections', 'max_body_bytes', 'max_frame_bytes',
            'pipeline_backlog', 'max_requests', 'body_truncate_bytes' => 'int',
            'recv_timeout', 'send_timeout', 'idle_timeout', 'process_timeout',
            'max_work_time', 'recycle_stagger' => 'float',
            'json', 'enabled', 'metrics' => 'bool',
            default => 'string',
        };
    }

    private function castEnv(string $variable, string $value, string $type): int|float|bool|string
    {
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new ConfigException('Environment variable ' . $variable . ' must be a single line');
        }

        return match ($type) {
            'int' => $this->castInt($variable, $value),
            'float' => $this->castFloat($variable, $value),
            'bool' => $this->castBool($variable, $value),
            default => $value,
        };
    }

    private function castInt(string $variable, string $value): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new ConfigException(
                'Environment variable ' . $variable . ' must be an integer, got "' . $value . '"',
            );
        }
        $number = (int) $value;
        if ((string) $number !== $value) {
            throw new ConfigException(
                'Environment variable ' . $variable . ' must be a base-10 integer in range, without leading zeros, got "' . $value . '"',
            );
        }

        return $number;
    }

    private function castFloat(string $variable, string $value): float
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new ConfigException(
                'Environment variable ' . $variable . ' must be a number, got "' . $value . '"',
            );
        }

        return (float) $value;
    }

    private function castBool(string $variable, string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigException(
                'Environment variable ' . $variable
                . ' must be a boolean (true/false/1/0/yes/no/on/off), got "' . $value . '"',
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $warnings
     */
    private function build(array $data, array $warnings): Config
    {
        /** @var list<string> $errors */
        $errors = [];

        $log = $this->buildLog($this->section($data, 'log', $errors), $errors);
        $admin = $this->buildAdmin($this->section($data, 'admin', $errors), $errors);
        $pidFile = $this->optionalString($data, 'pid_file', '', 'pid_file', $errors);
        $user = $this->optionalString($data, 'user', '', 'user', $errors);
        $group = $this->optionalString($data, 'group', '', 'group', $errors);

        if ($pidFile !== null && strlen($pidFile) > 4096) {
            $errors[] = 'pid_file must be at most 4096 bytes';
            $pidFile = null;
        }
        if ($user !== null && !$this->isAccount($user)) {
            $errors[] = 'user must be a system user name or numeric uid (got "' . $user . '")';
            $user = null;
        }
        if ($group !== null && !$this->isAccount($group)) {
            $errors[] = 'group must be a system group name or numeric gid (got "' . $group . '")';
            $group = null;
        }

        $workers = $this->buildWorkers($data, $errors);
        if ($admin !== null && $admin['enabled']) {
            $this->findListenConflicts($workers, $admin, $errors);
        }

        $this->emitWarnings($warnings);
        if ($errors !== [] || $log === null || $admin === null || $pidFile === null || $user === null || $group === null) {
            if ($errors === []) {
                $errors[] = 'configuration failed validation';
            }
            $lines = $errors;
            foreach ($warnings as $warning) {
                $lines[] = 'warning: ' . $warning;
            }
            throw new ConfigException(implode("\n", $lines));
        }

        return new Config(
            new LogConfig($log['level'], $log['json'], $log['timezone'], $log['body_truncate_bytes']),
            new AdminConfig(
                $admin['enabled'],
                $admin['listen'],
                $admin['host'],
                $admin['port'],
                $admin['token'],
                $admin['metrics'],
            ),
            $pidFile,
            $user,
            $group,
            $workers,
            $warnings,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     *
     * @return array<string, mixed>
     */
    private function section(array $data, string $key, array &$errors): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }
        if (!is_array($data[$key])) {
            $errors[] = $key . ' must be an array';

            return [];
        }

        $section = [];
        foreach ($data[$key] as $childKey => $child) {
            if (!is_string($childKey)) {
                $errors[] = $key . ' keys must be strings';
                continue;
            }
            $section[$childKey] = $child;
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string>         $errors
     *
     * @return array{level: LogLevel, json: bool, timezone: string, body_truncate_bytes: int}|null
     */
    private function buildLog(array $section, array &$errors): ?array
    {
        $level = null;
        $levelRaw = $section['level'] ?? 'info';
        $levelText = $this->requireString($levelRaw, 'log.level', $errors);
        if ($levelText !== null) {
            try {
                $level = LogLevel::parse($levelText);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $json = $this->requireBool($section['json'] ?? false, 'log.json', $errors);
        $timezone = $this->requireString($section['timezone'] ?? 'UTC', 'log.timezone', $errors);
        if ($timezone !== null) {
            try {
                new \DateTimeZone($timezone);
            } catch (\Exception) {
                $errors[] = 'log.timezone is not a valid timezone (got "' . $timezone . '")';
                $timezone = null;
            }
        }

        $truncate = $this->requireInt(
            $section['body_truncate_bytes'] ?? 256,
            'log.body_truncate_bytes',
            $errors,
        );
        if ($truncate !== null && ($truncate < 0 || $truncate > WorkerConfig::DEFAULT_MAX_BODY_BYTES)) {
            $errors[] = 'log.body_truncate_bytes must be between 0 and '
                . WorkerConfig::DEFAULT_MAX_BODY_BYTES . ' (got ' . $truncate . ')';
            $truncate = null;
        }

        if ($level === null || $json === null || $timezone === null || $truncate === null) {
            return null;
        }

        return [
            'level' => $level,
            'json' => $json,
            'timezone' => $timezone,
            'body_truncate_bytes' => $truncate,
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string>         $errors
     *
     * @return array{enabled: bool, listen: string, host: string, port: int, token: string, metrics: bool}|null
     */
    private function buildAdmin(array $section, array &$errors): ?array
    {
        $enabled = $this->requireBool($section['enabled'] ?? true, 'admin.enabled', $errors);
        $metrics = $this->requireBool($section['metrics'] ?? true, 'admin.metrics', $errors);
        $token = $this->requireString($section['token'] ?? '', 'admin.token', $errors);
        if ($token !== null && strlen($token) > 256) {
            $errors[] = 'admin.token must be at most 256 bytes';
            $token = null;
        }

        $listenRaw = $this->requireString($section['listen'] ?? '127.0.0.1:9100', 'admin.listen', $errors);
        $parsed = $listenRaw === null ? null : $this->parseListen($listenRaw, 'admin', $errors);

        if ($enabled === null || $metrics === null || $token === null || $parsed === null) {
            return null;
        }

        return [
            'enabled' => $enabled,
            'listen' => $parsed['listen'],
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'token' => $token,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     *
     * @return array<string, WorkerConfig>
     */
    private function buildWorkers(array $data, array &$errors): array
    {
        if (!array_key_exists('workers', $data) || !is_array($data['workers']) || $data['workers'] === []) {
            $errors[] = 'workers must be a non-empty map of worker name => options';

            return [];
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, WorkerConfig> $built */
        $built = [];

        foreach ($data['workers'] as $name => $options) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
                $shown = is_string($name) ? $name : get_debug_type($name);
                $errors[] = 'Worker name must match [A-Za-z][A-Za-z0-9]* (got "' . $shown . '")';
                continue;
            }
            $upper = strtoupper($name);
            if (isset($seen[$upper])) {
                $errors[] = 'Worker name "' . $name . '" collides with "' . $seen[$upper] . '" ignoring case';
                continue;
            }
            $seen[$upper] = $name;

            if (!is_array($options)) {
                $errors[] = 'Worker "' . $name . '" options must be an array';
                continue;
            }

            /** @var array<string, mixed> $options */
            $worker = $this->buildWorker($name, $options, $errors);
            if ($worker !== null) {
                $built[$name] = $worker;
            }
        }

        if ($built === [] && $errors === []) {
            $errors[] = 'workers must be a non-empty map of worker name => options';
        }

        return $built;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $errors
     */
    private function buildWorker(string $name, array $options, array &$errors): ?WorkerConfig
    {
        $label = 'Worker "' . $name . '"';
        $before = count($errors);

        $parsed = null;
        if (!array_key_exists('listen', $options)) {
            $errors[] = $label . ' is missing listen';
        } else {
            $listenRaw = $this->requireString($options['listen'], $label . ' listen', $errors);
            if ($listenRaw !== null) {
                $parsed = $this->parseListen($listenRaw, $label, $errors);
            }
        }

        $class = null;
        if (!array_key_exists('worker_class', $options)) {
            $errors[] = $label . ' is missing worker_class';
        } else {
            $class = $this->requireString($options['worker_class'], $label . ' worker_class', $errors);
            if ($class === '') {
                $errors[] = $label . ' is missing worker_class';
                $class = null;
            } elseif ($class !== null && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $class) !== 1) {
                $errors[] = $label . ' worker_class is not a class name (got "' . $class . '")';
                $class = null;
            }
        }

        $bootstrap = $this->requireString($options['bootstrap'] ?? '', $label . ' bootstrap', $errors);
        if ($bootstrap !== null && strlen($bootstrap) > 4096) {
            $errors[] = $label . ' bootstrap must be at most 4096 bytes';
            $bootstrap = null;
        }

        $childCount = $this->requireInt($options['child_count'] ?? 1, $label . ' child_count', $errors);
        if ($childCount !== null && $childCount < 1) {
            $errors[] = $label . ' child_count must be an integer >= 1 (got ' . $childCount . ')';
            $childCount = null;
        }

        $backlog = $this->requireInt($options['backlog'] ?? 511, $label . ' backlog', $errors);
        if ($backlog !== null && ($backlog < 1 || $backlog > 65535)) {
            $errors[] = $label . ' backlog must be between 1 and 65535 (got ' . $backlog . ')';
            $backlog = null;
        }

        $maxConnections = $this->requireInt($options['max_connections'] ?? 1024, $label . ' max_connections', $errors);
        if ($maxConnections !== null && ($maxConnections < 1 || $maxConnections > 1_000_000)) {
            $errors[] = $label . ' max_connections must be between 1 and 1000000 (got ' . $maxConnections . ')';
            $maxConnections = null;
        }

        $recv = $this->requireDuration($options['recv_timeout'] ?? 30, $label . ' recv_timeout', false, $errors);
        $send = $this->requireDuration($options['send_timeout'] ?? 30, $label . ' send_timeout', false, $errors);
        $idle = $this->requireDuration($options['idle_timeout'] ?? 60, $label . ' idle_timeout', false, $errors);
        $process = $this->requireDuration($options['process_timeout'] ?? 30, $label . ' process_timeout', false, $errors);
        $maxWork = $this->requireDuration($options['max_work_time'] ?? 0, $label . ' max_work_time', true, $errors);
        $stagger = $this->requireDuration($options['recycle_stagger'] ?? 0, $label . ' recycle_stagger', true, $errors);

        $maxBody = $this->requireInt(
            $options['max_body_bytes'] ?? WorkerConfig::DEFAULT_MAX_BODY_BYTES,
            $label . ' max_body_bytes',
            $errors,
        );
        if ($maxBody !== null && ($maxBody < 1 || $maxBody > WorkerConfig::MAX_BODY_BYTES)) {
            $errors[] = $label . ' max_body_bytes must be between 1 and ' . WorkerConfig::MAX_BODY_BYTES
                . ' (got ' . $maxBody . ')';
            $maxBody = null;
        }

        $maxFrame = null;
        if ($maxBody !== null) {
            $minimumFrame = $maxBody + WorkerConfig::FRAME_OVERHEAD_BYTES;
            $maximumFrame = WorkerConfig::MAX_BODY_BYTES + WorkerConfig::FRAME_OVERHEAD_BYTES;
            $maxFrame = $this->requireInt(
                $options['max_frame_bytes'] ?? $minimumFrame,
                $label . ' max_frame_bytes',
                $errors,
            );
            if ($maxFrame !== null && ($maxFrame < $minimumFrame || $maxFrame > $maximumFrame)) {
                $errors[] = $label . ' max_frame_bytes must be between ' . $minimumFrame
                    . ' and ' . $maximumFrame
                    . ' so a maximum legal frame fits (got ' . $maxFrame . ')';
                $maxFrame = null;
            }
        }

        $pipeline = $this->requireInt($options['pipeline_backlog'] ?? 32, $label . ' pipeline_backlog', $errors);
        if ($pipeline !== null && ($pipeline < 1 || $pipeline > 100_000)) {
            $errors[] = $label . ' pipeline_backlog must be between 1 and 100000 (got ' . $pipeline . ')';
            $pipeline = null;
        }

        $maxRequests = $this->requireInt($options['max_requests'] ?? 0, $label . ' max_requests', $errors);
        if ($maxRequests !== null && ($maxRequests < 0 || $maxRequests > 1_000_000_000)) {
            $errors[] = $label . ' max_requests must be between 0 and 1000000000 (got ' . $maxRequests . ')';
            $maxRequests = null;
        }

        if (
            count($errors) !== $before
            || $parsed === null
            || $class === null
            || $bootstrap === null
            || $childCount === null
            || $backlog === null
            || $maxConnections === null
            || $recv === null
            || $send === null
            || $idle === null
            || $process === null
            || $maxBody === null
            || $maxFrame === null
            || $pipeline === null
            || $maxRequests === null
            || $maxWork === null
            || $stagger === null
        ) {
            return null;
        }

        return new WorkerConfig(
            $name,
            $parsed['listen'],
            $parsed['host'],
            $parsed['port'],
            $class,
            $bootstrap,
            $childCount,
            $backlog,
            $maxConnections,
            $recv,
            $send,
            $idle,
            $process,
            $maxBody,
            $maxFrame,
            $pipeline,
            $maxRequests,
            $maxWork,
            $stagger,
        );
    }

    /**
     * @param array<string, WorkerConfig>                                                            $workers
     * @param array{enabled: bool, listen: string, host: string, port: int, token: string, metrics: bool} $admin
     * @param list<string>                                                                           $errors
     */
    private function findListenConflicts(array $workers, array $admin, array &$errors): void
    {
        /** @var list<array{label: string, host: string, port: int}> $endpoints */
        $endpoints = [];
        foreach ($workers as $worker) {
            $endpoints[] = [
                'label' => 'worker "' . $worker->name . '"',
                'host' => $worker->host,
                'port' => $worker->port,
            ];
        }
        $endpoints[] = [
            'label' => 'admin',
            'host' => $admin['host'],
            'port' => $admin['port'],
        ];

        $count = count($endpoints);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if (!$this->listenConflicts(
                    $endpoints[$i]['host'],
                    $endpoints[$i]['port'],
                    $endpoints[$j]['host'],
                    $endpoints[$j]['port'],
                )) {
                    continue;
                }
                $errors[] = 'Listen conflict on port ' . $endpoints[$i]['port']
                    . ' between ' . $endpoints[$i]['label'] . ' (' . $this->displayListen($endpoints[$i]['host'], $endpoints[$i]['port']) . ')'
                    . ' and ' . $endpoints[$j]['label'] . ' (' . $this->displayListen($endpoints[$j]['host'], $endpoints[$j]['port']) . ')';
            }
        }
    }

    private function listenConflicts(string $hostA, int $portA, string $hostB, int $portB): bool
    {
        if ($portA !== $portB) {
            return false;
        }
        if ($hostA === $hostB) {
            return true;
        }

        return $this->isWildcard($hostA) || $this->isWildcard($hostB);
    }

    private function isWildcard(string $host): bool
    {
        return $host === '*' || $host === '0.0.0.0' || $host === '::';
    }

    /**
     * @param list<string> $errors
     *
     * @return array{host: string, port: int, listen: string}|null
     */
    private function parseListen(string $raw, string $label, array &$errors): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            $errors[] = $label . ' listen must not be empty';

            return null;
        }
        if (str_contains($raw, '://')) {
            $errors[] = $label . ' listen must be host:port, not a URL (got "' . $raw . '")';

            return null;
        }

        $host = '';
        $portText = '';
        if (str_starts_with($raw, '[')) {
            if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $raw, $matches) !== 1) {
                $errors[] = $label . ' listen must be [ipv6]:port (got "' . $raw . '")';

                return null;
            }
            $host = $matches[1];
            $portText = $matches[2];
        } else {
            $colon = strrpos($raw, ':');
            if ($colon === false) {
                $errors[] = $label . ' listen must be host:port (got "' . $raw . '")';

                return null;
            }
            $host = substr($raw, 0, $colon);
            $portText = substr($raw, $colon + 1);
            if ($portText === '' || preg_match('/^\d+$/', $portText) !== 1) {
                $errors[] = $label . ' listen port must be an integer (got "' . $raw . '")';

                return null;
            }
        }

        $host = strtolower($host);
        if (!$this->isValidHost($host)) {
            $errors[] = $label . ' listen host is invalid (got "' . $host . '")';

            return null;
        }

        $port = (int) $portText;
        if ($portText !== (string) $port) {
            $errors[] = $label . ' listen port must not have leading zeros (got "' . $portText . '")';

            return null;
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = $label . ' listen port must be between 1 and 65535 (got ' . $port . ')';

            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
            'listen' => $this->displayListen($host, $port),
        ];
    }

    private function displayListen(string $host, int $port): string
    {
        if (str_contains($host, ':')) {
            return '[' . $host . ']:' . $port;
        }

        return $host . ':' . $port;
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '*') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
            return true;
        }
        if (strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        return preg_match(
            '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/',
            $host,
        ) === 1;
    }

    private function isAccount(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        return preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_-]{0,31}|\d+)$/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private function optionalString(array $data, string $key, string $default, string $label, array &$errors): ?string
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        return $this->requireString($data[$key], $label, $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function requireString(mixed $value, string $label, array &$errors): ?string
    {
        if (!is_string($value)) {
            $errors[] = $label . ' must be a string';

            return null;
        }
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            $errors[] = $label . ' must be a single line';

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     */
    private function requireInt(mixed $value, string $label, array &$errors): ?int
    {
        if (!is_int($value)) {
            $errors[] = $label . ' must be an integer';

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     */
    private function requireBool(mixed $value, string $label, array &$errors): ?bool
    {
        if (!is_bool($value)) {
            $errors[] = $label . ' must be a boolean';

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     */
    private function requireDuration(mixed $value, string $label, bool $allowZero, array &$errors): ?float
    {
        $number = null;
        if (is_int($value)) {
            $number = (float) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                $errors[] = $label . ' must be a finite number';

                return null;
            }
            $number = $value;
        } else {
            $errors[] = $label . ' must be a number';

            return null;
        }

        if ($allowZero) {
            if ($number < 0) {
                $errors[] = $label . ' must be >= 0 (got ' . $this->formatNumber($number) . ')';

                return null;
            }
        } elseif ($number <= 0) {
            $errors[] = $label . ' must be greater than 0 (got ' . $this->formatNumber($number) . ')';

            return null;
        }

        if ($number > 31_536_000) {
            $errors[] = $label . ' must be <= 31536000 seconds (got ' . $this->formatNumber($number) . ')';

            return null;
        }

        return $number;
    }

    private function formatNumber(float $number): string
    {
        if (floor($number) === $number && $number <= PHP_INT_MAX && $number >= PHP_INT_MIN) {
            return (string) (int) $number;
        }

        return (string) $number;
    }

    /**
     * @param list<string> $warnings
     */
    private function emitWarnings(array $warnings): void
    {
        if ($this->logger === null) {
            return;
        }
        foreach ($warnings as $warning) {
            $this->logger->warning($warning);
        }
    }
}
