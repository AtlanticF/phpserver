# Worker contract

`bin/worker` is a foreground process. It `accept`s and runs `SelectLoop` itself. `bin/serverd` uses the same worker class and adds fork and IPC. Business code implements two hooks.

## The two hooks

`PhpServer\Worker\WorkerInterface`:

```php
public function dealInput(string $buffer): Input;

public function dealProcess(string $message): Output;
```

`dealInput` looks at the bytes this connection has not consumed yet:

- `Input::needMore()`: not a full message yet.
- `Input::ready($consumed)`: the first `$consumed` bytes are one message. The worker drops those bytes, then calls `dealProcess`. A persistent connection does not leave the previous frame in the buffer.
- `Input::failed($consumed, $reply, $close)`: those bytes are illegal. Write `$reply` first. When `$close` is true, close after the write finishes.

`dealProcess` returns the bytes to write back. When `Output`'s `close` is true, the connection closes after that write.

`AbstractWorker` owns the listen socket, the connection table, the read-buffer cap, and the four timeouts. Once `max_connections` is full, extra connections are accepted and closed immediately. `conn_rejected` increments. Nothing is placed in the read buffer. The read buffer holds at most `max_frame_bytes`. If it fills before a message is complete, that is a frame-too-large: `errors` increments, `frameTooLarge()` is written, and the connection closes.

An exception from a hook does not exit the process. The worker logs an error, closes that connection, and keeps looping.

## When a connection closes

| Case | Reply | Counter |
|------|-------|---------|
| Over `max_connections` | nothing read, nothing written | `conn_rejected` |
| Message still incomplete at `recv_timeout` | none, close | not `errors` |
| Idle between messages past `idle_timeout` | none, close | `idle_timeout` |
| Reply not finished within `send_timeout` | whatever was written stays, then close | not `errors` |
| Read buffer hits `max_frame_bytes` and is still incomplete | the subclass's `frameTooLarge()`, empty by default | `errors` |
| `Output::$close` or a failure result asks to close | the reply is written first | depends on the message |
| A hook throws and the subclass does not catch it | a protocol reply is not guaranteed | `errors` |

`bin/worker` stops the loop and exits on `SIGTERM` or `SIGINT`. Under the master, those signals and an IPC `drain` stop accept first and finish requests already taken. Drain timeout and heartbeats are in [operations.md](operations.md).

`child_count`, `max_requests`, `max_work_time`, and the admin port are unused in this process.

## text-rpc/1

`PhpServer\Worker\RpcWorker` is abstract. The application writes a concrete subclass so that subclass can be `final`. Register methods with `on(method, closure)`, usually from the config's `bootstrap` closure. The closure is `(?array $params, string $traceId)`.

- `PING` answers `PONG` and does not call a handler.
- A request with no trace-id gets `r-` plus 16 hex digits, copied onto `RES` and `ERR`.
- An unknown method is `-32601`. The connection stays open.
- Any `Throwable` from a handler becomes `-32603`. The message on the frame is `Internal error`. The same trace-id is on the header and in the log's `request_id`. The exception text goes only to the log. The connection stays open and the process does not exit.
- Illegal JSON is `-32700`. A body with the wrong shape is `-32600`. Both keep the connection.
- A bad header, an unknown version, or a `len` past `max_body_bytes`: reply `ERR` and close. If there is no id, use 0.
- An unsupported codec is answered with JSON `-32004`. That body is skipped. The connection stays open.
- Frames past `pipeline_backlog` in one read do not run the handler. They are answered `-32006` in order. The connection stays open.
- If the handler returns after `process_timeout`, the result is dropped and the reply is `-32003`. The connection stays open. The worker does not signal-interrupt a running handler.

Each message `dealProcess` handles increments `requests`. A frame-level rejection is not a request.

## Echo

`PhpServer\Examples\Echo\EchoWorker` does not speak text-rpc. It waits for the end of the HTTP headers (`\r\n\r\n`), replies `HTTP/1.1 200` with body `hello`, and closes. `curl` to the business port therefore prints `hello`.

## Counters

`PhpServer\Metrics\Counters` accumulates inside the process. The names are `accept`, `requests`, `errors`, `conn_rejected`, `idle_timeout`, `rejected`, and `degraded`. Prometheus text is rendered by the master on admin `/metrics`. The worker process does not serve that path.
