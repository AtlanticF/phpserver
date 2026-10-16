# 配置

M1 只负责把配置读进来并校验。进程、端口监听和 admin HTTP 都还没实现；这里的键是后面里程碑要遵守的契约。

配置文件是一个返回关联数组的 PHP 文件。环境变量在文件值之后应用，同名时环境变量优先。

```php
<?php

declare(strict_types=1);

return [
    'workers' => [
        'Echo' => [
            'listen' => '127.0.0.1:9000',
            'worker_class' => 'PhpServer\\Examples\\Echo\\EchoWorker',
        ],
    ],
];
```

每个 worker 至少要有 `listen` 和 `worker_class`。其余键有默认值。未知键不会让进程启动失败，会记一条 warning（挂在 `Config::$warnings` 上；如果构造 `ConfigLoader` 时传了 logger，也会打一条 warning 日志）。

校验失败抛出 `PhpServer\Exception\ConfigException`。`ConfigLoader::loadOrExit()` 把同一段消息写到 stderr，前缀是 `phpserver: configuration error:`，然后以状态码 1 退出。

## 文件里的类型

PHP 文件里用真正的类型：整数用 `int`，超时用 `int` 或 `float`，开关用 `bool`，地址和类名用 `string`。不要在文件里写 `'30'` 这种数字字符串。

环境变量全是字符串，按下表解析：

| 类型 | 接受的写法 |
|------|------------|
| int | 十进制，可带负号，不能有前导零，必须落在 PHP int 范围内 |
| float | `30` 或 `30.5` |
| bool | `true` / `false` / `1` / `0` / `yes` / `no` / `on` / `off`（大小写不敏感） |
| string | 单行文本 |

## 根键

| 键 | 默认 | 环境变量 | 说明 |
|----|------|----------|------|
| `pid_file` | `''` | `PHPSERVER_PID_FILE` | 空字符串表示不写 PID 文件 |
| `user` | `''` | `PHPSERVER_USER` | 空表示不降权。否则是用户名或数字 uid |
| `group` | `''` | `PHPSERVER_GROUP` | 同上，组名或 gid |
| `log` | 见下表 | — | 日志 |
| `admin` | 见下表 | — | 独立 admin 口。M1 只校验，不监听 |
| `workers` | 必填，至少一个 | — | worker 名 => 选项。名字必须匹配 `[A-Za-z][A-Za-z0-9]*`，且大小写不能互相冲突 |

## 日志 `log`

| 键 | 默认 | 环境变量 |
|----|------|----------|
| `level` | `info` | `PHPSERVER_LOG_LEVEL` |
| `json` | `false` | `PHPSERVER_LOG_JSON` |
| `timezone` | `UTC` | `PHPSERVER_LOG_TIMEZONE` |
| `body_truncate_bytes` | `256` | `PHPSERVER_LOG_BODY_TRUNCATE_BYTES` |

`level` 是 `debug`、`info`、`notice`、`warning`、`error`、`critical`、`alert`、`emergency` 之一，大小写不敏感。

时间只用于日志戳，格式是带微秒和数字时区偏移的 ISO-8601，例如 `2026-10-16T07:26:00.123456+00:00`。间隔和超时用单调时钟，不用这里的时区。

`body_truncate_bytes` 是调用方截断 body 时用的字节数（0 到 1 MiB）。截断按字节，不加后缀；正好等于阈值时不截。logger 不会自动截断，避免把截断藏起来。

JSON 行的字段是 `ts`、`level`、`message`，有 request-id 时还有 `request_id`，其余上下文在 `context` 里。没有 request-id 时不出现这个字段。

request-id 字符集与 text-rpc/1 的 trace-id 相同：`[A-Za-z0-9._-]{1,64}`。`RequestId::generate()` 生成 `r-` 加 16 位十六进制。不合法的 id 不会写进日志。

## Admin `admin`

| 键 | 默认 | 环境变量 |
|----|------|----------|
| `enabled` | `true` | `PHPSERVER_ADMIN_ENABLED` |
| `listen` | `127.0.0.1:9100` | `PHPSERVER_ADMIN_LISTEN` |
| `token` | `''` | `PHPSERVER_ADMIN_TOKEN` |
| `metrics` | `true` | `PHPSERVER_ADMIN_METRICS` |

`enabled` 为 false 时不把 admin 地址算进端口冲突（进程不会听这个口）。地址格式错误仍然会失败。

## Worker

名字举例：`Echo`、`Rpc`。全局环境变量会套到每一个 worker 上；单个 worker 的变量再覆盖回去。

