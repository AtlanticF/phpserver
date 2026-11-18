# 运行

`bin/serverd` 是生产入口。它绑定每个 worker 的 listen，按 `child_count` fork，自己不 `accept`。管理口在 master 上，默认 `127.0.0.1:9100`。

前台跑，日志在 stdout：

```sh
php bin/serverd start --foreground -c config/server.example.php
```

不带 `--foreground` 时进程会双 fork 脱离终端。`pid_file` 为空时不写 PID 文件。`stop`、`restart`、`reload` 要有 PID 文件。`status` 不看 PID 文件，它请求本机 admin 的 `/status`：

```sh
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd start -c config/server.example.php
php bin/serverd status -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd reload -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd stop -c config/server.example.php
```

`status` 打印版本、uptime、master 的 fd 数量，以及每个 worker 的 pid、状态和请求数。fd 只是一个数。

## Admin

只接受 GET，请求头要在 1 秒内读完，超过 4096 字节还没读完头就关掉。`admin.token` 非空时要带 `Authorization: Bearer <token>`。`admin.enabled=false` 时不听这个口，业务端口照常工作。`admin.metrics=false` 时没有 `/metrics`，其它路径还在。

这些路径只在 admin 口上。打到业务端口会按 Echo 或 text-rpc/1 处理，不会返回探针正文。

```sh
curl -sS http://127.0.0.1:9100/healthz
curl -sS http://127.0.0.1:9100/readyz
curl -sS http://127.0.0.1:9100/status
curl -sS http://127.0.0.1:9100/metrics
```

`/healthz` 在 master 还在应答时是 200，只表示进程还在。`/readyz` 在已经 listen 且有 worker 时是 200。`SIGTERM` 一进处理函数，`/readyz` 就变成 503，哪怕业务 listen 还没关完。`/metrics` 是 Prometheus 文本，名字以 `phpserver_` 开头，计数器以 `_total` 结尾。

限流默认不装。应用在 bootstrap 里调用 `setLimiter()`。`TokenBucket` 用标准库时钟。拒绝时客户端收到 `-32001`，`phpserver_rejected_total` 增加；不装限流器时这个计数保持 0。

不是 root 时，配置了 `user` / `group` 也会跳过 `setuid` / `setgid`，进程照常启动。只有 root 才会降权，顺序是先 `setgid` 再 `setuid`，发生在 bind 和 fork 之前。

## SIGTERM / SIGINT

对 master 发 `SIGTERM` 或 `SIGINT`（前台时 Ctrl-C 也是）时，顺序是：

1. Master 关掉自己的 listen，不再有新的描述符可以继承。
2. Master 给每个 worker 发 IPC `drain`。
3. Worker 关掉自己的 listen，已经 accept 的请求继续跑完并回复，然后退出。
4. 全部退出后，master 以状态码 0 结束。
5. 排空时间默认 **5 秒**。到点还活着的 worker 被 `SIGKILL`。这记入 `drain_timeout`，不记入 `forced_kill`。master 仍然以 0 退出。

心跳是另一条路：master 每 **0.5 秒** 发 `ping`。连续 **2 次**没有 `pong` 就 `SIGKILL`，记入 `forced_kill`，然后拉起新 worker，`worker_restarts` 加一。这和排空超时不是同一个计数。

worker 若 **2 秒**收不到 master 的任何 IPC 字节，或者 IPC 被关掉，就自己退出。所以 `kill -9` master 之后，worker 不会留下。

## reload

`SIGUSR2` 或 `serverd reload` 逐个 drain 再拉起。同一时刻只换一个 worker，其余继续 accept。替换期间打到业务端口的请求应能完成。

## 回收阀

`max_requests` 和 `max_work_time` 默认 `0`，关闭。打开后，worker 在达到次数或时长时停止 accept，等在途请求结束，再退出。退出前会等一段随机时间，长度在 `0` 和 `recycle_stagger` 之间；`recycle_stagger` 为 0 则马上排空。master 看到退出后补一个新进程。

## Kubernetes

容器里用 `start --foreground` 当 PID 1，这样 `docker stop` / `SIGTERM` 走上面的排空。

`terminationGracePeriodSeconds` 要盖住排空时间再加余量。排空默认 5 秒，建议设成 **不少于 10 秒**。短于排空时间的话，kubelet 会在 master 完成 `SIGKILL` 之前把整个容器杀掉。

liveness 用 admin 的 `/healthz`，readiness 用 `/readyz`。不要把这两个路径指到业务端口。
