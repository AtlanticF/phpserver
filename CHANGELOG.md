# Changelog

库版本遵循 SemVer。text-rpc 协议版本单独记录，不跟库版本绑在一起。

## Unreleased

### 库

- M1：Composer 骨架、CI、配置加载与校验、stdout 日志（文本 / JSON 行、request-id）、时钟、异常层级。
- M2：`stream_select` 事件循环（读/写、定时器最小堆、信号）。没有流可等时用 `time_nanosleep`，不放占位 FD。
- M3：text-rpc/1 帧编解码、阻塞客户端、`bin/phpserver-rpc`。没有服务端。`bin/serverd` 和 `bin/worker` 仍打印 `not implemented` 并以状态码 1 退出。
- Docker：默认开发镜像（`php:8.4-cli-alpine`）跑 `composer test`、`analyse`、`cs`。`runtime` 目标是占位，非 root（uid 10001），入口仍是 `bin/serverd`。

### text-rpc 协议

- text-rpc/1 规格见 `docs/protocol-text-rpc.md`。库实现了帧与 JSON body（codec `json`，心跳 codec `-`）。服务端还没有，协议版本不跟库版本绑在一起。
