# text-rpc/1 protocol specification

**Status:** Shipped with library 1.0.0. This file is the wire protocol `text-rpc/1`. The protocol version is not tied to the library's SemVer patch number.  
**Date:** 2026-10-16 (Asia/Shanghai)  
**Protocol version:** `TRPC/1`

The key words **MUST / MUST NOT / SHOULD / SHOULD NOT / MAY** are to be interpreted as described in RFC 2119 / RFC 8174 (they have normative meaning only when in full capitals).

---

## 1. Overview and goals

text-rpc/1 is a request/response RPC protocol on a TCP byte stream.

Goals:

1. **Debuggable by hand.** `nc` or `telnet` can read and write it. That is the core idea.
2. **Binary-safe, and able to reject an oversized frame early.** The body has a byte-length prefix.
3. **A small implementation.** Read one line, then read `len` body bytes. The server uses only the PHP standard library.
4. **Room to evolve.** Version, codec, and trailing extension fields are reserved on the header line.

Not goals for v1: multiplexed out-of-order replies, notification, batch, streaming, compression, an authentication handshake.

---

## 2. Frame

### 2.1 Layout

Each frame is three parts, in order:

```text
header-line  EOL
body         (exactly len bytes)
EOL
```

- `EOL` is `LF` (`\n`, 0x0A). A receiver **MUST** also accept `CR LF` (`\r\n`) as EOL. A sender **SHOULD** send only `LF`.
- The EOL after the body **MUST** be present. After reading `len` bytes, the receiver **MUST** check that the next bytes are an EOL. Otherwise the frame is an error.
- When `len` is 0 the body is empty. The frame still ends with the header-line EOL plus an EOL (an empty line in `nc`).

### 2.2 Header-line grammar (ABNF style)

```abnf
header-line  = magic SP kind SP id SP codec SP len [SP trace-id] *(SP ext-field)
magic        = "TRPC/1"
kind         = "REQ" / "RES" / "ERR" / "PING" / "PONG"
id           = "0" / ( %x31-39 *18DIGIT )          ; decimal, no leading zero
codec        = "json" / "-" / codec-token            ; codec-token is §3.2
codec-token  = 1*16( %x61-7A / DIGIT / "-" )         ; lowercase, digits, hyphen
len          = "0" / ( %x31-39 *9DIGIT )
trace-id     = 1*64( ALPHA / DIGIT / "." / "_" / "-" )
ext-field    = ext-key "=" ext-value
ext-key      = 1*32( %x61-7A / DIGIT / "-" )
ext-value    = 1*128( %x21-7E )                      ; visible ASCII, no space
SP           = %x20                                   ; exactly one space
```

Rules:

- The header line (not counting EOL) **MUST NOT** exceed **256 bytes**. Longer is a frame error (`-32002 FrameTooLarge`).
- Keywords are case-sensitive. Fields **MUST** be separated by exactly one space. The line **MUST NOT** have extra whitespace at either end.
- Tell `trace-id` from `ext-field` like this: a token that contains `=` is an `ext-field`. A token that does not is a `trace-id`. If a `trace-id` is present, it **MUST** come immediately after `len`.

---

## 3. Fields

### 3.1 `id`

- The range is **0 through 9223372036854775807** (2^63−1, which a PHP `int` can hold without loss).
- `REQ`: the client **MUST** use 1 through 2^63−1. Among requests on the same connection that have not yet been answered, ids **MUST** be unique. Starting at 1 and incrementing is recommended.
- `RES` / `ERR`: **MUST** copy the id of the matching `REQ`. When the server cannot parse the header line and therefore has no id, it **MUST** use `0`.
- `PING`: **MAY** use any legal id, including 0. `PONG` **MUST** copy that id.
- The server **MAY** answer a duplicate in-flight id with `-32600`. v1 replies in order, so the server is **not required** to detect duplicates.

### 3.2 `codec` (encoding registry)

