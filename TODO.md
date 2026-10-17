# TODO

对照 [docs/ROADMAP.md](docs/ROADMAP.md)。M1、M2 的勾选以仓库里的代码和测试为准。M3 起还没开始。

每个里程碑结束时，PHP 8.3 和 8.4 上 `composer test`、`composer analyse`、`composer cs` 都要过，`require` 里仍然没有第三方包。

## M1 骨架与基础件

- [x] `composer.json`：包名 `phpserver/phpserver`、MIT、`php >=8.3`、扩展 `pcntl` / `posix` / `sockets`，`require` 无包，`bin` 登记三个脚本。验收：`composer validate --strict` 通过，`composer show --no-dev --name-only` 为空。
- [x] PHPUnit 11 的 Unit / Integration 两套件、PHPStan level max、php-cs-fixer `@PER-CS`。验收：`phpunit.xml.dist`、`phpstan.neon`、`.php-cs-fixer.dist.php` 与 CI 一致。
- [x] `.github/workflows/ci.yml` 在 PHP 8.3 和 8.4 上跑 validate、无运行时包、`cs`、`analyse`、`test`。验收：工作流包含这两档，且 `fail-fast` 为 false。
- [x] `CHANGELOG.md` 把库和 text-rpc 协议分成两栏；`LICENSE` 为 MIT。验收：Unreleased 的协议栏写明本阶段不发布实现。
- [x] `bin/serverd`、`bin/worker`、`bin/phpserver-rpc` 只是占位。验收：`tests/Integration/Bin/PlaceholderBinTest.php` 断言打印 `not implemented` 并以状态码 1 退出。
- [x] `src/Exception/`：`PhpServerException` 标记接口，以及 `ConfigException`、`ProtocolException`、`RateLimitedException`、`ProcessException`。验收：`tests/Unit/Exception/ExceptionHierarchyTest.php`；`RateLimitedException` 默认码 `-32001`。
- [x] `src/Support/ClockInterface.php`、`src/Support/SystemClock.php`，测试时钟在 `tests/Support/MutableClock.php`。验收：`tests/Unit/Support/SystemClockTest.php` 里改墙钟不改变 `monotonic()`。
- [x] `src/Config/`：PHP 数组文件、`PHPSERVER_*` 覆盖、启动前校验。验收：`tests/Unit/Config/ConfigLoaderTest.php` 中环境变量压过文件；未知键只 warning。
- [x] 配置失败路径：端口冲突、admin 与业务同端口、`child_count < 1`、非法超时、缺 `worker_class`。验收：同一测试的 `invalidConfigs` 抛 `ConfigException`。
- [x] `ConfigLoader::loadOrExit()`。验收：`tests/Integration/Config/ConfigLoadOrExitTest.php` 非法配置状态码 1，合法配置不退出。
- [x] `config/server.example.php`、`config/server.echo.php`、`docs/configuration.md`。验收：`testExampleConfigsLoadWithDefaults`；Echo 的类名只做字符串校验，类留到 M4。
- [x] `src/Log/`：`LoggerInterface`（方法与 PSR-3 同形）、`LogLevel`、`StdoutLogger` 文本 / JSON 行、`RequestId`、`BodyTruncator`。验收：`tests/Unit/Log/StdoutLoggerTest.php` 的 JSON 行含 `ts`（ISO-8601）、`level`，有 id 时含 `request_id`，没有 id 时不出现该字段。
- [x] body 按字节截断，调用方自己截，logger 不暗中截。验收：`tests/Unit/Log/BodyTruncatorTest.php` 恰好等于阈值时不截，超过则切在阈值上。
- [x] `suggest` 里写 `psr/log`，适配器只在 `examples/psr3-logger-adapter.php`。验收：`src/` 不引用 `Psr\Log`。

## M2 事件循环

接口名是 `LoopInterface`，不是别的名字。回调类型是 `\Closure`。

