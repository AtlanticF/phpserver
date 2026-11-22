# Compare with the old server

Run one pass of each server on the same machine. Fill the [report template](../README.md) twice, then compare P50, P99, and QPS. This repository does not contain the old PHPServer source, configuration, or docs.

1. Measure this repository with [http](../http/README.md) or [rpc/bench.php](../rpc/bench.php). Record the machine, PHP version, connection count, and payload.
2. Stop this repository. Start the old server on the same address and repeat the same connection count and payload.
3. Put the two reports side by side. If the old server cannot be started, the binary cannot be found, or it cannot be installed on this machine, write `skipped` and the reason in the second report. Do not copy the old source into this repository to force the comparison.
