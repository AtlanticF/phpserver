# phpserver/phpserver

轻量的多进程 text RPC 服务器，面向 Linux 和 Kubernetes。这是 2016 年 PHPServer 的干净重写：保留 prefork 和两个业务钩子的方向，不携带旧协议、旧品牌或运行时依赖。

**当前状态：M3。** 仓库里有骨架、基础件、事件循环，以及 text-rpc/1 的帧编解码和阻塞客户端：

- 配置：PHP 数组文件、`PHPSERVER_*` 环境变量覆盖、启动前校验
- 日志：自带 `LoggerInterface`（方法签名与 PSR-3 同形）、文本或 JSON 行、request-id
- 时钟：墙钟 + 单调 `hrtime`
- 异常：`PhpServerException` 标记接口和四个具体异常
- 事件循环：`LoopInterface` / `SelectLoop`（流、定时器、信号）
- 协议：text-rpc/1 帧（魔数 `TRPC/1`）和 JSON body
- 客户端：阻塞的 `Client`，以及 `bin/phpserver-rpc`

还没有 worker、master、admin 端口。`bin/serverd` 和 `bin/worker` 仍是占位，打印 `not implemented` 并以状态码 1 退出。目前不能对外提供服务。

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

`-v` 把收发的帧打到 stderr，body 截到 256 字节，并打印 trace-id。`--raw` 原样输出响应 body。退出码：`0` 成功，`1` RPC 错误，`2` 协议错误，`3` 网络错误，`64` 用法错误。这一里程碑没有产品服务端；测试用 `tests/Fixture` 里的回显套接字，不启动 `bin/worker`。

## 事件循环

[`docs/event-loop.md`](docs/event-loop.md)。`SelectLoop` 用 `stream_select`；没有流时睡眠，不放占位 FD。

## 配置

说明和键表在 [`docs/configuration.md`](docs/configuration.md)。示例：

- [`config/server.echo.php`](config/server.echo.php) — 最小 Echo
- [`config/server.example.php`](config/server.example.php) — 带全部键的示例

校验失败时抛出 `PhpServer\Exception\ConfigException`。`ConfigLoader::loadOrExit()` 把消息写到 stderr 并以状态码 1 退出。

## 许可

[MIT](LICENSE)
