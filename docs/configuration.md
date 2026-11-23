# Configuration

Loading reads the file and checks it. `bin/worker` accepts on one worker's `listen`. `bin/serverd` listens for admin inside the master. The keys below are the contract.

A config file is a PHP file that returns an associative array. Environment variables are applied after the file. The environment wins on the same key.

```php
<?php

declare(strict_types=1);

return [
    'workers' => [
        'Echo' => [
            'listen' => '127.0.0.1:9000',
            'worker_class' => 'PhpServer\\Examples\\Echo\\EchoWorker',
        ],
    ],
];
```

Every worker needs at least `listen` and `worker_class`. The other keys have defaults. An unknown key does not stop startup. It records a warning (on `Config::$warnings`, and as a warning log if the `ConfigLoader` was constructed with a logger).

A failed check throws `PhpServer\Exception\ConfigException`. `ConfigLoader::loadOrExit()` writes the same message to stderr, prefixed with `phpserver: configuration error:`, and exits 1.

## Types in the file

Use real PHP types in the file: `int` for integers, `int` or `float` for timeouts, `bool` for switches, `string` for addresses and class names. Do not write a numeric string such as `'30'`.

Environment variables are all strings. They are parsed as follows:

| Type | Accepted form |
|------|----------------|
| int | decimal, optional minus sign, no leading zeros, must fit in a PHP int |
| float | `30` or `30.5` |
| bool | `true` / `false` / `1` / `0` / `yes` / `no` / `on` / `off` (case insensitive) |
| string | a single line of text |

## Root keys

| Key | Default | Environment | Meaning |
|-----|---------|-------------|---------|
| `pid_file` | `''` | `PHPSERVER_PID_FILE` | An empty string means no PID file is written |
| `user` | `''` | `PHPSERVER_USER` | Empty means do not drop privileges. Otherwise a user name or a numeric uid |
| `group` | `''` | `PHPSERVER_GROUP` | Same, a group name or a gid |
| `log` | see below | — | Logging |
| `admin` | see below | — | A separate admin port, listened by the master |
| `workers` | required, at least one | — | worker name => options. The name must match `[A-Za-z][A-Za-z0-9]*`, and two names must not differ only by case |

## Log `log`

| Key | Default | Environment |
|-----|---------|-------------|
| `level` | `info` | `PHPSERVER_LOG_LEVEL` |
| `json` | `false` | `PHPSERVER_LOG_JSON` |
| `timezone` | `UTC` | `PHPSERVER_LOG_TIMEZONE` |
| `body_truncate_bytes` | `256` | `PHPSERVER_LOG_BODY_TRUNCATE_BYTES` |

`level` is one of `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`, case insensitive.

The timezone is only for the log timestamp. The format is ISO-8601 with microseconds and a numeric offset, for example `2026-10-16T07:26:00.123456+00:00`. Intervals and timeouts use the monotonic clock, not this timezone.

`body_truncate_bytes` is how many bytes a caller keeps when it truncates a body (0 to 1 MiB). Truncation is by bytes and adds no suffix. A body that is exactly the limit is not cut. The logger does not truncate on its own, so the cut is not hidden.

A JSON line has `ts`, `level`, and `message`. It also has `request_id` when there is one. The rest of the context is under `context`. With no request-id, that field is absent.

The request-id alphabet is the text-rpc/1 trace-id alphabet: `[A-Za-z0-9._-]{1,64}`. `RequestId::generate()` produces `r-` plus 16 hex digits. An illegal id is not written to the log.

## Admin `admin`

| Key | Default | Environment |
|-----|---------|-------------|
| `enabled` | `true` | `PHPSERVER_ADMIN_ENABLED` |
| `listen` | `127.0.0.1:9100` | `PHPSERVER_ADMIN_LISTEN` |
| `token` | `''` | `PHPSERVER_ADMIN_TOKEN` |
| `metrics` | `true` | `PHPSERVER_ADMIN_METRICS` |

When `enabled` is false, the admin address is left out of the port-conflict check and the master does not listen on it. A malformed address still fails. An empty `token` means no check. Otherwise the request must send `Authorization: Bearer <token>`. When `metrics` is false there is no `/metrics`.

## Worker

Example names: `Echo`, `Rpc`. A global environment variable applies to every worker. A per-worker variable overrides it again.

