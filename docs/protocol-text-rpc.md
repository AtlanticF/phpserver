# text-rpc/1 协议规格（草案，待用户评审）

**状态：** Draft for review —— 评审通过后成为仓库 `docs/protocol-text-rpc.md`  
**日期：** 2026-10-16（Asia/Shanghai）  
**依据：** `DECISIONS.md`（2026-10-16「线协议 text-rpc/1」条）、`PROTOCOL_RESEARCH.md`  
**协议版本：** `TRPC/1`（与 `phpserver/phpserver` 库的 SemVer 版本相互独立）

本文中的 **MUST / MUST NOT / SHOULD / SHOULD NOT / MAY** 按 RFC 2119 / RFC 8174 解释（仅在全大写时具有规范含义）。

---

## 1. 概述与目标

text-rpc/1 是一个跑在 TCP 字节流上的请求/响应 RPC 协议。

目标：

1. **可手工调试** —— 用 `nc` / `telnet` 能读能写（核心思路）。
2. **二进制安全、可提前拒绝超大帧** —— body 带字节长度前缀。
3. **实现极简** —— 读一行 + 按长度读 body；服务端只用 PHP 标准库。
4. **可演进** —— 版本、codec、尾部扩展字段都在头行里预留。

非目标（v1）：多路复用乱序回复、notification、batch、流式、压缩、鉴权握手。

---

## 2. 帧（Frame）

### 2.1 结构

每个帧由三部分组成，依次排列：

```text
header-line  EOL
body         （恰好 len 字节）
EOL
```

- `EOL` 是 `LF`（`\n`，0x0A）。接收方 **MUST** 也接受 `CR LF`（`\r\n`）作为 EOL。发送方 **SHOULD** 只发 `LF`。
- body 后面的 EOL **MUST** 存在；接收方读完 `len` 字节后 **MUST** 校验紧随其后的是 EOL，否则为帧错误。
- `len` 为 0 时 body 为空，帧仍以 header-line EOL + EOL 结束（nc 中显示为一个空行）。

### 2.2 头行语法（ABNF 风格）

```abnf
header-line  = magic SP kind SP id SP codec SP len [SP trace-id] *(SP ext-field)
magic        = "TRPC/1"
kind         = "REQ" / "RES" / "ERR" / "PING" / "PONG"
id           = "0" / ( %x31-39 *18DIGIT )          ; 十进制，无前导零
codec        = "json" / "-" / codec-token            ; codec-token 见 §3.2
codec-token  = 1*16( %x61-7A / DIGIT / "-" )         ; 小写字母、数字、连字符
len          = "0" / ( %x31-39 *9DIGIT )
trace-id     = 1*64( ALPHA / DIGIT / "." / "_" / "-" )
ext-field    = ext-key "=" ext-value
ext-key      = 1*32( %x61-7A / DIGIT / "-" )
ext-value    = 1*128( %x21-7E )                      ; 可见 ASCII，不含空格
SP           = %x20                                   ; 恰好一个空格
```

规则：

- 头行（不含 EOL）**MUST NOT** 超过 **256 字节**；超过即帧错误（`-32002 FrameTooLarge`）。
- 所有关键字区分大小写。字段之间 **MUST** 恰好一个空格；行首行尾 **MUST NOT** 有多余空白。
- `trace-id` 与 `ext-field` 的区分：含 `=` 的 token 是 `ext-field`，不含的是 `trace-id`。`trace-id` 若出现，**MUST** 紧跟在 `len` 之后。

---

## 3. 字段定义

### 3.1 `id`

- 范围 **0 ~ 9223372036854775807**（2^63−1，PHP `int` 可无损表示）。
- `REQ`：客户端 **MUST** 使用 1 ~ 2^63−1；在同一连接上尚未收到回复的请求之间 **MUST** 唯一。推荐从 1 递增。
- `RES` / `ERR`：**MUST** 原样回传对应 `REQ` 的 id。服务端因头行无法解析而拿不到 id 时 **MUST** 使用 `0`。
- `PING`：**MAY** 用任意合法 id（含 0）；`PONG` **MUST** 回传同一 id。
- 服务端 **MAY** 对重复的在途 id 回复 `-32600`；但因 v1 按序回复，服务端 **不要求**检测重复。