| 键 | 默认 | 作用到全部 worker | 只作用到名为 Echo 的 worker |
|----|------|-------------------|------------------------------|
| `listen` | 必填 | `PHPSERVER_LISTEN` | `PHPSERVER_WORKER_ECHO_LISTEN` |
| `worker_class` | 必填 | `PHPSERVER_WORKER_CLASS` | `PHPSERVER_WORKER_ECHO_WORKER_CLASS` |
| `bootstrap` | `''` | `PHPSERVER_BOOTSTRAP` | `PHPSERVER_WORKER_ECHO_BOOTSTRAP` |
| `child_count` | `1` | `PHPSERVER_CHILD_COUNT` | `PHPSERVER_WORKER_ECHO_CHILD_COUNT` |
| `backlog` | `511` | `PHPSERVER_BACKLOG` | `PHPSERVER_WORKER_ECHO_BACKLOG` |
| `max_connections` | `1024` | `PHPSERVER_MAX_CONNECTIONS` | `PHPSERVER_WORKER_ECHO_MAX_CONNECTIONS` |
| `recv_timeout` | `30` | `PHPSERVER_RECV_TIMEOUT` | `PHPSERVER_WORKER_ECHO_RECV_TIMEOUT` |
| `send_timeout` | `30` | `PHPSERVER_SEND_TIMEOUT` | `PHPSERVER_WORKER_ECHO_SEND_TIMEOUT` |
| `idle_timeout` | `60` | `PHPSERVER_IDLE_TIMEOUT` | `PHPSERVER_WORKER_ECHO_IDLE_TIMEOUT` |
| `process_timeout` | `30` | `PHPSERVER_PROCESS_TIMEOUT` | `PHPSERVER_WORKER_ECHO_PROCESS_TIMEOUT` |
| `max_body_bytes` | `1048576` | `PHPSERVER_MAX_BODY_BYTES` | `PHPSERVER_WORKER_ECHO_MAX_BODY_BYTES` |
| `max_frame_bytes` | body + 260 | `PHPSERVER_MAX_FRAME_BYTES` | `PHPSERVER_WORKER_ECHO_MAX_FRAME_BYTES` |
| `pipeline_backlog` | `32` | `PHPSERVER_PIPELINE_BACKLOG` | `PHPSERVER_WORKER_ECHO_PIPELINE_BACKLOG` |
| `max_requests` | `0` | `PHPSERVER_MAX_REQUESTS` | `PHPSERVER_WORKER_ECHO_MAX_REQUESTS` |
| `max_work_time` | `0` | `PHPSERVER_MAX_WORK_TIME` | `PHPSERVER_WORKER_ECHO_MAX_WORK_TIME` |
| `recycle_stagger` | `0` | `PHPSERVER_RECYCLE_STAGGER` | `PHPSERVER_WORKER_ECHO_RECYCLE_STAGGER` |

超时的单位是秒。`recv_timeout`、`send_timeout`、`idle_timeout`、`process_timeout` 必须大于 0，并且不超过 365 天。`max_work_time` 和 `recycle_stagger` 允许 0，0 表示关闭。`max_requests` 为 0 也表示关闭回收阀。

`child_count` 必须是大于等于 1 的整数。

`listen` 写成 `host:port` 或 `[ipv6]:port`。不要写 `tcp://`。端口范围 1–65535，不能有前导零。`*`、`0.0.0.0`、`::` 视为通配地址。

### 端口冲突

下面的情况会失败：

- 两个 worker 的 host 和 port 都相同
- admin 开着，并且和某个 worker 落在同一个 host:port
- 同一端口上，一边是通配地址，另一边是任意地址（含另一个通配地址）

不解析 DNS。`127.0.0.1` 和 `10.0.0.8` 的同一端口不冲突。`127.0.0.1` 和 `0.0.0.0` 的同一端口冲突。这是故意偏严，避免启动后才发现 bind 失败。

admin 关闭时不参加这次比较。

### 帧大小

对齐 text-rpc/1，但 M1 不做编解码：

- 头行上限 256 字节，不可配置
- `max_body_bytes` 默认 1 MiB，硬上限 64 MiB（67108864）
- `max_frame_bytes` 是未完成帧的读缓冲上限，默认等于 `max_body_bytes + 260`
- 260 = 256 字节头行 + 头行后的 CR LF + body 后的 CR LF
- 显式设置时必须落在 `[max_body_bytes + 260, 67108864 + 260]` 里，保证最大的合法帧放得下

### `worker_class`

非空的类名字符串，例如 `PhpServer\Examples\Echo\EchoWorker`。M1 不检查这个类是否已经加载；后面的里程碑在真正启动时再加载。

## 不认识的环境变量

`PHPSERVER_` 开头但不是上表中的变量，会记 warning 并忽略。没有这个前缀的变量与本服务器无关。

没有 worker 时，worker 相关的变量不会偷偷生效，也会记一条 warning，同时因为缺少 `workers` 而校验失败。