| Key | Default | Every worker | Only the worker named Echo |
|-----|---------|--------------|----------------------------|
| `listen` | required | `PHPSERVER_LISTEN` | `PHPSERVER_WORKER_ECHO_LISTEN` |
| `worker_class` | required | `PHPSERVER_WORKER_CLASS` | `PHPSERVER_WORKER_ECHO_WORKER_CLASS` |
| `bootstrap` | `''` | `PHPSERVER_BOOTSTRAP` | `PHPSERVER_WORKER_ECHO_BOOTSTRAP` |
| `child_count` | `1` | `PHPSERVER_CHILD_COUNT` | `PHPSERVER_WORKER_ECHO_CHILD_COUNT` |
| `backlog` | `511` | `PHPSERVER_BACKLOG` | `PHPSERVER_WORKER_ECHO_BACKLOG` |
| `max_connections` | `1024` | `PHPSERVER_MAX_CONNECTIONS` | `PHPSERVER_WORKER_ECHO_MAX_CONNECTIONS` |
| `recv_timeout` | `30` | `PHPSERVER_RECV_TIMEOUT` | `PHPSERVER_WORKER_ECHO_RECV_TIMEOUT` |
| `send_timeout` | `30` | `PHPSERVER_SEND_TIMEOUT` | `PHPSERVER_WORKER_ECHO_SEND_TIMEOUT` |
| `idle_timeout` | `60` | `PHPSERVER_IDLE_TIMEOUT` | `PHPSERVER_WORKER_ECHO_IDLE_TIMEOUT` |
| `process_timeout` | `30` | `PHPSERVER_PROCESS_TIMEOUT` | `PHPSERVER_WORKER_ECHO_PROCESS_TIMEOUT` |
| `max_body_bytes` | `1048576` | `PHPSERVER_MAX_BODY_BYTES` | `PHPSERVER_WORKER_ECHO_MAX_BODY_BYTES` |
| `max_frame_bytes` | body + 260 | `PHPSERVER_MAX_FRAME_BYTES` | `PHPSERVER_WORKER_ECHO_MAX_FRAME_BYTES` |
| `pipeline_backlog` | `32` | `PHPSERVER_PIPELINE_BACKLOG` | `PHPSERVER_WORKER_ECHO_PIPELINE_BACKLOG` |
| `max_requests` | `0` | `PHPSERVER_MAX_REQUESTS` | `PHPSERVER_WORKER_ECHO_MAX_REQUESTS` |
| `max_work_time` | `0` | `PHPSERVER_MAX_WORK_TIME` | `PHPSERVER_WORKER_ECHO_MAX_WORK_TIME` |
| `recycle_stagger` | `0` | `PHPSERVER_RECYCLE_STAGGER` | `PHPSERVER_WORKER_ECHO_RECYCLE_STAGGER` |

Timeouts are in seconds. `recv_timeout`, `send_timeout`, `idle_timeout`, and `process_timeout` must be greater than 0 and at most 365 days. `max_work_time` and `recycle_stagger` may be 0, which turns them off. `max_requests` of 0 also turns the recycle valve off.

`child_count` must be an integer greater than or equal to 1.

Write `listen` as `host:port` or `[ipv6]:port`. Do not write `tcp://`. The port is 1–65535 with no leading zeros. `*`, `0.0.0.0`, and `::` are wildcards.

### Port conflicts

Startup fails when:

- two workers have the same host and port
- admin is enabled and lands on the same host:port as a worker
- one side of a port is a wildcard and the other side is any address (including another wildcard)

DNS is not resolved. `127.0.0.1` and `10.0.0.8` on the same port do not conflict. `127.0.0.1` and `0.0.0.0` on the same port do. That is deliberately strict, so a bind failure is not discovered after startup.

A disabled admin port is left out of this comparison.

### Frame size

Aligned with text-rpc/1:

- the header line cap is 256 bytes and is not configurable
- `max_body_bytes` defaults to 1 MiB, with a hard cap of 64 MiB (67108864)
- `max_frame_bytes` is the read-buffer cap for an unfinished frame, defaulting to `max_body_bytes + 260`
- 260 = a 256-byte header line + the CR LF after the header + the CR LF after the body
- an explicit value must fall in `[max_body_bytes + 260, 67108864 + 260]`, so the largest legal frame fits

### `worker_class`

A non-empty class-name string, for example `PhpServer\Examples\Echo\EchoWorker`. Loading the config does not check that the class exists. `bin/worker` loads it at startup. The class must already be autoloadable, and it must be a concrete subclass of `AbstractWorker`.

`bootstrap` is a PHP file path relative to the current working directory. It defaults to empty. The file may return a closure that receives the worker just constructed. Returning nothing is also fine. `examples/Rpc/bootstrap.php` uses that closure to register `Echo.say`.

## Unknown environment variables

A variable that starts with `PHPSERVER_` but is not in the tables above is logged as a warning and ignored. A variable without that prefix is unrelated to this server.

With no workers, worker-related variables do not take effect quietly. They also log a warning, and validation fails because `workers` is missing.
