# Debugging

Run each of these from the repository root, and start only one worker at a time. The Echo config and the sample Rpc config both listen on `127.0.0.1:9000`. Do not start them together.

## 1. Single-process Echo

```sh
php bin/worker --config config/server.echo.php Echo
```

In another terminal:

```sh
curl -sS http://127.0.0.1:9000/
```

The body is `hello`. The worker logs on stdout in the first terminal. `Ctrl-C` or `SIGTERM` exits 0.

## 2. One RPC frame

```sh
php bin/worker --config config/server.example.php Rpc
```

In another terminal:

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

stdout is `"hi"`. `-v` prints the frames on stderr and prints `trace-id:`. `examples/Rpc/bootstrap.php` registers `Echo.say`.

The same frame by hand:

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

## 3. Follow a request-id

Set a trace-id on the frame, and have the worker print debug logs on stdout:

```sh
php bin/worker -v --config config/server.example.php Rpc
```

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' --trace-id=demo-1 -v
```

The worker stdout contains `request_id=demo-1`. The CLI stderr contains `trace-id: demo-1`. They are the same id.

A missing method or a thrown handler shares that id between the error frame and the log. The message on the frame is the short protocol sentence (for example `Internal error`). The exception text appears only in the worker log:

```sh
bin/phpserver-rpc 127.0.0.1:9000 Missing.method '[]' --trace-id=demo-err -v
```

Search the worker stdout for `demo-err`.

## 4. Look at admin, not the business port

Start the master. The sample config serves admin on `127.0.0.1:9100` and the business port on `127.0.0.1:9000`:

```sh
php bin/serverd start --foreground -c config/server.example.php
```

```sh
curl -sS http://127.0.0.1:9100/healthz
curl -sS http://127.0.0.1:9100/readyz
curl -sS http://127.0.0.1:9100/metrics
php bin/serverd status -c config/server.example.php
```

`/healthz` answering `ok` only means the master is still answering. `/readyz` answering `ready` means a worker is listening. `status` reads admin `/status`. `/healthz` on the business port is not a probe.