| Value | Status | Meaning |
|-------|--------|---------|
| `json` | **the only codec v1 must support** | body is UTF-8 JSON (RFC 8259) |
| `-` | v1 | no body; only for `PING` / `PONG`, and `len` **MUST** be 0 |
| `msgpack` | reserved | optional later |
| `proto` | reserved | optional later |
| other | reserved | adding a codec is a **non-breaking** change (§8) |

- An unsupported codec: the server **MUST** reply with `json` and `ERR` `-32004 UnsupportedVersionOrCodec`, and **skip** that body (the length is known, so the connection can stay open).
- The codec of `RES` / `ERR` **SHOULD** match the request. When the request codec is unsupported, `ERR` **MUST** use `json`.

### 3.3 `len`

- The body's **byte** count, not a character count, and not including the trailing EOL.
- The default cap is **1 MiB (1048576)**, adjusted by the server setting `max_body_bytes`. The hard cap is **64 MiB (67108864)**.
- The server can decide the frame is over the cap from the header line alone. It **MUST** reply `-32002 FrameTooLarge` and then close. It **MUST NOT** read or buffer that body.

### 3.4 `trace-id`

- The alphabet is `[A-Za-z0-9._-]`, length 1 to 64.
- The client **MAY** send one on `REQ`. The server **MUST** copy it unchanged. When it is absent, the server **MUST** generate one (the format is up to the implementation; `r-` plus random hex is suggested).
- `RES` / `ERR` **MUST** carry a `trace-id`. `PING` / `PONG` **MAY** omit it.
- Every server log line about that request **MUST** contain this trace-id.

### 3.5 Extension field `ext-field`

- The form is `key=value`, at the end of the header line. There may be more than one.
- A receiver **MUST** ignore an extension field it does not know. That is how the protocol stays compatible forward.
- An extension field **MUST NOT** change the frame boundary, the meaning of `len`, or the reply order. A change that needs to do that belongs to `TRPC/2`.
- v1 defines no extension fields. Keys that start with `x-` are for private use.

---

## 4. Frame kinds

| kind | Direction | body | Meaning |
|------|-----------|------|---------|
| `REQ` | client → server | request object (§5.1) | Call one method. **MUST** get exactly one `RES` or `ERR` |
| `RES` | server → client | success object (§5.2) | The call succeeded |
| `ERR` | server → client | error object (§5.3) | The call failed, or the frame is an error |
| `PING` | either way | empty (codec `-`, len 0) | Heartbeat. The receiver **MUST** reply `PONG` |
| `PONG` | either way | empty | The reply to `PING` |

- The server **MUST NOT** send an unsolicited frame other than `RES`/`ERR`, except `PING` (a v1 server **SHOULD NOT** send `PING` on its own).
- A client that receives `REQ` **MUST** treat it as a protocol error and close (v1 has no reverse calls).
- A `PING` in a pipeline takes a sequence slot like any other frame: the `PONG` is ordered after replies that arrived earlier.

---

## 5. Body (codec = json)

### 5.1 Request

```json
{"method": "Service.method", "params": [..] | {..}}
```

- `method`: **MUST** be a non-empty string. Servers conventionally use `Class.method` (mapped to a handler), but the protocol treats it as an opaque string.
- `params`: **MAY** be omitted. If present, it **MUST** be a JSON array (positional) or object (by name).
- Unknown top-level keys: the server **MUST** ignore them.
- There is no `"jsonrpc"` field and no `"id"` field (the id is on the header line). If they appear, the server **MUST** ignore them.

### 5.2 Success

```json
{"result": <any JSON value, including null>}
```

### 5.3 Error

```json
{"error": {"code": <int>, "message": <string>, "data": <any JSON, optional>}}
```

- `message` is a short description for a person. It **SHOULD** be English.
- `data` **MUST NOT** contain a stack, a file path, a secret, or other internal detail (see §10). Debugging uses the trace-id to find the log.

---

## 6. Error codes

