# Echo 明文

先起 Echo。限流默认关闭（不调用 `setLimiter`）。`admin.metrics` 默认 true，压测时可以看 admin 口的 `/metrics`。

```sh
php bin/serverd start --foreground -c config/server.echo.php
```

另一个终端。下面的工具不在 CI 里，机器上没有就在报告里写跳过。

```sh
curl -sS http://127.0.0.1:9000/
curl -sS http://127.0.0.1:9100/metrics
```

wrk：

```sh
wrk -t2 -c64 -d30s http://127.0.0.1:9000/
```

vegeta：

```sh
echo 'GET http://127.0.0.1:9000/' | vegeta attack -duration=30s | vegeta report
```

k6，脚本是同目录的 `echo.js`：

```sh
k6 run benchmarks/http/echo.js
```

body 应是 `hello`。业务端口不提供 `/metrics`。