### 3.2 `codec`（编码注册表）

| 值 | 状态 | 含义 |
|----|------|------|
| `json` | **v1 唯一必须支持** | body 为 UTF-8 JSON（RFC 8259） |
| `-` | v1 | 无 body；仅用于 `PING` / `PONG`，此时 `len` **MUST** 为 0 |
| `msgpack` | 保留 | 未来可选 |
| `proto` | 保留 | 未来可选 |
| 其它 | 保留 | 新增 codec 是**非破坏性**变更（§8） |

- 服务端收到不支持的 codec：**MUST** 用 `json` 回复 `ERR` `-32004 UnsupportedVersionOrCodec`，并**跳过**该 body（因长度已知，连接可保持）。
- `RES` / `ERR` 的 codec **SHOULD** 与请求一致；`ERR` 在请求 codec 不受支持时 **MUST** 用 `json`。

### 3.3 `len`

- body 的**字节数**（不是字符数），不含结尾 EOL。
- 默认上限 **1 MiB（1048576）**，由服务端配置 `max_body_bytes` 调整，硬上限 **64 MiB（67108864）**。
- 服务端读到头行即可判断超限：**MUST** 回复 `-32002 FrameTooLarge` 后关闭连接，**MUST NOT** 读取或缓冲该 body。

### 3.4 `trace-id`

- 字符集 `[A-Za-z0-9._-]`，长度 1 ~ 64。
- 客户端 **MAY** 在 `REQ` 上带；服务端 **MUST** 原样沿用；未带时服务端 **MUST** 生成一个（格式由实现决定，建议 `r-` + 随机十六进制）。
- `RES` / `ERR` **MUST** 带 `trace-id`；`PING` / `PONG` **MAY** 不带。
- 服务端日志中与该请求相关的每一行 **MUST** 含此 trace-id（见 DECISIONS：request-id）。

### 3.5 扩展字段 `ext-field`

- 形如 `key=value`，位于头行末尾，可有多个。
- 接收方 **MUST** 忽略不认识的扩展字段（这是向前兼容的关键）。
- 扩展字段 **MUST NOT** 改变帧边界、`len` 语义或回复顺序；需要这么做的变化属于 `TRPC/2`。
- v1 不定义任何扩展字段。以 `x-` 开头的 key 留给私有用途。

---

## 4. 帧类型

| kind | 方向 | body | 语义 |
|------|------|------|------|
| `REQ` | 客户端 → 服务端 | 请求对象（§5.1） | 调用一个方法；**MUST** 得到恰好一个 `RES` 或 `ERR` |
| `RES` | 服务端 → 客户端 | 成功对象（§5.2） | 调用成功 |
| `ERR` | 服务端 → 客户端 | 错误对象（§5.3） | 调用失败或帧错误 |
| `PING` | 任一方向 | 空（codec `-`，len 0） | 心跳；接收方 **MUST** 回复 `PONG` |
| `PONG` | 任一方向 | 空 | 对 `PING` 的回复 |

- 服务端 **MUST NOT** 主动发 `RES`/`ERR` 以外的未请求帧，`PING` 除外（v1 服务端 **SHOULD NOT** 主动 PING）。
- 客户端收到 `REQ` 时 **MUST** 视为协议错误并关闭连接（v1 无反向调用）。
- `PING` 在流水线中同样占一个顺序位置：`PONG` 按到达顺序排在前面的回复之后。

---

## 5. body 结构（codec = json）

### 5.1 请求

```json
{"method": "Service.method", "params": [..] | {..}}
```

- `method`：**MUST** 是非空字符串。服务端约定形如 `Class.method`（映射到 Handler），但协议只把它当不透明字符串。
- `params`：**MAY** 省略；若存在 **MUST** 是 JSON 数组（按位置）或对象（按名）。
- 未知的顶层键：服务端 **MUST** 忽略。
- 不使用 `"jsonrpc"` 与 `"id"` 字段（id 在头行）；若出现，服务端 **MUST** 忽略。

