<!-- 横幅是 capsule-render（https://github.com/kyechan99/capsule-render）生成的动态 SVG。type=waving 会让波浪持续动画。 -->

<p align="center">
  <img alt="phpserver — 轻量 prefork text RPC" src="https://capsule-render.vercel.app/api?type=waving&amp;color=0:0F172A,100:1D4ED8&amp;height=240&amp;section=header&amp;text=phpserver&amp;fontSize=56&amp;fontAlignY=38&amp;fontColor=ffffff&amp;desc=%E8%BD%BB%E9%87%8F%20prefork%20text%20RPC&amp;descAlignY=62&amp;descSize=18&amp;animation=fadeIn" />
</p>

<p align="center">
  <a href="https://github.com/AtlanticF/phpserver/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/AtlanticF/phpserver/ci.yml?branch=main&amp;label=CI" /></a>
  <a href="CHANGELOG.md"><img alt="版本 1.0.0" src="https://img.shields.io/badge/version-1.0.0-blue" /></a>
  <a href="docs/protocol-text-rpc.md"><img alt="text-rpc/1" src="https://img.shields.io/badge/text--rpc-1-0f766e" /></a>
  <img alt="PHP &gt;= 8.3" src="https://img.shields.io/badge/php-%3E%3D8.3-777BB4?logo=php&amp;logoColor=white" />
  <a href="LICENSE"><img alt="MIT 许可" src="https://img.shields.io/github/license/AtlanticF/phpserver" /></a>
</p>

<p align="center">
  <a href="README.md">English</a> · <strong>简体中文</strong>
</p>

# phpserver

面向 Linux 和 Kubernetes 的轻量 prefork text RPC 服务器。库版本 **1.0.0**。线协议 **text-rpc/1**。两个版本分开记，不绑在一起。

这是 2016 年 PHPServer 的净室重写：一个 master、预派生的 worker、`select` 循环、两个业务钩子。不携带旧协议、旧品牌，也没有运行时 Composer 包。

## 目录

