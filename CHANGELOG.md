# Changelog

库版本遵循 SemVer。text-rpc 协议版本单独记录，不跟库版本绑在一起。

## Unreleased

### 库

- M1：Composer 骨架、CI、配置加载与校验、stdout 日志（文本 / JSON 行、request-id）、时钟、异常层级。
- M2：`stream_select` 事件循环（读/写、定时器最小堆、信号）。没有流可等时用 `time_nanosleep`，不放占位 FD。
- 没有可用的服务器。`bin/serverd`、`bin/worker`、`bin/phpserver-rpc` 打印 `not implemented` 并以状态码 1 退出。

### text-rpc 协议

- 本里程碑不发布协议实现。线格式仍是草案，库代码里的 body / frame 上限与 `-32001` RateLimited 只是为了不跟草案冲突。