### 5.2 成功

```json
{"result": <任意 JSON 值，含 null>}
```

### 5.3 错误

```json
{"error": {"code": <int>, "message": <string>, "data": <任意 JSON，可省略>}}
```

- `message` 是给人看的简短描述，**SHOULD** 为英文。
- `data` **MUST NOT** 包含堆栈、文件路径、密钥等内部细节（见 §10）；调试靠 trace-id 查日志。

---

## 6. 错误码

| code | 名称 | 级别 | 连接 | 说明 |
|------|------|------|------|------|
| −32700 | ParseError | body | 保持 | body 不是合法 JSON / 非法 UTF-8 |
| −32600 | InvalidRequest | body 或帧 | body：保持；帧：关闭 | body 结构不对（缺 method 等）；**也用于头行无法解析的帧错误** |
| −32601 | MethodNotFound | body | 保持 | 方法不存在 |
| −32602 | InvalidParams | body | 保持 | 参数不符 |
| −32603 | InternalError | body | 保持 | Handler 抛出未处理异常 |
| −32001 | RateLimited | body | 保持 | 被限流钩子拒绝（DECISIONS：默认关） |
| −32002 | FrameTooLarge | 帧 | **关闭** | 头行 > 256 字节，或 `len` > `max_body_bytes` |
| −32003 | Timeout | body | 保持（见 §7.4） | 处理超过 `process_timeout` |
| −32004 | UnsupportedVersionOrCodec | 帧 / body | 版本：**关闭**；codec：保持 | 魔数不是 `TRPC/1`；或 codec 不支持 |
| −32005 | ShuttingDown | 连接 | 回复后**关闭** | 服务端正在排空 |
| −32006 | Overloaded | body | 保持 | 每连接积压超限，或服务端过载 |
| −32007 ~ −32099 | — | — | — | 保留给后续框架错误，客户端 **MUST** 按「未知服务端错误」处理 |
| ≥ 1 | 业务错误 | body | 保持 | 由业务 Handler 定义 |
| 0、−1 ~ −31999、−32100 ~ −32699、−32701 ~ −32768 中未列出者、< −32768 | 保留 | — | — | 服务端 **MUST NOT** 使用；客户端视为未知错误 |

规则：

- **帧级错误**（帧边界已不可信）：服务端 **MUST** 尽力回复一个 `ERR`（拿不到 id 时用 0），然后**关闭连接**。
- **body 级错误**：服务端 **MUST** 回复 `ERR`，连接**保持**，继续处理下一帧。

---

## 7. 连接行为

### 7.1 流水线与顺序

- 客户端 **MAY** 不等回复连续发送多个 `REQ`/`PING`（流水线）。
- 服务端 **MUST** 按帧到达顺序处理，并**按相同顺序**发送回复。
- 客户端 **SHOULD** 仍用 id 匹配回复，以便将来支持乱序（v2）时无需改代码。

### 7.2 积压上限（backlog）

- 每连接已接收但尚未回复的帧数上限：配置 `pipeline_backlog`，**默认 32**。
- 超限的 `REQ`：服务端 **MUST NOT** 执行，**MUST** 在它的顺序位置上回复 `-32006 Overloaded`；连接保持。
- 服务端 **MAY** 在积压时暂停从该连接读取（TCP 背压），这也是合规的行为。

### 7.3 超时（配置键见 `docs/configuration.md`）

| 配置 | 含义 | 到期行为 |
|------|------|---------|
| `recv_timeout` | 一个帧开始后必须在此时间内读完 | 关闭连接（无法回复，帧不完整） |
| `idle_timeout` | 两帧之间无数据的最长时间 | 静默关闭连接 |
| `send_timeout` | 回复写出的最长时间 | 关闭连接 |
| `process_timeout` | 单个请求处理时长 | 见 §7.4 |

### 7.4 处理超时

- 若服务端能在超时后仍保持该连接状态一致，**SHOULD** 回复 `-32003 Timeout`。
- 若实现需要终止 worker（例如通过 alarm），则连接会被关闭；客户端 **MUST** 把「连接在等待回复时断开」当作结果未知（请求**可能已执行**）。

