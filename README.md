<!-- Banner is a dynamic SVG from capsule-render (https://github.com/kyechan99/capsule-render). type=waving animates the waves. -->

<p align="center">
  <img alt="phpserver — prefork text RPC for PHP" src="https://capsule-render.vercel.app/api?type=waving&amp;color=0:0F172A,100:1D4ED8&amp;height=240&amp;section=header&amp;text=phpserver&amp;fontSize=56&amp;fontAlignY=38&amp;fontColor=ffffff&amp;desc=prefork%20text%20RPC%20for%20PHP&amp;descAlignY=62&amp;descSize=18&amp;animation=fadeIn" />
</p>

<p align="center">
  <a href="https://github.com/AtlanticF/phpserver/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/AtlanticF/phpserver/ci.yml?branch=main&amp;label=CI" /></a>
  <a href="CHANGELOG.md"><img alt="version 1.0.0" src="https://img.shields.io/badge/version-1.0.0-blue" /></a>
  <a href="docs/protocol-text-rpc.md"><img alt="text-rpc/1" src="https://img.shields.io/badge/text--rpc-1-0f766e" /></a>
  <img alt="PHP &gt;= 8.3" src="https://img.shields.io/badge/php-%3E%3D8.3-777BB4?logo=php&amp;logoColor=white" />
  <a href="LICENSE"><img alt="MIT license" src="https://img.shields.io/github/license/AtlanticF/phpserver" /></a>
</p>

<p align="center">
  <strong>English</strong> · <a href="README.zh-CN.md">简体中文</a>
</p>

# phpserver

Slight prefork text RPC server for Linux and Kubernetes. Library **1.0.0**. Wire protocol **text-rpc/1**. The two versions are recorded separately and are not tied together.

This is a clean-room rewrite of the 2016 PHPServer idea: one master, preforked workers, a `select` loop, and two business hooks. It does not carry the old protocol, the old brand, or any runtime Composer package.

## Contents

- [About](#about)
- [Features](#features)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Usage](#usage)
- [Docker](#docker)
- [Kubernetes](#kubernetes)
- [Documentation](#documentation)
- [Development](#development)
- [Provenance](#provenance)
- [Contributing](#contributing)
- [License](#license)

## About

`bin/serverd` binds the listen socket, forks `child_count` workers, and drains on `SIGTERM`. Workers accept connections and run `SelectLoop`. Business code implements `dealInput` and `dealProcess`.

Admin probes and metrics listen on their own port, `127.0.0.1:9100` by default. Do not point `/healthz` or `/readyz` at the business port. Rate limiting is off unless a bootstrap calls `setLimiter()`.

Deeper docs in this repository are written in Chinese. The links below still point at the current behavior.

## Features

- Config: a PHP array file, `PHPSERVER_*` overrides, checked before the process serves
- Log: a `LoggerInterface` with the same method names as PSR-3, text or JSON lines, request-id
- Clock: wall time plus monotonic `hrtime`
- Protocol: text-rpc/1 frames (magic `TRPC/1`) and a JSON body
- Client: a blocking `Client`, and `bin/phpserver-rpc`
- Process: `bin/worker` runs one foreground process; `bin/serverd` forks. Echo answers plaintext `hello`. The Rpc example speaks text-rpc/1
- Admin: `/healthz`, `/readyz`, `/status`, `/metrics` on the master
- Runtime image and a Kubernetes example

## Requirements

- PHP >= 8.3 (CI runs 8.3 and 8.4)
- Extensions: `pcntl`, `posix`, `sockets`
- Runtime Composer packages: **none**

## Quick start

Three commands, from the repository root. The third prints `hello`.

```sh
composer install
php bin/serverd start --foreground -c config/server.echo.php
```

In another terminal:

```sh
curl -sS http://127.0.0.1:9000/
```

Stop the server with Ctrl-C.

## Usage

### Send a frame

The spec is [`docs/protocol-text-rpc.md`](docs/protocol-text-rpc.md). With a server already listening, `len` is the body size in bytes and does not include the line ending:

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

Or the CLI:

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

`-v` prints frames on stderr, truncates the body to 256 bytes, and prints the trace-id. `--raw` prints the response body unchanged. Exit codes: `0` success, `1` RPC error, `2` protocol error, `3` network error, `64` usage error.

### Single process

The contract is [`docs/worker-contract.md`](docs/worker-contract.md). Copy-paste sessions are in [`docs/debugging.md`](docs/debugging.md).

Echo, body `hello`:

```sh
php bin/worker --config config/server.echo.php Echo
curl -sS http://127.0.0.1:9000/
```

Rpc example. `Echo.say` is registered by `examples/Rpc/bootstrap.php`. Stop the Echo process first; it also binds port 9000.

```sh
php bin/worker --config config/server.example.php Rpc
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

`bin/worker` logs to stdout. `-v` lowers the level to debug. `--config` (or `-c`) is followed by a worker name. Exit codes: `0` stopped by a signal, `1` config or runtime error, `64` usage error.

### Prefork

Signal order and `terminationGracePeriodSeconds` are in [`docs/operations.md`](docs/operations.md).

```sh
php bin/serverd start --foreground -c config/server.example.php
```

`pid_file` defaults to empty, so no PID file is written. `stop`, `restart`, and `reload` need one. `status` does not: it GETs the admin `/status` endpoint.

## Docker

The dev image runs tests, static analysis, and the coding-style check. The runtime image runs `bin/serverd start --foreground` as uid 10001. Echo listens on `0.0.0.0:9000`, admin on `0.0.0.0:9100`. `docker stop` sends `SIGTERM`; the log contains `master draining`.

```sh
docker build --target dev -t phpserver:dev .
docker run --rm phpserver:dev
docker build --target runtime -t phpserver:runtime .
docker run --rm -p 9000:9000 -p 9100:9100 phpserver:runtime
```

In another terminal: `curl -sS http://127.0.0.1:9000/`.

The base image `php:8.4-cli-alpine` is multi-arch, and this Dockerfile has no architecture-specific steps. Daily CI builds the native architecture only. Both platforms:

```sh
docker buildx build --platform linux/amd64,linux/arm64 --target runtime -t phpserver:runtime .
```

`docker compose run --rm dev` runs the same three checks and mounts the working tree (`vendor` stays the image copy). A shell: `docker compose run --rm dev sh`.

## Kubernetes

Example manifests, not a Helm chart: [`deploy/k8s/`](deploy/k8s/README.md). Liveness is admin `/healthz`, readiness is `/readyz`, `terminationGracePeriodSeconds` is 10, and the Service publishes port 9000 only. The notes in that directory are in Chinese.

## Documentation

| Document | What it covers |
| --- | --- |
| [`docs/protocol-text-rpc.md`](docs/protocol-text-rpc.md) | Wire spec for text-rpc/1 |
| [`docs/configuration.md`](docs/configuration.md) | Config keys and `PHPSERVER_*` |
| [`docs/worker-contract.md`](docs/worker-contract.md) | `dealInput` / `dealProcess` |
| [`docs/operations.md`](docs/operations.md) | Signals, drain, probes |
| [`docs/debugging.md`](docs/debugging.md) | Short copy-paste sessions |
| [`docs/event-loop.md`](docs/event-loop.md) | `SelectLoop` |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | v1 blueprint |
| [`TODO.md`](TODO.md) | Milestone checklist |
| [`benchmarks/`](benchmarks/README.md) | How to record a benchmark |
| [`CHANGELOG.md`](CHANGELOG.md) | Library column and protocol column |

Example configs:

- [`config/server.echo.php`](config/server.echo.php) — smallest Echo
- [`config/server.example.php`](config/server.example.php) — every key

A bad config throws `PhpServer\Exception\ConfigException`. `ConfigLoader::loadOrExit()` writes the message to stderr and exits 1.

## Development

```sh
composer install
composer test
composer analyse
composer cs
composer validate --strict
composer show --tree
```

`composer show --no-dev --name-only` stays empty. Fix style with `composer cs:fix`.

`SelectLoop` uses `stream_select`. When nothing is registered it sleeps, and it does not park a placeholder file descriptor. See [`docs/event-loop.md`](docs/event-loop.md).

## Provenance

This repository is an independent, clean-room rewrite inspired by the architecture of the original PHPServer (v1.3.4, from the PHP 5.6 era). That server was a multi-process PHP RPC server developed and used in production at Jumei (聚美优品). None of its source, configuration, or documentation is included here. What is kept is the original idea: a slight master that preforks workers, a select-based event loop, and a two-hook worker contract (`dealInput` / `dealProcess`). The names "PHPServer" and "Jumei" describe provenance only; this project is not affiliated with or endorsed by Jumei. See [NOTICE](NOTICE).

## Contributing

Open an issue or a pull request. Before sending code, run `composer test`, `composer analyse`, and `composer cs` on PHP 8.3 or newer. Keep `composer.json` `require` free of packages. Behavior that changes a config key, an error code, the signal order, or an admin path needs the matching line in `docs/` updated in the same change.

## License

[MIT](LICENSE)
