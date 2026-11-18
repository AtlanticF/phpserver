# Worker 契约

M4 是一个前台进程：自己 `accept`，自己跑 `SelectLoop`，没有 master、fork 和 IPC。业务只实现两个钩子。

## 两个钩子

`PhpServer\Worker\WorkerInterface`：

```php
public function dealInput(string $buffer): Input;

public function dealProcess(string $message): Output;
```

`dealInput` 看当前连接还没消费的缓冲：

- `Input::needMore()`：还不是一条完整消息。
- `Input::ready($consumed)`：缓冲开头的 `$consumed` 个字节是一条消息。worker 先把这几个字节从缓冲里拿掉，再调用 `dealProcess`。持久连接不会把上一帧留在缓冲里。
- `Input::failed($consumed, $reply, $close)`：这些字节非法。先写出 `$reply`，`$close` 为真时写完就关连接。

`dealProcess` 返回要写回的字节。`Output` 的 `close` 为真时，写完这一条就关连接。

`AbstractWorker` 负责 listen、连接表、读缓冲上限和四种超时。`max_connections` 满了之后，多出来的连接会被 accept 然后立刻关掉，`conn_rejected` 加一，不会往读缓冲里放任何字节。读缓冲最长 `max_frame_bytes`，到顶还不成一条消息就当帧过大：`errors` 加一，写完 `frameTooLarge()` 的回复后关闭。

钩子抛出的异常不会让进程退出。worker 记一条 error 日志，关掉这条连接，循环继续。

## 连接什么时候关

| 情况 | 回复 | 计数 |
|------|------|------|
| 超过 `max_connections` | 不读、不回 | `conn_rejected` |
| 消息还没收完，到了 `recv_timeout` | 不回，直接关 | 不记 `errors` |
| 两条消息之间空闲超过 `idle_timeout` | 不回，直接关 | `idle_timeout` |
| 回复在 `send_timeout` 内写不完 | 已写出的留下，然后关 | 不记 `errors` |
| 读缓冲达到 `max_frame_bytes` 仍不完整 | 子类的 `frameTooLarge()`，默认空回复 | `errors` |
| `Output::$close` 或失败结果要求关闭 | 先把回复写完 | 视这条消息而定 |
| 钩子抛异常且子类没有接住 | 不保证有协议回复 | `errors` |

`bin/worker` 收到 `SIGTERM` 或 `SIGINT` 就停循环并退出。在 master 下面，这两个信号和 IPC `drain` 会先停止 accept，等已经接上的请求写完再退出。排空超时和心跳见 [operations.md](operations.md)。

`child_count`、`max_requests`、`max_work_time`、admin 端口在这个进程里不使用。

## text-rpc/1

`PhpServer\Worker\RpcWorker` 是抽象类，应用要再写一个具体子类（这样子类可以是 `final`）。方法用 `on(method, closure)` 登记，通常写在配置的 `bootstrap` 闭包里。闭包参数是 `(?array $params, string $traceId)`。

- `PING` 回 `PONG`，不调用 Handler。
- 请求没带 trace-id 时生成 `r-` 加 16 位十六进制，并原样放进 `RES` / `ERR`。
- 方法不存在是 `-32601`，连接保持。
- Handler 抛出的任何 `Throwable` 变成 `-32603`，帧上的 message 固定是 `Internal error`，同样的 trace-id 写在头行和日志的 `request_id` 里。异常原文只进日志。连接保持，进程不退出。
- 非法 JSON 是 `-32700`，结构不对是 `-32600`，都保持连接。
- 头行非法、不认识的版本、`len` 超过 `max_body_bytes`：回复 `ERR` 后关闭。拿不到 id 时用 0。
- 不支持的 codec 用 JSON 回 `-32004`，跳过这个 body，连接保持。
- 一次读入里超过 `pipeline_backlog` 的帧不执行 Handler，按顺序回 `-32006`，连接保持。
- Handler 返回时如果已经超过 `process_timeout`，丢掉结果，回 `-32003`，连接保持。worker 不会用信号打断正在跑的 Handler。

`dealProcess` 每处理一条消息，`requests` 加一。帧级拒绝不算 request。

## Echo

`PhpServer\Examples\Echo\EchoWorker` 不说 text-rpc。它等到 HTTP 头结束（`\r\n\r\n`），回 `HTTP/1.1 200`，body 是 `hello`，然后关闭。所以 `curl` 打到业务端口看到的是明文 `hello`。

## 计数器

`PhpServer\Metrics\Counters` 只在进程内累加，名字是 `accept`、`requests`、`errors`、`conn_rejected`、`idle_timeout`。没有 Prometheus 文本。
