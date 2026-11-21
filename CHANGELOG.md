# Changelog

库版本遵循 SemVer。text-rpc 协议版本单独记录，不跟库版本绑在一起。

## Unreleased

### 库

### text-rpc 协议

## 1.0.0

### 库

- M1：Composer 骨架、CI、配置加载与校验、stdout 日志（文本 / JSON 行、request-id）、时钟、异常层级。
- M2：`stream_select` 事件循环（读/写、定时器最小堆、信号）。没有流可等时用 `time_nanosleep`，不放占位 FD。
- M3：text-rpc/1 帧编解码、阻塞客户端、`bin/phpserver-rpc`。
- M4：单进程 worker（`dealInput` / `dealProcess`）、进程内计数器、`bin/worker`、Echo 与 Rpc 示例。
- M5：prefork master、`bin/serverd`、worker 与 master 之间的定长 IPC。
- M6：master 上的 admin HTTP（`/healthz`、`/readyz`、`/status`、`/metrics`）和可选的限流钩子。限流默认关闭。
- M7：`benchmarks/`（HTTP 说明和复用官方客户端的 `bench.php`）、运行时镜像（uid 10001，拷入 `examples/`，入口是 `serverd start --foreground`）、`deploy/k8s/`。`Server::VERSION` 为 `1.0.0`。
- Docker：默认开发镜像（`php:8.4-cli-alpine`）跑 `composer test`、`analyse`、`cs`。`runtime` 目标是可运行的 Echo 服务，不是占位。

### text-rpc 协议

- `text-rpc/1` 规格见 `docs/protocol-text-rpc.md`。库实现了帧、JSON body（codec `json`，心跳 codec `-`）、阻塞客户端和 prefork 服务端。协议版本是 `text-rpc/1`，不跟库的 `1.0.0` 补丁号绑在一起。
