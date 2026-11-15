# 调试

下面三条都在仓库根目录执行，并且每次只起一个 worker。Echo 和示例 Rpc 的配置都听 `127.0.0.1:9000`，不要一起起。

## 1. 单进程 Echo

```sh
php bin/worker --config config/server.echo.php Echo
```

另一个终端：

```sh
curl -sS http://127.0.0.1:9000/
```

输出是 `hello`。worker 的日志在第一个终端的 stdout。`Ctrl-C` 或 `SIGTERM` 让进程以状态码 0 退出。

## 2. 打一帧 RPC

```sh
php bin/worker --config config/server.example.php Rpc
```

另一个终端：

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

stdout 是 `"hi"`。`-v` 把帧打到 stderr，并打印 `trace-id:`。这帧由 `examples/Rpc/bootstrap.php` 登记的 `Echo.say` 处理。

手敲同一帧：

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

## 3. 跟着 request-id 看日志

给这一帧指定 trace-id，并让 worker 把 debug 日志打到 stdout：

```sh
php bin/worker -v --config config/server.example.php Rpc
```

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' --trace-id=demo-1 -v
```

worker 的 stdout 里有 `request_id=demo-1`。CLI 的 stderr 里有 `trace-id: demo-1`。两边是同一个 id。

方法不存在或 Handler 抛异常时，错误帧和日志共用这个 id。帧上的 message 是协议里的短句（例如 `Internal error`），异常原文只出现在 worker 日志里：

```sh
bin/phpserver-rpc 127.0.0.1:9000 Missing.method '[]' --trace-id=demo-err -v
```

在 worker 的 stdout 里搜 `demo-err`。
