# 事件循环

M2 提供单线程的 `stream_select` 循环，给后面的 worker 用。公开契约是 `PhpServer\Event\LoopInterface`。实现是 `SelectLoop`，构造时传入 M1 的 `ClockInterface`，日志可选。

```php
$loop = new SelectLoop(new SystemClock(), $logger);
$loop->onReadable($client, $this->onRead(...));
$loop->addTimer(0.0, function (int $id): void {
    // 下一次定时器遍历才会跑；0 表示下一拍，不是忙等
});
$loop->run();
```

回调一律是 `\Closure`，不是 `callable`。同一个流再次 `onReadable` / `onWritable` 会换掉旧回调，不会叠多个。`off*`、`removeStream`、`cancelTimer` 重复调用没有效果，也不会关闭流。调用方先 `removeStream()`，再 `fclose()`。

## 一拍里的顺序

1. 处理已经入队的信号。`pcntl_signal` 的处理函数只把信号编号放进队列，并打开 `pcntl_async_signals(true)`。回调在循环线程里执行。
2. 用这一拍读到的一次单调时间，跑所有到期定时器。周期定时器先改下一次到期时间，再执行回调。补跑最多一次：`due = max(due + interval, now)`，按计划时间而不是按「现在 + 间隔」连着追。
3. 如果调用过 `stop()`，这一拍到此结束，`run()` 返回。
4. 没有读流、写流、定时器、信号处理器时，`run()` 自己返回。
5. 计算距离下一个定时器的等待时间。没有定时器就一直阻塞。
6. 读集和写集都空时，用 `time_nanosleep` 睡到下一个定时器。只有信号、没有定时器时，最多睡 1 秒再回来看队列。这里不调用 `stream_select`，也不放占位 FD（旧实现在 PHP 8 上会崩）。
7. 否则对两个集合的副本做 `stream_select`。`EINTR` 安静地进入下一拍。其它失败写一条 error 日志并抛出 `RuntimeException`。
8. 先读回调，再写回调。同一个方向按注册顺序调用。每个回调调用前按 `(int) $stream` 再查一次表；同一拍里更早的回调如果已经 `removeStream()`，后来的回调就跳过。

`stop()` 只是把标志置上。定时器回调里调用它，当前这批定时器跑完就返回。流回调里调用它，当前这批读/写回调还会跑完，下一拍再跑一次定时器，然后返回。`run()` 退出后可以再次调用。

回调抛出的异常不会被吞掉，会从 `run()` 传出去。抛出前，这个定时器自己的状态已经更新（一次性的已删除，周期的已排下一次）。

## 时间

对外的延迟和间隔是秒（`float`）。`0` 表示下一拍的定时器遍历。周期间隔必须大于 0。负数或非有限数抛出 `InvalidArgumentException`。

内部全部用整数纳秒，来自 `ClockInterface::monotonic()`（`hrtime`）。定时器 id 从 1 递增，不复用。

## 信号

每个信号编号只有一个回调，后注册的覆盖先注册的。`offSignal()` 去掉回调并恢复 `SIG_DFL`。`pcntl_async_signals(true)` 是进程级开关，第一次注册信号时打开，之后保持打开。集成测试在子进程里注册 `SIGTERM`，回调里 `stop()`，父进程再发信号。

## 其它

- 单个回调的单调耗时超过构造参数 `slowCallbackThreshold`（默认 0.1 秒）时打一条 warning，带上闭包的文件和行号。
- 被监视的流（读和写按 id 去重）超过 1024（`FD_SETSIZE`）时打一次 warning。
- `SelectLoop::metrics()` 返回 `LoopMetrics`：拍数、当前读/写/定时器数量、已跑回调数、最慢回调纳秒。这个方法不在 `LoopInterface` 上。
