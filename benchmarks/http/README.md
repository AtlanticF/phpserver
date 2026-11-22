# Echo plaintext

Start Echo first. Rate limiting is off (nothing calls `setLimiter`). `admin.metrics` defaults to true, so a scrape of the admin port works during a run.

```sh
php bin/serverd start --foreground -c config/server.echo.php
```

In another terminal. These tools are not in CI. If one is missing, write `skipped` in the report.

```sh
curl -sS http://127.0.0.1:9000/
curl -sS http://127.0.0.1:9100/metrics
```

wrk. Add `--latency` when the report needs the printed P50 and P99:

```sh
wrk -t2 -c64 -d30s http://127.0.0.1:9000/
```

vegeta. The default offered rate is 50 requests/s, so this command is not a ceiling:

```sh
echo 'GET http://127.0.0.1:9000/' | vegeta attack -duration=30s | vegeta report
```

k6. The script is `echo.js` in this directory:

```sh
k6 run benchmarks/http/echo.js
```

The body is `hello`. Echo closes the connection after that response. The business port does not serve `/metrics`.
