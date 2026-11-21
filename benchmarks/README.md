# 基准

这里是手工压测的记录方式。CI 只跑 `benchmarks/rpc/bench.php` 的一小段，确认能打出延迟数字，不设 QPS 门槛。wrk、vegeta、k6 不装进 CI。

v1 多个 worker 共享同一个 listen FD，接受惊群。把一次手工报告里的 P99 留下来，再决定 1.0 之后要不要评估 `SO_REUSEPORT`。M7 不改 listen 模型。

## 报告模板

一次报告抄下面这一段，数字换成本机测到的。工具没装、或旧服务器起不来，就在对应行写「跳过」和原因，不要留空。

```text
日期：
机器：CPU 型号、核数、内存
系统：
PHP：
服务：bin/serverd 或 bin/worker，配置文件，child_count
地址：
工具：wrk / vegeta / k6 / benchmarks/rpc/bench.php
连接数：
payload：
时长：
P50：
P99：
QPS：
错误率：
备注：限流默认关闭。共享 listen FD，未开 SO_REUSEPORT。
```
