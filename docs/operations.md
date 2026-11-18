# 运行

`bin/serverd` 是生产入口。它绑定每个 worker 的 listen，按 `child_count` fork，自己不 `accept`。管理口的 HTTP 还没做。

前台跑，日志在 stdout：

```sh
php bin/serverd start --foreground -c config/server.example.php
```

不带 `--foreground` 时进程会双 fork 脱离终端。`pid_file` 为空时不写 PID 文件。`stop`、`restart`、`reload`、`status` 都要有 PID 文件：

```sh
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd start -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd status -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd reload -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd stop -c config/server.example.php
```

`status` 只报告 master 是否活着，以及 `/proc` 里能看到的 worker 数量。

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

探针和 admin 端口还没接上，不要把 liveness 指到业务端口。
