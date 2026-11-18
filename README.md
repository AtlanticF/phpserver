# phpserver/phpserver

轻量的多进程 text RPC 服务器，面向 Linux 和 Kubernetes。这是 2016 年 PHPServer 的干净重写：保留 prefork 和两个业务钩子的方向，不携带旧协议、旧品牌或运行时依赖。

**当前状态：M6。** 仓库里有骨架、基础件、事件循环、text-rpc/1、阻塞客户端、前台 worker、prefork master，以及 admin 探针：

- 配置：PHP 数组文件、`PHPSERVER_*` 环境变量覆盖、启动前校验
- 日志：自带 `LoggerInterface`（方法签名与 PSR-3 同形）、文本或 JSON 行、request-id
- 时钟：墙钟 + 单调 `hrtime`
- 异常：`PhpServerException` 标记接口和四个具体异常
- 事件循环：`LoopInterface` / `SelectLoop`（流、定时器、信号）
- 协议：text-rpc/1 帧（魔数 `TRPC/1`）和 JSON body
- 客户端：阻塞的 `Client`，以及 `bin/phpserver-rpc`
- 服务：`bin/worker` 前台跑一个进程；`bin/serverd` 按 `child_count` fork。Echo 回明文 `hello`，Rpc 示例接 text-rpc/1

探针在 admin 口上，默认 `127.0.0.1:9100` 的 `/healthz` 和 `/readyz`。不要打到业务端口。限流默认关闭。

蓝图：[docs/ROADMAP.md](docs/ROADMAP.md)。
待办：[TODO.md](TODO.md)。

## Origins / 来源

This repository is an independent, clean-room rewrite inspired by the architecture of the original PHPServer (v1.3.4, from the PHP 5.6 era). That server was a multi-process PHP RPC server developed and used in production at Jumei (聚美优品). None of its source, configuration, or documentation is included here. What is kept is the original idea: a slight master that preforks workers, a select-based event loop, and a two-hook worker contract (`dealInput` / `dealProcess`). The names "PHPServer" and "Jumei" describe provenance only; this project is not affiliated with or endorsed by Jumei. See [NOTICE](NOTICE).

本仓库是独立的净室重写，不包含原 PHPServer 的代码、配置或文档。

## 要求

- PHP >= 8.3（CI 覆盖 8.3 和 8.4）
- 扩展：`pcntl`、`posix`、`sockets`
- 运行时 Composer 包：**没有**

## 本地检查

```sh
composer install
composer test
composer analyse
composer cs
composer validate --strict
composer show --tree
```

`composer show --no-dev --name-only` 应为空。修正代码风格用 `composer cs:fix`。

## 用 Docker 开始

默认镜像是开发目标，跑测试、静态分析和代码风格。`runtime` 还不能接连接：`bin/serverd` 打印 `not implemented` 并以状态码 1 退出，留到 M5 再监听。

```sh
docker build --target dev -t phpserver:dev .
docker run --rm phpserver:dev
docker compose run --rm dev sh
```

`docker compose run --rm dev` 同样跑这三项检查，并把当前目录挂进容器（`vendor` 用镜像里的）。进 shell 用上面的第三条。

## 调用 text-rpc/1

规格在 [`docs/protocol-text-rpc.md`](docs/protocol-text-rpc.md)。有服务端时可以手敲一帧（`len` 按 body 的字节数，不含行尾）：

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

或用官方 CLI：

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

`-v` 把收发的帧打到 stderr，body 截到 256 字节，并打印 trace-id。`--raw` 原样输出响应 body。退出码：`0` 成功，`1` RPC 错误，`2` 协议错误，`3` 网络错误，`64` 用法错误。协议测试夹具仍在 `tests/Fixture`，不依赖 `bin/worker`。

## 单进程 worker

契约在 [`docs/worker-contract.md`](docs/worker-contract.md)，三条能直接复制的命令在 [`docs/debugging.md`](docs/debugging.md)。

Echo，body 是 `hello`：

```sh
php bin/worker --config config/server.echo.php Echo
curl -sS http://127.0.0.1:9000/
```

Rpc 示例（`Echo.say` 由 `examples/Rpc/bootstrap.php` 登记）。先停掉上面的 Echo，它也占用 9000：

```sh
php bin/worker --config config/server.example.php Rpc
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

`bin/worker` 把日志打到 stdout。`-v` 把级别降到 debug。`--config`（或 `-c`）后面跟一个 worker 名。退出码：`0` 被信号停掉，`1` 配置或运行错误，`64` 用法错误。

一组进程用 `serverd`。信号顺序和 `terminationGracePeriodSeconds` 写在 [`docs/operations.md`](docs/operations.md)。

```sh
php bin/serverd start --foreground -c config/server.example.php
```

`pid_file` 默认空，不写 PID 文件。`stop` / `status` / `reload` 需要先配一个。

## 事件循环

[`docs/event-loop.md`](docs/event-loop.md)。`SelectLoop` 用 `stream_select`；没有流时睡眠，不放占位 FD。

## 配置

说明和键表在 [`docs/configuration.md`](docs/configuration.md)。示例：

- [`config/server.echo.php`](config/server.echo.php) — 最小 Echo
- [`config/server.example.php`](config/server.example.php) — 带全部键的示例

校验失败时抛出 `PhpServer\Exception\ConfigException`。`ConfigLoader::loadOrExit()` 把消息写到 stderr 并以状态码 1 退出。

## 许可

[MIT](LICENSE)