- [x] `src/Event/LoopInterface.php`：读/写注册与移除、一次性定时器、周期定时器、`cancelTimer`、`onSignal` / `offSignal`、`run` / `stop`。验收：流回调签名是 `\Closure(resource): void`，定时器和信号回调是 `\Closure(int): void`；重复 `off*` / `cancelTimer` 不抛、不关流。
- [x] `src/Event/SelectLoop.php` 一拍顺序为信号 → 定时器 →（若已 `stop` 则返回）→ select。验收：类注释与 `docs/event-loop.md` 一致；`tests/Unit/Event/SelectLoopTest.php` 的 `testEmptyLoopReturns` 构造后 `run` 一轮，无 `TypeError`。
- [x] 没有流时 `time_nanosleep`，不放占位 FD，不对空集调用 `stream_select`。验收：`testTimerOnlyRunReturnsWithoutSelecting`、`testTimerWaitSleepsInsteadOfSelectingEmptySets` 的日志模式含 `sleep`、不含 `select`。
- [x] `src/Event/Timer.php` 用整数纳秒：`dueNs` 为绝对单调时刻，`intervalNs` 为 0 表示一次性。验收：`addTimer` / `addPeriodicTimer` 经 `ClockInterface::monotonic()` 排期；负数或非有限延迟抛 `InvalidArgumentException`（`testRejectsBadDelaysAndStreams`）。
- [x] `src/Event/TimerQueue.php` 惰性删除：`cancel` 只动 id 表，堆顶对不上表时丢掉。验收：`tests/Unit/Event/TimerQueueTest.php` 的 `testCancelDropsATimerBeforeItIsDue`、`testStaleHeapEntriesAreSkipped`；周期定时器按计划时间补跑最多一次（`testPeriodicTimerCatchesUpAtMostOnce`）。
- [x] 改墙钟不影响间隔。验收：定时器只读 `monotonic()`；`SystemClockTest::testWallMoveDoesNotChangeMonotonicTime` 把墙钟和单调时钟拆开。
- [x] 先 `removeStream()` 再 `fclose()`。同拍里更早的回调若已移除某个流，后面的回调跳过。验收：`testClosingAnotherReadyStreamSkipsItsCallback`、`testRemoveStreamClearsBothDirections`。
- [x] 信号：`pcntl_async_signals(true)`，处理函数只入队，回调在循环线程执行。验收：`tests/Integration/Event/SignalTest.php` 在子进程注册 `SIGTERM`，回调里 `stop()`，父进程发信号后子进程以状态码 0 退出。
- [x] socketpair 上先读回调、再写回调。验收：`testReadAndWriteCallbacksFireThenTheLoopExits`。
- [x] `EINTR` 不当成失败：`SelectLoop::isInterrupted` 遇到 interrupted system call 或 `SOCKET_EINTR` 就进入下一拍。验收：实现已在 `select()` 里；还没有测试逼出 `stream_select` 返回失败。见文末。
- [x] 流回调里 `stop()` 的当前行为：本拍 I/O 跑完，下一拍再跑一次定时器然后返回。验收：`testZeroDelayTimerAddedFromACallbackRunsOnTheNextTick` 得到 `first`、`read`、`second`。要不要保留，见文末，不要当成已拍板的产品语义。

## M3 协议、客户端、调用 CLI

先写规格，再写编解码。本里程碑没有服务端 worker，也没有连接池或异步客户端。

- [ ] `docs/protocol-text-rpc.md`。验收：写清帧、UTF-8、长度按字节、前导零和负数、`max_body_bytes` / 头行 256 字节、`ErrorCode`、trace-id 的位置，并给一组能用 `nc` 手敲的样例；魔数为 `TRPC/1`。
- [ ] `src/Protocol/ProtocolInterface.php`。验收：对一块缓冲给出「还要多少字节 / 已经完整 / 非法」；非法路径抛 `ProtocolException`，不返回含糊的假。
- [ ] `src/Protocol/TextRpc/ErrorCode.php`。验收：含 `-32700`、`-32600`～`-32603`，以及 `-32001` RateLimited、`-32002` FrameTooLarge、`-32003` Timeout、`-32004` UnsupportedVersionOrCodec、`-32005` ShuttingDown、`-32006` Overloaded。
- [ ] `src/Protocol/TextRpc/` 中的版本常量（魔数 `TRPC/1`）。验收：不认识的魔数映射到 `-32004`，而不是按 v1 硬解析。
- [ ] `src/Protocol/TextRpc/FrameDecoder.php` 与 `tests/Unit/Protocol/TextRpc/FrameDecoderTest.php`。验收：逐字节半包、两帧一次喂入、超长 length、负数或前导零、非法 UTF-8、未知版本，分别得到「还要字节」「完整帧」或 `ProtocolException` 加 `ErrorCode`。
- [ ] `src/Protocol/TextRpc/FrameEncoder.php` 与往返测试。验收：空 body 和含非 UTF-8 字节的 body 编码后再解码，得到同一帧；发送端用 LF。
- [ ] 用规格文档里同一串字节做解码测试。验收：文档样例和单测里的字节序列一致，解码器接受它。
- [ ] `src/Client/` 阻塞客户端：连接、超时、发送、读完一帧。验收：只依赖 `Protocol`，不引用 `Worker` 或 `Master`。
- [ ] `bin/phpserver-rpc`：`host:port`、方法、payload、`-v` 时按截断长度打印帧、显示 request-id 和错误码。验收：`--help` 文本稳定；退出码区分成功、协议错误、网络错误。
- [ ] 测试夹具里的最小回显 socket（放在 `tests/`，不是产品代码）。验收：CLI 对这个夹具往返成功，测试不启动 `bin/worker`。