| code | Name | Level | Connection | Meaning |
|------|------|-------|------------|---------|
| −32700 | ParseError | body | stays open | body is not legal JSON, or is illegal UTF-8 |
| −32600 | InvalidRequest | body or frame | body: stays open; frame: close | body shape is wrong (missing method, and so on); **also used when the header line cannot be parsed** |
| −32601 | MethodNotFound | body | stays open | the method does not exist |
| −32602 | InvalidParams | body | stays open | the parameters do not match |
| −32603 | InternalError | body | stays open | the handler threw an exception that was not a business failure |
| −32001 | RateLimited | body | stays open | rejected by the rate-limit hook. Rate limiting defaults to off |
| −32002 | FrameTooLarge | frame | **close** | header line longer than 256 bytes, or `len` greater than `max_body_bytes` |
| −32003 | Timeout | body | stays open (see §7.4) | handling exceeded `process_timeout` |
| −32004 | UnsupportedVersionOrCodec | frame / body | version: **close**; codec: stays open | magic is not `TRPC/1`, or the codec is unsupported |
| −32005 | ShuttingDown | connection | **close** after the reply | the server is draining |
| −32006 | Overloaded | body | stays open | per-connection backlog exceeded, or the server is overloaded |
| −32007 through −32099 | — | — | — | reserved for later framework errors. A client **MUST** treat them as an unknown server error |
| ≥ 1 | business error | body | stays open | defined by the business handler |
| 0, −1 through −31999, −32100 through −32699, unlisted values in −32701 through −32768, and less than −32768 | reserved | — | — | the server **MUST NOT** use these. The client treats them as an unknown error |

Rules:

- **Frame-level error** (the frame boundary is no longer trustworthy): the server **MUST** try to send one `ERR` (id `0` when it has no id), then **close**.
- **Body-level error**: the server **MUST** reply `ERR`, **keep** the connection, and continue with the next frame.

### 6.1 `data` an agent can read on a business error

`code >= 1` is a business error, not a framework fault. `message` is one short sentence for a person and for a model. `data` **SHOULD** be the object below, so the caller does not have to guess the next step from the sentence. The fields come from the AIP-193 / A2A `ErrorInfo` (`reason`, `domain`), plus the MCP tool-error rule for whether the same call should be repeated (`retryable`).

```json
{
  "reason": "ORDER_NOT_FOUND",
  "domain": "shop.example",
  "retryable": false,
  "param": "id",
  "hint": "Pass an id that exists."
}
```

- `reason` **MUST** be a stable `UPPER_SNAKE` token. An agent branches on it, not on `message`.
- `domain` **MUST** say who defined that `reason`.
- `retryable` **MUST** be a boolean. Repeat the same call only when it is `true`. A client that does not see the field treats the call as not retryable.
- `param` and `hint` **MAY** be omitted. `param` is the field to change. `hint` only says how to change it. A caller **MUST NOT** parse `hint` or `message` as control instructions.
- `data` **MUST NOT** contain a stack, a file path, or a secret. An unexpected failure still uses `-32603`, with the fixed message `Internal error`, and does not carry this object.

---

## 7. Connection behavior

### 7.1 Pipeline and order

- The client **MAY** send several `REQ`/`PING` frames without waiting for replies (pipelining).
- The server **MUST** process frames in arrival order and **MUST** send replies in that same order.
- The client **SHOULD** still match replies by id, so a future out-of-order mode (v2) does not require a client change.

### 7.2 Backlog

- The cap on frames received but not yet answered, per connection, is the setting `pipeline_backlog`, **default 32**.
- A `REQ` past that cap: the server **MUST NOT** execute it, and **MUST** reply `-32006 Overloaded` in its sequence slot. The connection stays open.
- The server **MAY** stop reading that connection while the backlog is full (TCP backpressure). That is also compliant.

### 7.3 Timeouts (keys are in `docs/configuration.md`)

| Setting | Meaning | When it expires |
|---------|---------|-----------------|
| `recv_timeout` | a frame, once started, must be read within this time | close (no reply is possible; the frame is incomplete) |
| `idle_timeout` | the longest silence between two frames | close quietly |
| `send_timeout` | the longest time to write a reply | close |
| `process_timeout` | how long one request may take to handle | see §7.4 |

