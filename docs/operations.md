# Operations

`bin/serverd` is the production entry. It binds each worker's listen socket, forks `child_count` workers, and does not `accept` itself. The admin port is on the master, `127.0.0.1:9100` by default.

Run in the foreground. Logs go to stdout:

```sh
php bin/serverd start --foreground -c config/server.example.php
```

Without `--foreground` the process double-forks off the terminal. An empty `pid_file` means no PID file is written. `stop`, `restart`, and `reload` need a PID file. `status` does not. It requests the local admin `/status`:

```sh
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd start -c config/server.example.php
php bin/serverd status -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd reload -c config/server.example.php
PHPSERVER_PID_FILE=/tmp/phpserver.pid php bin/serverd stop -c config/server.example.php
```

`status` prints the version, uptime, the master's fd count, and each worker's pid, state, and request count. The fd count is one number.

## Admin

GET only. The header must arrive within 1 second. If the header is still unfinished after 4096 bytes, the connection is closed. When `admin.token` is non-empty, send `Authorization: Bearer <token>`. `admin.enabled=false` does not listen on that port. The business port keeps working. `admin.metrics=false` removes `/metrics`. The other paths stay.

These paths exist only on the admin port. A request to the business port is handled as Echo or text-rpc/1. It does not return probe text.

```sh
curl -sS http://127.0.0.1:9100/healthz
curl -sS http://127.0.0.1:9100/readyz
curl -sS http://127.0.0.1:9100/status
curl -sS http://127.0.0.1:9100/metrics
```

`/healthz` is 200 while the master is still answering. It only means the process is up. `/readyz` is 200 once the process is listening and a worker is alive. As soon as the `SIGTERM` handler runs, `/readyz` becomes 503, even if the business listen socket is not closed yet. `/metrics` is Prometheus text. Names start with `phpserver_` and counters end with `_total`.

Rate limiting is not installed by default. The application calls `setLimiter()` from bootstrap. `TokenBucket` uses the standard-library clock. A rejection returns `-32001` to the client and increments `phpserver_rejected_total`. With no limiter installed, that counter stays 0.

When the process is not root, a configured `user` or `group` is skipped and the process still starts. Only root drops privileges, `setgid` then `setuid`, before bind and fork.

## SIGTERM / SIGINT

On `SIGTERM` or `SIGINT` to the master (Ctrl-C in the foreground is the same signal), the order is:

1. The master closes its own listen sockets, so no new descriptor can be inherited.
2. The master sends an IPC `drain` to each worker.
3. The worker closes its own listen socket, finishes requests it has already accepted, replies, and exits.
4. After every worker has exited, the master exits 0.
5. The drain timeout defaults to **5 seconds**. A worker still alive at the deadline is `SIGKILL`ed. That increments `drain_timeout`, not `forced_kill`. The master still exits 0.

Heartbeats are a separate path. The master sends `ping` every **0.5 seconds**. Two misses in a row mean `SIGKILL`, which increments `forced_kill`, and then a replacement worker, which increments `worker_restarts`. That is not the drain-timeout counter.

A worker that receives no IPC byte from the master for **2 seconds**, or whose IPC is closed, exits on its own. After `kill -9` on the master, workers do not stay behind.

## reload

`SIGUSR2` or `serverd reload` drains and restarts workers one at a time. Only one worker is replaced at once. The others keep accepting. A request to the business port during the swap should still complete.

## Recycle

`max_requests` and `max_work_time` default to `0`, which turns them off. When set, a worker stops accepting once it hits the count or the duration, finishes in-flight requests, and exits. Before exiting it waits a random time between `0` and `recycle_stagger`. A `recycle_stagger` of 0 drains immediately. The master starts a replacement when it sees the exit.

## Kubernetes

In a container, `start --foreground` is PID 1, so `docker stop` and `SIGTERM` take the drain path above.

`terminationGracePeriodSeconds` must cover the drain plus a margin. The drain defaults to 5 seconds, so set grace to **at least 10 seconds**. Shorter than the drain, kubelet kills the container before the master finishes its own `SIGKILL`.

Liveness is admin `/healthz`. Readiness is `/readyz`. Do not point either path at the business port.