## M4 首切：单进程 worker

没有 master、fork、IPC、admin 端口、指标渲染、限流和 `max_requests`。那些属于 M5 / M6。

- [ ] `src/Worker/WorkerInterface.php`。验收：业务契约只有 `dealInput` 和 `dealProcess`。
- [ ] `src/Worker/AbstractWorker.php`：`accept`、连接表、读缓冲上限、`recv_timeout` / `send_timeout` / `idle_timeout`、`max_connections`、持久连接在一帧结束后清缓冲。验收：超过 `max_connections` 时拒绝并让 `conn_rejected` 加一，不无限攒缓冲。
- [ ] 未捕获的 `Throwable` 变成协议错误，worker 进程不退出。验收：错误帧带 `ErrorCode::InternalError` 和同一个 request-id，日志有对应的 error 行。
- [ ] `src/Worker/RpcWorker.php`。验收：解析 text-rpc/1，按方法调用 Handler，再把结果编码成 `RES` 或 `ERR`；`PING` 回 `PONG`。
- [ ] `src/Metrics/` 只计数，不渲染：`accept`、`requests`、`errors`、`conn_rejected`、`idle_timeout`。验收：计数器可在进程内读到，没有 Prometheus 文本。
- [ ] `examples/Echo/`（`EchoWorker`）。验收：`php bin/worker --config config/server.echo.php Echo` 之后，`curl` 业务端口得到明文 `hello`。
- [ ] `examples/Rpc/`：`RpcWorker` 子类、一个 Handler、`bootstrap`。验收：`bin/phpserver-rpc` 打到 `config/server.example.php` 的 `Rpc`，结果正确，CLI 打出 request-id。
- [ ] `bin/worker`：无 master、前台、日志到 stdout、`-v`、`--config`、worker 名。验收：占位脚本被换掉，不再无条件 `exit(1)`。
- [ ] 超过 `max_frame_bytes` 的帧。验收：返回协议错误并关闭连接，计数加一。
- [ ] 只发半包然后停住的客户端。验收：到 `recv_timeout` 后关闭，不挂住进程。
- [ ] `tests/Integration/` 用子进程覆盖上面四条：Echo 的 `hello`、RPC 成功、Handler 抛异常、超长帧或半包超时。验收：PHP 8.4 下无 deprecation。
- [ ] `docs/worker-contract.md`。验收：写明两个钩子、连接何时关闭、异常如何变成错误帧。
- [ ] `docs/debugging.md` 至少三条能复制运行的配方（单 worker、打一帧 RPC、跟着 request-id 看日志）。验收：按文档命令能跑通。

## M5 master 与 `bin/serverd`

admin 的 HTTP 和完整 `/status` 留到 M6。本里程碑不另开 unix 控制套接字，也不做 `SO_REUSEPORT`。