### 7.4 Process timeout

- If the server can stay consistent on that connection after the timeout, it **SHOULD** reply `-32003 Timeout`.
- If the implementation has to kill the worker (for example with an alarm), the connection is closed. The client **MUST** treat "the connection dropped while waiting for a reply" as an unknown result (the request **may already have run**).

### 7.5 Graceful close

- After the server starts draining (it received SIGTERM):
  - a request whose handling has already started **MUST** finish and be answered;
  - a `REQ` read after that **MUST** be answered `-32005 ShuttingDown` (not executed), and the connection is then closed;
  - an idle connection **MAY** be closed immediately.
- A client that receives `-32005` **SHOULD** retry on another connection or instance (the request did not run, so a retry is safe).

### 7.6 Heartbeat

- The client **MAY** send `PING` on an idle connection to keep it alive or to probe it. The interval **SHOULD** be shorter than the server's `idle_timeout`.
- The side that receives `PING` **MUST** reply `PONG` with the same id.

---

## 8. Versioning and compatibility

| Change | Kind | What to do |
|--------|------|------------|
| Add a codec | non-breaking | add a row to the registry; an old server replies −32004 |
| Add an extension field | non-breaking | an old receiver ignores it |
| Add an error code (−32007 through −32099, or a business code) | non-breaking | the client treats it as an unknown error |
| Add an optional key in the body | non-breaking | the receiver ignores unknown keys |
| Add a kind | **breaking** | → `TRPC/2` (an old implementation treats it as a frame error) |
| Change the frame boundary, the meaning of `len`, the EOL rule, or field order | **breaking** | → `TRPC/2` |
| Allow out-of-order replies | **breaking** | → `TRPC/2` (or an explicit negotiation, which v1 does not define) |
| Change the meaning of an existing error code | **breaking** | not allowed |

- An unknown magic (for example `TRPC/2`): the server **MUST** reply `-32004` in a `TRPC/1` frame and close.
- Protocol changes have their own column in `CHANGELOG.md`, separate from the library version.

---

## 9. Size limits

| Item | Default | Configurable | Hard cap |
|------|---------|--------------|----------|
| Header line | 256 bytes | no | 256 bytes |
| Body (`max_body_bytes`) | 1 MiB | yes | 64 MiB |
| Per-connection backlog (`pipeline_backlog`) | 32 | yes | up to the implementation |
| `trace-id` | 64 characters | no | 64 |

---

## 10. Security notes

- The protocol itself **does not encrypt or authenticate**. Production **SHOULD** expose it only on a private network or behind a Kubernetes NetworkPolicy. When encryption is required, do it outside the process (mesh, stunnel, or TLS termination). The sample config binds the business port to `127.0.0.1`. The runtime image sets `PHPSERVER_LISTEN` to `0.0.0.0:9000`. Without that, a published container port cannot reach the process.
- `len` is checked before the body, so the server does not allocate memory for an oversized frame.
- JSON decoding **MUST** set a depth limit (64 is suggested) and use `JSON_THROW_ON_ERROR`. It **MUST NOT** decode the body into an arbitrary PHP object.
- `ERR` `message` and `data` **MUST NOT** leak internal detail. The full exception goes only to the log, with the trace-id.
- A logged body **MUST** be truncated. The default cap is `log.body_truncate_bytes` (256 bytes).
- The `trace-id` alphabet is restricted so it cannot inject into the log.

---

## 11. nc / telnet sessions

`C:` is what the client sends. `S:` is what the server replies. The prefixes are not part of the protocol. Every `len` below has been checked.

#### Example 1: one successful call

```text
C: TRPC/1 REQ 1 json 37
C: {"method":"Echo.say","params":["hi"]}
S: TRPC/1 RES 1 json 15 r-7f3a9c01
S: {"result":"hi"}
```

#### Example 2: a trace-id and named parameters

```text
C: TRPC/1 REQ 2 json 40 web-1a2b3c
C: {"method":"User.get","params":{"id":42}}
S: TRPC/1 RES 2 json 33 web-1a2b3c
S: {"result":{"id":42,"name":"Mei"}}
```