### 7.5 优雅关闭

- 服务端进入排空（收到 SIGTERM）后：
  - 已开始处理的请求 **MUST** 正常完成并回复；
  - 此后读到的 `REQ` **MUST** 回复 `-32005 ShuttingDown`（不执行），随后关闭连接；
  - 空闲连接 **MAY** 直接关闭。
- 客户端收到 `-32005` **SHOULD** 换一个连接/实例重试（请求未执行，重试安全）。

### 7.6 心跳

- 客户端 **MAY** 在空闲连接上发 `PING` 以保活或探测；间隔 **SHOULD** 小于服务端 `idle_timeout`。
- 收到 `PING` 的一方 **MUST** 回复 `PONG`，id 相同。

---

## 8. 版本与兼容性

| 变更 | 类型 | 处理方式 |
|------|------|---------|
| 新增 codec | 非破坏 | 注册表加一行；老服务端回复 −32004 |
| 新增扩展字段 | 非破坏 | 老接收方忽略 |
| 新增错误码（−32007 ~ −32099 或业务码） | 非破坏 | 客户端按未知错误处理 |
| body 中新增可选键 | 非破坏 | 接收方忽略未知键 |
| 新增 kind | **破坏** | → `TRPC/2`（老实现会当帧错误） |
| 改变帧边界、`len` 语义、EOL 规则、字段顺序 | **破坏** | → `TRPC/2` |
| 允许乱序回复 | **破坏** | → `TRPC/2`（或通过显式协商，v1 不定义） |
| 改变已有错误码含义 | **破坏** | 不允许 |

- 服务端收到不认识的魔数（如 `TRPC/2`）：**MUST** 用 `TRPC/1` 帧回复 `-32004` 并关闭。
- `CHANGELOG.md` 中协议变更单独一栏，与库版本分开记录。

---

## 9. 大小上限汇总

| 项 | 默认 | 可配 | 硬上限 |
|----|------|------|--------|
| 头行 | 256 字节 | 否 | 256 字节 |
| body（`max_body_bytes`） | 1 MiB | 是 | 64 MiB |
| 每连接积压（`pipeline_backlog`） | 32 | 是 | 实现自定 |
| `trace-id` | 64 字符 | 否 | 64 |

---

## 10. 安全注意事项

- 协议本身**不加密、不鉴权**。生产 **SHOULD** 只在私有网络 / K8s NetworkPolicy 内暴露；需要加密时在外部（mesh / stunnel / TLS 终结）完成。业务端口默认 bind `127.0.0.1`（见 EXTRA_CONSIDERATIONS）。
- `len` 先于 body 校验 → 服务端不会为超大帧分配内存。
- JSON 解码 **MUST** 设置深度上限（建议 64）并使用 `JSON_THROW_ON_ERROR`；**MUST NOT** 把 body 反序列化为任意 PHP 对象。
- `ERR` 的 `message`/`data` **MUST NOT** 泄露内部细节；完整异常只进日志（带 trace-id）。
- 日志中 body **MUST** 截断（DECISIONS：verbose/trace body 截断）。
- `trace-id` 字符集受限，防止日志注入。

---

## 11. nc / telnet 会话示例

以下 `C:` 为客户端发送、`S:` 为服务端回复（前缀不属于协议）。所有 `len` 均已用脚本校验。

#### 示例 1：一次成功调用

```text
C: TRPC/1 REQ 1 json 37
C: {"method":"Echo.say","params":["hi"]}
S: TRPC/1 RES 1 json 15 r-7f3a9c01
S: {"result":"hi"}
```

#### 示例 2：带 trace-id 的请求与命名参数

```text
C: TRPC/1 REQ 2 json 40 web-1a2b3c
C: {"method":"User.get","params":{"id":42}}
S: TRPC/1 RES 2 json 33 web-1a2b3c
S: {"result":{"id":42,"name":"Mei"}}
```

#### 示例 3：方法不存在（body 级错误，连接保持）