- [ ] `src/Master/`：master 统一 `listen`，按 `child_count` fork，子进程关掉无关 FD，只留自己的 listen 和 IPC。验收：非 root 下 `bin/serverd start --foreground -c config/server.example.php` 起 N 个进程，`ps` 能看到进程标题。
- [ ] `user` / `group` 只在以 root 启动时 `setgid` / `setuid`。验收：普通用户启动时跳过降权，不因此失败。
- [ ] `SIGCHLD` 在循环里收尸，不在信号处理器里 fork。验收：`kill -9` 一个 worker 后约 1 秒内补齐，`worker_restarts` 加一。
- [ ] 心跳 `ping` / `pong`，错过上限则强杀并拉起。验收：强杀记入强制回收，不和正常 drain 混用同一个计数。
- [ ] `SIGTERM` / `SIGINT`：停止 accept，等在途请求，超时再强杀。验收：慢请求能完成，进程树退出码 0；超过排空时间则强杀并让 `drain_timeout` 加一。
- [ ] `SIGUSR2` 或 `reload`：逐个换 worker。验收：替换期间并发的 `bin/phpserver-rpc` 没有失败。
- [ ] `max_requests` / `max_work_time` 默认 `0`（关）。打开时先排空再退出，并加上随机 `recycle_stagger`。验收：`max_requests=50` 时该 worker 在第 50 个请求完成后被换掉；默认配置从不触发。
- [ ] master 死亡时 worker 自己退出。验收：`kill -9` master 之后，心跳超时内没有残留 worker。
- [ ] `src/Ipc/`：unix socketpair、定长头、命令 enum（`stop`、`drain`、status、metrics 快照、`ping`），不用 `serialize`。验收：一条命令可以往返，body 不是 PHP 序列化串。
- [ ] `src/Server.php`：`Server::fromConfig(...)->run()`，标 `@api`。验收：`bin/serverd` 和以后的调用方从这个门面进去。
- [ ] `bin/serverd`：`start` / `stop` / `restart` / `reload` / `status`，以及 `--foreground` 和 daemon。验收：PID 文件默认不写；`status` 在本里程碑只报告 master 是否活着和 worker 数量。
- [ ] 计数增加 `worker_restarts`、`drain_timeout`、`forced_kill`。验收：上面三条路径各自加一，仍不渲染成 Prometheus 文本。
- [ ] `docs/operations.md`：`SIGTERM` 的顺序，以及 `terminationGracePeriodSeconds` 要盖住排空时间加余量。验收：文档里的顺序和 master 的实现一致。

## M6 admin 端口与限流钩子

不做 OpenTelemetry、仪表盘、mTLS / RBAC，也不做外部配额服务。

- [ ] `src/Admin/`：listen 在 master 里，默认 `127.0.0.1`，可选 token；只接受 GET，有读超时和大小上限。验收：慢客户端占不住这个 FD。
- [ ] `GET /healthz`。验收：master 活着时 200；它表示进程还在，不表示能接业务。
- [ ] `GET /readyz`。验收：已经 listen 且有就绪 worker 时 200；对 master 发 `SIGTERM` 后、listen 关掉之前就已经不是 200。
- [ ] `GET /status`。验收：JSON 里有版本、uptime、每个 worker 的 pid / 状态 / 请求计数；FD 只给计数，不逐个 dump。
- [ ] `GET /metrics`：Prometheus 文本，前缀 `phpserver_`，至少包括 accept、requests、errors、`worker_restarts`、`conn_rejected`、rejected、degraded、`drain_timeout`。验收：100 个请求里 5 个抛异常时，`phpserver_requests_total` 和 errors 对得上；有 `promtool` 时 `check metrics` 通过。
- [ ] 业务端口上的 `/healthz` 等路径不当成 admin。验收：打到业务端口得到协议错误或 Echo/RPC 的正常处理，而不是探针正文。
- [ ] 关掉 admin 和 metrics 之后 Echo / RPC 仍工作。验收：`admin.enabled=false` 时不 listen admin 端口，业务请求成功。
- [ ] `src/RateLimit/LimiterInterface.php`，在 `dealProcess` **之前**调用，返回放行、拒绝或降级。验收：默认实现什么都不做，热路径上没有额外判定。
- [ ] 一个标准库 token bucket 示例，默认关闭。验收：打开并压低速率时，客户端收到 `-32001`，`rejected` 增加；关闭时该计数保持 0。
- [ ] `bin/serverd status` 改为请求本机 admin `/status` 并格式化。验收：不再只靠 PID 文件猜测。
- [ ] 把探针、scrape 和一条 admin 配方写进 `docs/operations.md`、`docs/debugging.md`。验收：文档里的 `curl` 指向 admin 端口，不是业务端口。

## M7 基准、部署、1.0.0

不做 TechEmpower 正式提交，不做 Helm chart / Operator，不做 ghz / gRPC。