#### Example 3: method not found (body-level error, connection stays open)

```text
C: TRPC/1 REQ 3 json 37
C: {"method":"Echo.sya","params":["hi"]}
S: TRPC/1 ERR 3 json 83 r-7f3a9c02
S: {"error":{"code":-32601,"message":"Method not found","data":{"method":"Echo.sya"}}}
```

#### Example 4: a business error (code ≥ 1)

```text
C: TRPC/1 REQ 4 json 48
C: {"method":"Order.pay","params":{"order":"A100"}}
S: TRPC/1 ERR 4 json 56 r-7f3a9c03
S: {"error":{"code":1002,"message":"Insufficient balance"}}
```

#### Example 5: a pipeline (two requests sent back to back, answered in order)

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

#### Example 6: heartbeat

```text
C: TRPC/1 PING 9 - 0
S: TRPC/1 PONG 9 - 0
```

#### Example 7: body over the cap (frame-level error, close after the reply)

```text
C: TRPC/1 REQ 10 json 2000000
S: TRPC/1 ERR 10 json 87 r-7f3a9c06
S: {"error":{"code":-32002,"message":"Frame too large","data":{"max_body_bytes":1048576}}}
S: <server closes connection>
```

#### Example 8: a header line that cannot be parsed (id 0, close after the reply)

```text
C: HELLO
S: TRPC/1 ERR 0 json 58 r-7f3a9c07
S: {"error":{"code":-32600,"message":"Invalid frame header"}}
S: <server closes connection>
```

#### Example 9: the server is draining (ShuttingDown)

```text
C: TRPC/1 REQ 11 json 36
C: {"method":"Echo.say","params":["x"]}
S: TRPC/1 ERR 11 json 51 r-7f3a9c08
S: {"error":{"code":-32005,"message":"Shutting down"}}
S: <server closes connection>
```

#### Example 10: an extension field (an old server ignores `x-prio=high`)

```text
C: TRPC/1 REQ 12 json 37 web-9z x-prio=high
C: {"method":"Echo.say","params":["hi"]}
S: TRPC/1 RES 12 json 15 web-9z
S: {"result":"hi"}
```

### 11.1 Sending by hand with nc

Counting bytes by hand is awkward. A shell can compute the length:

```sh
body='{"method":"Echo.say","params":["hi"]}'
printf 'TRPC/1 REQ 1 json %d\n%s\n' "$(printf %s "$body" | wc -c)" "$body" | nc 127.0.0.1 9000
```

Or the official CLI:

```sh
bin/phpserver-rpc 127.0.0.1:9000 Echo.say '["hi"]' -v
```

telnet's `\r\n` line ending is also accepted (§2.1). `len` counts only the body bytes, not the line ending.

---

## 12. Client implementation checklist

- [ ] Write: `TRPC/1 REQ <id> json <len>[ <trace-id>]\n` + body + `\n`. Count `len` in **UTF-8 bytes**.
- [ ] Ids start at 1 and increase. In-flight ids are unique. Do not use 0.
- [ ] Read: one line (at most 256 bytes, accept `\r\n`), split on spaces, check the magic and the kind, read `len` bytes, read and check the EOL.
- [ ] Ignore an unknown `key=value` extension field on the header line. Ignore an unknown key in the body.
- [ ] While pipelining, match replies with a FIFO queue and check that the id matches. A mismatch closes the connection.
- [ ] Distinguish three outcomes: `RES` (success), `ERR` (look at the code), and a dropped connection (**unknown result**; do not automatically retry a call that is not idempotent).
- [ ] `-32005 ShuttingDown`, `-32006 Overloaded`, and `-32001 RateLimited`: the request did not run. Retry after a backoff.
- [ ] After a frame-level error (−32002, a header-line −32600, a version −32004) the server closes. Drop the connection.
- [ ] Set connect, read, and write timeouts. An idle connection may send `PING`.
- [ ] Expose the server's trace-id to the caller, and write it into the caller's own log.