```text
C: TRPC/1 REQ 3 json 37
C: {"method":"Echo.sya","params":["hi"]}
S: TRPC/1 ERR 3 json 83 r-7f3a9c02
S: {"error":{"code":-32601,"message":"Method not found","data":{"method":"Echo.sya"}}}
```

#### 示例 4：业务错误（code ≥ 1）

```text
C: TRPC/1 REQ 4 json 48
C: {"method":"Order.pay","params":{"order":"A100"}}
S: TRPC/1 ERR 4 json 56 r-7f3a9c03
S: {"error":{"code":1002,"message":"Insufficient balance"}}
```

#### 示例 5：流水线（连续发两个请求，按序回复）

```text
C: TRPC/1 REQ 5 json 36
C: {"method":"Echo.say","params":["a"]}
C: TRPC/1 REQ 6 json 36
C: {"method":"Echo.say","params":["b"]}
S: TRPC/1 RES 5 json 14 r-7f3a9c04
S: {"result":"a"}
S: TRPC/1 RES 6 json 14 r-7f3a9c05
S: {"result":"b"}
```

#### 示例 6：心跳

```text
C: TRPC/1 PING 9 - 0
S: TRPC/1 PONG 9 - 0
```

#### 示例 7：body 超过上限（帧级错误，回复后关闭连接）

```text
C: TRPC/1 REQ 10 json 2000000
S: TRPC/1 ERR 10 json 87 r-7f3a9c06
S: {"error":{"code":-32002,"message":"Frame too large","data":{"max_body_bytes":1048576}}}
S: <server closes connection>
```

#### 示例 8：头行无法解析（id 用 0，回复后关闭）

```text
C: HELLO
S: TRPC/1 ERR 0 json 58 r-7f3a9c07
S: {"error":{"code":-32600,"message":"Invalid frame header"}}
S: <server closes connection>
```

#### 示例 9：服务端正在排空（ShuttingDown）

```text
C: TRPC/1 REQ 11 json 36
C: {"method":"Echo.say","params":["x"]}
S: TRPC/1 ERR 11 json 51 r-7f3a9c08
S: {"error":{"code":-32005,"message":"Shutting down"}}
S: <server closes connection>
```

#### 示例 10：带扩展字段（旧服务端忽略 `x-prio=high`）

```text
C: TRPC/1 REQ 12 json 37 web-9z x-prio=high
C: {"method":"Echo.say","params":["hi"]}
S: TRPC/1 RES 12 json 15 web-9z
S: {"result":"hi"}
```

### 11.1 用 nc 手工发送

手敲时数字节不便，可用 shell 计算长度：

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

或直接用官方 CLI：

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

telnet 发送 `\r\n` 行尾也可以（§2.1）。注意 `len` 只计算 body 字节，不含行尾。

---

## 12. 客户端实现清单

- [ ] 写：`TRPC/1 REQ <id> json <len>[ <trace-id>]\n` + body + `\n`；`len` 按 **UTF-8 字节**计算。
- [ ] id 从 1 递增，在途唯一；不使用 0。
- [ ] 读：读一行（≤ 256 字节，接受 `\r\n`）→ 按空格切分 → 校验魔数与 kind → 读 `len` 字节 → 读并校验 EOL。
- [ ] 忽略头行中不认识的 `key=value` 扩展字段；忽略 body 中不认识的键。
- [ ] 流水线时用 FIFO 队列匹配回复，并校验 id 一致；不一致即关闭连接。
- [ ] 区分三类结果：`RES`（成功）、`ERR`（看 code）、连接断开（**结果未知**，不要自动重试非幂等调用）。
- [ ] `-32005 ShuttingDown`、`-32006 Overloaded`、`-32001 RateLimited`：请求未执行，可退避后重试。
- [ ] 遇到帧级错误（−32002、头行 −32600、版本 −32004）后服务端会关连接：丢弃连接。
- [ ] 设置连接、读、写超时；空闲连接可发 `PING`。
- [ ] 把服务端返回的 trace-id 暴露给调用方 / 写进自己的日志。
