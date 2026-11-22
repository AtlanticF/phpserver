# Benchmarks

How to record a manual run. CI runs a short `benchmarks/rpc/bench.php` smoke and checks that it prints latencies. There is no QPS gate. wrk, vegeta, and k6 are not installed in CI.

v1 shares one listen fd across workers and accepts the thundering herd. A filled report keeps the P99. That number is what a later change would use to decide whether `SO_REUSEPORT` is worth testing. v1 does not switch the listen model.

Filled example: [reports/2026-11-22.md](reports/2026-11-22.md). That run does not turn on `SO_REUSEPORT`.

## Report template

Copy this block once per run. Replace the blanks with measurements from that machine. If a tool is not installed, or the old server cannot be started, write `skipped` and the reason on that line. Do not leave a line empty.

```text
Date:
Machine: CPU model, cores, memory
OS:
PHP:
Server: bin/serverd or bin/worker, config file, child_count
Address:
Tool: wrk / vegeta / k6 / benchmarks/rpc/bench.php
Connections:
Payload:
Duration:
P50:
P99:
QPS:
Error rate:
Notes: rate limit off. Shared listen fd, SO_REUSEPORT off.
```