- [简介](#简介)
- [功能](#功能)
- [要求](#要求)
- [快速开始](#快速开始)
- [用法](#用法)
  - [一个小服务](#一个小服务)
- [Docker](#docker)
- [Kubernetes](#kubernetes)
- [文档](#文档)
- [本地开发](#本地开发)
- [来源](#来源)
- [参与贡献](#参与贡献)
- [许可](#许可)

## 简介

`bin/serverd` 绑定 listen，按 `child_count` fork，收到 `SIGTERM` 后排空。worker 自己 accept，并跑 `SelectLoop`。业务只实现 `dealInput` 和 `dealProcess`。

探针和指标在独立的 admin 口上，默认 `127.0.0.1:9100`。不要把 `/healthz` 或 `/readyz` 指到业务端口。限流默认关闭，除非 bootstrap 调用了 `setLimiter()`。

## 功能

- 配置：PHP 数组文件、`PHPSERVER_*` 覆盖、启动前校验
- 日志：自带 `LoggerInterface`（方法名与 PSR-3 同形）、文本或 JSON 行、request-id
- 时钟：墙钟 + 单调 `hrtime`
- 协议：text-rpc/1 帧（魔数 `TRPC/1`）和 JSON body
- 客户端：阻塞的 `Client`，以及 `bin/phpserver-rpc`
- 进程：`bin/worker` 前台跑一个进程；`bin/serverd` 按 `child_count` fork。Echo 回明文 `hello`，Rpc 示例说 text-rpc/1
- Admin：master 上的 `/healthz`、`/readyz`、`/status`、`/metrics`
- 运行时镜像和 Kubernetes 示例

## 要求

- PHP >= 8.3（CI 覆盖 8.3 和 8.4）
- 扩展：`pcntl`、`posix`、`sockets`
- 运行时 Composer 包：**没有**

## 快速开始

仓库根目录下 3 条命令。最后一条的输出是 `hello`。

```sh
composer install
php bin/serverd start --foreground -c config/server.echo.php
```

另一个终端：

```sh
curl -sS http://127.0.0.1:9000/
```

Ctrl-C 停掉服务。

## 用法

### 一个小服务

一个 RPC 接口就是一个方法名加一个闭包。子类继承 `RpcWorker`，这样业务类可以是 `final`。业务方法写在普通类上，在 bootstrap 里登记。不要重写 `dealInput` 或 `dealProcess`。

`AppWorker.php`：

```php
<?php

declare(strict_types=1);

namespace App;

use PhpServer\Worker\RpcWorker;

final class AppWorker extends RpcWorker {}
```

`OrderApi.php`。返回值会编成 JSON 的 `result`。抛出的异常变成 `-32603`，message 固定是 `Internal error`，所以业务失败放进返回值里。

```php
<?php

declare(strict_types=1);

namespace App;

final class OrderApi
{
    /** @param array<array-key, mixed>|null $params */
    public function get(?array $params, string $traceId): array
    {
        $id = is_array($params) ? (string) ($params['id'] ?? '') : '';
        if ($id === '') {
            return ['ok' => false, 'error' => 'id required'];
        }

        return ['ok' => true, 'id' => $id];
    }
}
```

`bootstrap.php`，路径相对启动时的工作目录。JSON 对象进来是关联数组，JSON 数组进来是列表。空对象和空数组都会变成 `[]`。

```php
<?php

declare(strict_types=1);

use App\AppWorker;
use App\OrderApi;

$api = new OrderApi();

return static function (AppWorker $worker) use ($api): void {
    $worker->on('Order.get', $api->get(...));
};
```

`config/server.php`：

```php
<?php

declare(strict_types=1);

use App\AppWorker;

return [
    'workers' => [
        'App' => [
            'listen' => '127.0.0.1:9000',
            'worker_class' => AppWorker::class,
            'bootstrap' => 'bootstrap.php',
            'child_count' => 4,
        ],
    ],
];
```

业务项目自己自动加载 `App\`。这个包只自动加载 `PhpServer\`。

```sh
php vendor/bin/serverd start --foreground -c config/server.php
```

另一个 PHP 进程里调用。一条连接上同时只有一个调用。列表编成 JSON 数组，关联数组编成 JSON 对象。

```php
<?php

declare(strict_types=1);

use PhpServer\Client\Client;
use PhpServer\Client\RpcError;

$client = new Client();
$client->connect('127.0.0.1:9000', 2.0);

try {
    $order = $client->call('Order.get', ['id' => '7'], 'order-7')->result;
} catch (RpcError $error) {
    // $error->getCode() 是 text-rpc/1 错误码。$error->traceId 对得上服务端日志。
}

$client->close();
```

同一调用在命令行：

```sh
php vendor/bin/phpserver-rpc 127.0.0.1:9000 Order.get '{"id":"7"}' --trace-id=order-7
```

### 发一帧

规格在 [`docs/protocol-text-rpc.md`](docs/protocol-text-rpc.md)。服务已经在听时，`len` 是 body 的字节数，不含行尾：

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

或用 CLI：

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

`-v` 把收发的帧打到 stderr，body 截到 256 字节，并打印 trace-id。`--raw` 原样输出响应 body。退出码：`0` 成功，`1` RPC 错误，`2` 协议错误，`3` 网络错误，`64` 用法错误。

### 单进程

契约在 [`docs/worker-contract.md`](docs/worker-contract.md)。可复制的三条命令在 [`docs/debugging.md`](docs/debugging.md)。

Echo，body 是 `hello`：

```sh
php bin/worker --config config/server.echo.php Echo
curl -sS http://127.0.0.1:9000/
```

Rpc 示例。`Echo.say` 由 `examples/Rpc/bootstrap.php` 登记。先停掉 Echo，它也占用 9000。

```sh
php bin/worker --config config/server.example.php Rpc
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

`bin/worker` 把日志打到 stdout。`-v` 把级别降到 debug。`--config`（或 `-c`）后面跟一个 worker 名。退出码：`0` 被信号停掉，`1` 配置或运行错误，`64` 用法错误。

### Prefork

信号顺序和 `terminationGracePeriodSeconds` 写在 [`docs/operations.md`](docs/operations.md)。

```sh
php bin/serverd start --foreground -c config/server.example.php
```

`pid_file` 默认空，不写 PID 文件。`stop`、`restart`、`reload` 需要先配一个。`status` 不看 PID 文件，它请求 admin 的 `/status`。

## Docker

开发镜像跑测试、静态分析和代码风格。运行时镜像以 uid 10001 跑 `bin/serverd start --foreground`。Echo 听 `0.0.0.0:9000`，admin 听 `0.0.0.0:9100`。`docker stop` 发 `SIGTERM`，日志里有 `master draining`。

```sh
docker build --target dev -t phpserver:dev .
docker run --rm phpserver:dev
docker build --target runtime -t phpserver:runtime .
docker run --rm -p 9000:9000 -p 9100:9100 phpserver:runtime
```

另一个终端：`curl -sS http://127.0.0.1:9000/`。

基础镜像 `php:8.4-cli-alpine` 是多架构的，本 Dockerfile 没有按 CPU 写死的步骤。日常 CI 只做本机架构。要同时打 amd64 和 arm64：

```sh
docker buildx build --platform linux/amd64,linux/arm64 --target runtime -t phpserver:runtime .
```

`docker compose run --rm dev` 同样跑这三项检查，并把当前目录挂进容器（`vendor` 用镜像里的）。进 shell：`docker compose run --rm dev sh`。

## Kubernetes

示例清单，不是 Helm：[`deploy/k8s/`](deploy/k8s/README.md)。存活探针是 admin 的 `/healthz`，就绪探针是 `/readyz`，`terminationGracePeriodSeconds` 为 10，Service 只发布 9000。

## 文档

| 文档 | 内容 |
| --- | --- |
| [`docs/protocol-text-rpc.md`](docs/protocol-text-rpc.md) | text-rpc/1 线协议 |
| [`docs/configuration.md`](docs/configuration.md) | 配置键和 `PHPSERVER_*` |
| [`docs/worker-contract.md`](docs/worker-contract.md) | `dealInput` / `dealProcess` |
| [`docs/operations.md`](docs/operations.md) | 信号、排空、探针 |
| [`docs/debugging.md`](docs/debugging.md) | 可复制的短会话 |
| [`docs/event-loop.md`](docs/event-loop.md) | `SelectLoop` |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | v1 蓝图 |
| [`TODO.md`](TODO.md) | 里程碑清单 |
| [`benchmarks/`](benchmarks/README.md) | 怎么记一次压测 |
| [`CHANGELOG.md`](CHANGELOG.md) | 库栏和协议栏 |

示例配置：

- [`config/server.echo.php`](config/server.echo.php) — 最小 Echo
- [`config/server.example.php`](config/server.example.php) — 带全部键的示例

校验失败时抛出 `PhpServer\Exception\ConfigException`。`ConfigLoader::loadOrExit()` 把消息写到 stderr 并以状态码 1 退出。

## 本地开发

```sh
composer install
composer test
composer analyse
composer cs
composer validate --strict
composer show --tree
```

`composer show --no-dev --name-only` 应为空。修正代码风格用 `composer cs:fix`。

`SelectLoop` 用 `stream_select`。没有流时睡眠，不放占位 FD。见 [`docs/event-loop.md`](docs/event-loop.md)。

## 来源

本仓库是独立的净室重写，灵感来自原 PHPServer（v1.3.4，PHP 5.6 时代）的结构。那是一套在聚美优品生产环境里用过的多进程 PHP RPC 服务器。这里不包含它的源码、配置或文档。留下来的是原来的想法：一个很薄的 master 预派生 worker、基于 select 的事件循环，以及两个钩子（`dealInput` / `dealProcess`）。“PHPServer”和“聚美优品”只用来说明出处。本项目不隶属聚美优品，也未获其背书。见 [NOTICE](NOTICE)。

## 参与贡献

开 issue 或 pull request。提交前在 PHP 8.3 或更新的版本上跑 `composer test`、`composer analyse`、`composer cs`。`composer.json` 的 `require` 保持没有第三方包。改了配置键、错误码、信号顺序或 admin 路径时，同一改动里更新 `docs/` 里对应的那一行。

## 许可

[MIT](LICENSE)
