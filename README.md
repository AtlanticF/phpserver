# phpserver/phpserver

轻量的多进程 text RPC 服务器，面向 Linux 和 Kubernetes。这是 2016 年 PHPServer 的干净重写：保留 prefork 和两个业务钩子的方向，不携带旧协议、旧品牌或运行时依赖。

**当前状态：M1。** 仓库里现在只有骨架和所有后续模块都会用到的基础件：

- 配置：PHP 数组文件、`PHPSERVER_*` 环境变量覆盖、启动前校验
- 日志：自带 `LoggerInterface`（方法签名与 PSR-3 同形）、文本或 JSON 行、request-id
- 时钟：墙钟 + 单调 `hrtime`
- 异常：`PhpServerException` 标记接口和四个具体异常

还没有事件循环、text-rpc 编解码、worker、master、admin 端口。`bin/serverd`、`bin/worker`、`bin/phpserver-rpc` 只是占位，打印 `not implemented` 并以状态码 1 退出。

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

## 配置

说明和键表在 [`docs/configuration.md`](docs/configuration.md)。示例：

- [`config/server.echo.php`](config/server.echo.php) — 最小 Echo
- [`config/server.example.php`](config/server.example.php) — 带全部键的示例

校验失败时抛出 `PhpServer\Exception\ConfigException`。`ConfigLoader::loadOrExit()` 把消息写到 stderr 并以状态码 1 退出。

## 许可

[MIT](LICENSE)
