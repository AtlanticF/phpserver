# phpserver/phpserver

轻量的多进程 text RPC 服务器，面向 Linux 和 Kubernetes。这是 2016 年 PHPServer 的干净重写：保留 prefork 和两个业务钩子的方向，不携带旧协议、旧品牌或运行时依赖。

**当前状态：M2。** 仓库里有骨架、基础件，以及 worker 会用的事件循环：

- 配置：PHP 数组文件、`PHPSERVER_*` 环境变量覆盖、启动前校验
- 日志：自带 `LoggerInterface`（方法签名与 PSR-3 同形）、文本或 JSON 行、request-id
- 时钟：墙钟 + 单调 `hrtime`
- 异常：`PhpServerException` 标记接口和四个具体异常
- 事件循环：`LoopInterface` / `SelectLoop`（流、定时器、信号）

还没有 text-rpc 编解码、worker、master、admin 端口。`bin/serverd`、`bin/worker`、`bin/phpserver-rpc` 只是占位，打印 `not implemented` 并以状态码 1 退出。目前不能对外提供服务。

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

## 事件循环

[`docs/event-loop.md`](docs/event-loop.md)。`SelectLoop` 用 `stream_select`；没有流时睡眠，不放占位 FD。

## 配置

说明和键表在 [`docs/configuration.md`](docs/configuration.md)。示例：

- [`config/server.echo.php`](config/server.echo.php) — 最小 Echo
- [`config/server.example.php`](config/server.example.php) — 带全部键的示例

校验失败时抛出 `PhpServer\Exception\ConfigException`。`ConfigLoader::loadOrExit()` 把消息写到 stderr 并以状态码 1 退出。

## 许可

[MIT](LICENSE)