- [ ] `benchmarks/README.md`。验收：报告模板含机器规格、PHP 版本、连接数、payload、P50 / P99、QPS、错误率。
- [ ] `benchmarks/http/`：wrk、vegeta、k6 打 Echo 的明文。验收：说明里限流关闭、metrics 开启。
- [ ] `benchmarks/rpc/bench.php`，复用 `src/Client`。验收：脚本不进 PSR-4，能对一个已启动的 Rpc worker 打出延迟数字。
- [ ] `benchmarks/legacy/`：和旧服务器同机对照的步骤。验收：仓库里不放入旧源码；旧的跑不起来时，报告写明跳过。
- [ ] CI 里加一段短时基准冒烟。验收：只检查跑得通，不设 QPS 门槛，不挡住日常推送；完整压测留在手工或定时任务。
- [ ] `deploy/Dockerfile`：PHP 8.4、`linux/amd64` 与 `linux/arm64`、非 root `USER`、前台 PID 1、`--foreground`。验收：镜像里 `id -u` 不是 0；`docker stop` 的日志能看到排空。
- [ ] `deploy/k8s/`：Deployment 的 liveness 指 admin `/healthz`、readiness 指 `/readyz`，grace period 按 `docs/operations.md`；Service 只暴露业务端口；NetworkPolicy 和 Prometheus 注解可选。验收：在 kind 或 minikube 里 apply 之后 Pod Ready，滚动更新期间 RPC 连续调用无失败。
- [ ] 五份文档定稿：`docs/protocol-text-rpc.md`、`docs/worker-contract.md`、`docs/configuration.md`、`docs/operations.md`、`docs/debugging.md`。验收：和实现不一致的句子改掉，README 快速开始不超过 5 条命令就能看到 Echo 的 `hello`。
- [ ] `CHANGELOG.md` 写入 `1.0.0` 和 `text-rpc/1`。验收：两栏都有条目，协议版本不跟库的补丁号绑死。

## 横切

- [x] CI 矩阵：PHP 8.3 与 8.4，`composer validate --strict`、无运行时包、`composer cs`、`composer analyse`、`composer test`。验收：`.github/workflows/ci.yml`。
- [ ] 后续每个里程碑保持 `require` 为空，新行为用标准库。验收：`composer show --no-dev --name-only` 仍为空。
- [ ] 文档和代码一起改：配置键、错误码、信号顺序、admin 路径变了，就改 `docs/` 和 README 里指向它们的那一行。验收：README 仍链到本文件和 `docs/ROADMAP.md`。
- [ ] 发布 1.0.0 前核对：两档 PHP 的 CI 全绿；`CHANGELOG` 两栏写满；镜像非 root；探针打在 admin 端口；`bin/serverd`、`bin/worker`、`bin/phpserver-rpc` 不再是占位；标签用库的 SemVer，协议版本单独写。

## 已知后续

这里是还没拍板、或实现了但测试没盖住的点。不要在对应里程碑里偷偷改掉。

- **`stop()` 从流回调返回的时机。** `SelectLoop::run` 在一拍的开头跑定时器，`stop` 标志要到定时器之后才看。流回调发生在 select 之后，所以从流回调里 `stop()`，当前读/写回调会跑完，**下一拍还会再跑一次到期定时器**，然后 `run()` 才返回。定时器回调里 `stop()` 则是本批定时器结束后就返回。`testZeroDelayTimerAddedFromACallbackRunsOnTheNextTick` 依赖现在的顺序（`first`，然后 `read`，然后 `second`）。要不要改成「流回调里一旦 `stop()`，不再跑那一拍定时器」，还没定。
- **`EINTR` 没有直接测试。** 打断后继续循环的代码在 `SelectLoop::isInterrupted`。现有集成测试是子进程里的 `SIGTERM`，走的是没有流时的 `time_nanosleep`，不是 `stream_select` 被 `SIGUSR1` 打断。补测试或接受这个覆盖面，留到下次改循环时一起定。
- **text-rpc/1 规格正文还没进仓库。** 帧形已经锁定（`TRPC/1`、`REQ|RES|ERR|PING|PONG`、`json`、按序回复、默认 body 1 MiB）。M3 仍应先把 `docs/protocol-text-rpc.md` 落库再写解码器；若规格和锁定条目冲突，改规格之前先改决策记录。
- **多 worker 的指标怎么汇总。** M5 先做 IPC 快照命令。M6 默认在每次 scrape 时经 IPC 拉取。若因此拖慢 master，再改成 worker 定期推送。
- **惊群。** v1 接受多个 worker 共享同一个 listen FD。M7 用基准记下 P99，再决定 1.0 之后要不要评估 `SO_REUSEPORT`。
- **`inotify` 自动 reload。** M5 不做。开发机上要不要做，放到 M7 或更晚，不挡 1.0.0。
