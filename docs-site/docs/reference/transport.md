---
title: "HTTP/2 transport (Core\\Transport\\Curl)"
description: "CurlTransport implements Core\\Transport\\Transport on curl_multi. This page records how it behaves, why, and the measurements behind each choice."
---

# HTTP/2 transport (`Core\Transport\Curl`)

`CurlTransport` implements `Core\Transport\Transport` on `curl_multi`. This page records how it behaves, why, and the measurements behind each choice. Every number below was measured in the project container: **PHP 8.3.6, libcurl 8.5.0 (nghttp2), OpenSSL 3.0.13, Node 22**, against the mock server in `tests/Support/H2Mock/` on loopback. Behaviour pinned by tests is marked with the test file.

## Files

| File | Role |
|---|---|
| `CurlTransport` | the `Transport`: sliding window, lazy input, one Exchange per request |
| `CurlPool` | one persistent `CurlMultiHandle` per pool key (its connection cache *is* the pool), easy-handle recycling, routing of completions between concurrent `send()` generators |
| `CurlPoolRegistry` | per-process registry: global limit + LRU eviction, idle expiry, controlled close, fork guard |
| `ConnectionReuse` | `auto` / `on` / `off`: whether a `send()` uses the registry's persistent pools or a private pool of its own |
| `CoroutineCurlHook` | detects a coroutine runtime that hooks curl (Swoole / OpenSwoole); drives `auto` |
| `CurlCapabilities` | runtime probe; throws `TransportConfigurationException` with a remedy before anything is sent |
| `CurlErrorClassifier` | libcurl error + counters → `TransportFailure` with honest evidence |
| `ResponseHeaderParser` | `CURLOPT_HEADERFUNCTION` parser (interim 1xx, proxy CONNECT block, lowercased names) |

## Usage

```php
$transport = new CurlTransport();                       // shares CurlPoolRegistry::process()
$profile = new ConnectionProfile(poolKey: 'apns|team:ABC|key:XYZ|production|gen:3', window: 100);

foreach ($transport->send($lazyRequests, $profile) as $requestId => $exchange) {
    // exactly one Exchange per Request, in completion order
}
$transport->close('apns|team:ABC|key:XYZ|production|gen:3'); // or close() for every pool
```

`send()` validates the runtime and the profile **at call time** (it is not itself a generator), so a `TransportConfigurationException` is thrown before any request is pulled from the input.

## Guarantees

- **One Exchange per request.** Per-request problems are Exchanges, never exceptions: invalid header names or values (CR/LF/NUL), invalid methods, `http://` URLs (only `https` is allowed via `CURLOPT_PROTOCOLS`), `curl_multi_add_handle` failures. Locally refused requests are `NotSent` with `errno` 0 (or the libcurl code).
- **Early exit is safe.** If the consumer stops iterating, the generator's `finally` removes every in-flight easy handle (libcurl resets those HTTP/2 streams with `RST_STREAM`, the connection survives) and releases the pool. Pinned by `ConnectionPoolTest` ("cleans up when the consumer stops...").
- **Lazy input, flat memory.** Requests are pulled only while the window has room. 20,000 requests: memory growth after warm-up < 512 KiB; 100,000 requests (group `slow`): same ceiling (`LargeBatchTest`).
- **Concurrent sends on one pool** (a consumer starting a second `send()` while iterating the first) share the multi handle; completions are routed to the generator that owns each easy handle.
- **TLS verification is always on** (`VERIFYPEER`, `VERIFYHOST=2`); there is no option to disable it.
- **Proxy is explicit.** `CURLOPT_PROXY` is always set (empty string when `$proxy` is null) and `CURLOPT_NOPROXY` is empty, so `http(s)_proxy` / `no_proxy` environment variables never change where pushes go. (Measured: without this, the container's `HTTPS_PROXY` hijacked a DNS-failure test and `NO_PROXY=localhost` bypassed an explicit proxy.)
- **Response bodies are capped** at `CurlTransport::MAX_RESPONSE_BYTES` (8 MiB); larger ones abort as `Unknown`.

## Investigation results (PLAN §4.1 / §13 open items)

### libcurl re-sends requests by itself

`Curl_retry_request()` (libcurl 8.5 `lib/transfer.c`) silently re-runs a transfer, on a fresh connection, up to `CONN_MAX_RETRIES` = 5 times, when no response byte was received and either:

1. the stream was refused: `RST_STREAM(REFUSED_STREAM)`, **or** the stream id was above the `GOAWAY` last-stream-id (to our reading nghttp2 closes those streams with `REFUSED_STREAM`; either way the measured effect is a re-send); or
2. the connection was a *reused* one (`conn->bits.reuse`, true for every connection that carried more than one transfer, i.e. any multiplexed connection) and it failed with `CURLE_RECV_ERROR`.

Measured with an in-memory body (`CURLOPT_POSTFIELDS`), raw `curl_multi` (`LibcurlBehaviourTest`):

| Server action | What PHP sees | What the server saw |
|---|---|---|
| `REFUSED_STREAM` once | `errno 0`, HTTP 200, `REQUEST_SIZE` doubled (131 → 262) | request received **twice**, processed once |
| `REFUSED_STREAM` always | `errno 56` "Connection died, tried 5 times before giving up" | received **6 times** |
| `GOAWAY(last-stream-id)` below 5 in-flight streams | all `errno 0`, HTTP 200 | each of the 5 received twice, processed once, on a 2nd connection |
| **request processed, then connection destroyed** before the response | one transfer `errno 0`, HTTP 200 | that request **processed twice** (a duplicate push) |

The last row is the dangerous one: case 2 fires for a request the server *did* process when the response is lost with the connection. libcurl has no option to disable this. PHP 8.3 sets no `CURLOPT_SEEKFUNCTION`, so:

**Decision: request bodies are streamed with `CURLOPT_READFUNCTION` (and `CURLOPT_UPLOAD` + `CURLOPT_CUSTOMREQUEST` + `CURLOPT_INFILESIZE`) and no seek callback.** When libcurl wants to re-send, it must rewind the body, cannot, and fails the transfer with `CURLE_SEND_FAIL_REWIND` (65) after opening the new connection but before writing anything on it. Measured with this mode: the refused stream, the GOAWAY'd streams and the "processed then dropped" request are each received exactly once; their Exchanges are failures (`errno 65` "necessary data rewind wasn't possible", or `errno 65` with the first error, e.g. "Recv failure: Connection reset by peer"). Pinned by `ResponseLostTest`, `StreamRefusalTest`, `LibcurlBehaviourTest`.

Limits of the protection: a request **without a body** (GET/DELETE/empty POST) has nothing to rewind and may still be re-sent by libcurl (≤ 5 times). Use bodiless methods only for idempotent calls (channel list/delete are idempotent). `CURLINFO_SIZE_UPLOAD_T` is reset by an internal retry (measured `up=0` after the blocked re-send), so it is never used as proof; `CURLINFO_REQUEST_SIZE` is cumulative across retries (131 → 262 → 786 over 3 attempts).

### Can REFUSED_STREAM / GOAWAY be promoted to NotSent? **No.**

- PHP exposes no HTTP/2 frame information: no error code, no last-stream-id, no CURLINFO for it (`CURLOPT_DEBUGFUNCTION` only exists from PHP 8.4, and libcurl's `REFUSED_STREAM` path only writes a trace, not `failf()`).
- With in-memory bodies they are invisible (`errno 0`, the retry succeeds) or surface as `errno 56` "Connection died, tried 5 times", the same text a reused connection that died after processing produces.
- With streamed bodies they surface as `errno 65` with "necessary data rewind wasn't possible". A reused connection that closed cleanly (FIN with empty buffers, `ctx->conn_closed` path in `http2.c`) after the server processed the request takes the same retry path and produces the same errno and the same message. The difference between the two is only in libcurl's internal state.

So the binding does not tell them apart reliably from a dead connection that may have processed the request. **They stay `Unknown`.** (Protocol-wise RFC 9113 §8.7 guarantees those streams were not processed; the evidence is lost in the binding, not in the protocol.) Revisit if the package raises its minimum to PHP 8.4 (`CURLOPT_DEBUGFUNCTION` shows the frames) or if libcurl exposes the refused-stream state.

### Proof of NotSent

`CurlErrorClassifier::classify()` returns `NotSent` only when **all** hold:

1. the errno belongs to a phase that precedes the request: resolve (5, 6), connect (7), TLS handshake/verification (35, 53, 54, 58, 59, 60, 64, 66, 77, 82, 83, 90, 91, 98), proxy handshake (97), unsupported protocol / malformed URL (1, 3), or timeout (28);
2. `CURLINFO_REQUEST_SIZE` is 0: libcurl adds every request byte the connection filters accepted (`Curl_buffer_send`), cumulatively across internal retries;
3. `CURLINFO_PRETRANSFER_TIME_T` is 0: no attempt reached the DO phase, where the request is generated; the timer is not reset by an internal retry;
4. our read callback never handed body bytes to libcurl and no response byte arrived.

Why the counters and not the errno alone: measured `errno 7` "Couldn't connect" with `REQUEST_SIZE` 131 when libcurl's own retry of an already-written request failed to reconnect. With the rule above that is `Unknown`.

Measured outcomes (`NotSentProofTest`, `MutualTlsTest`):

| Situation | errno | Evidence |
|---|---|---|
| nothing listening | 7 | NotSent |
| server certificate from an untrusted CA | 60 | NotSent |
| TCP accepted, TLS handshake never answered (connect timeout 300 ms) | 28 | NotSent |
| request written, server never answers | 28 | Unknown |
| processed, connection destroyed before the response | 55 / 56 / 65 | Unknown |
| response cut in the middle of the body | 18 | Unknown |
| mTLS server, client sends no certificate (TLS 1.3) | 56 "tlsv13 alert certificate required" | **Unknown**: with TLS 1.3 the client writes the request before the server's alert arrives (`REQUEST_SIZE` 53, `PRETRANSFER` > 0); the server's application never saw it |
| `http://` URL, CR/LF in a header, invalid method | 1 / 0 | NotSent (refused locally) |

### `CURLOPT_MAXAGE_CONN` default

**118 seconds** (`url.c`: `set->maxage_conn = 118`). Measured: an idle connection was reused after 110 s and replaced by a new one after 125 s. The transport sets it explicitly to `ConnectionProfile::$maxIdleSeconds`, the same value the registry uses for pool expiry, so the two notions of "idle too long" agree.

### `curl_upkeep()`

**No effect for this transport.** `curl_easy_upkeep()` only walks `data->multi_easy` (the private multi of `curl_exec()` handles) and returns immediately for handles used with `curl_multi`; in 8.5 the HTTP(S) protocol handlers also have no `connection_check`, so even on a `curl_exec()` handle no HTTP/2 PING is sent. Measured: 0 PINGs counted by the server in both cases, while a Node client's PING was counted. Liveness therefore relies on TCP keepalive (`CURLOPT_TCP_KEEPALIVE`, idle 30 s, interval 15 s), libcurl's dead-connection check before reuse, and `MAXAGE_CONN`.

### Cancelled streams

Removing an in-flight easy handle makes libcurl queue `RST_STREAM`; measured on 8.5, the frame may stay queued until the connection's next egress (the server kept answering cancelled streams when nothing else was sent). The transport runs one non-blocking `curl_multi_exec` after aborting; the reset reliably reaches the server with the next `send()`.

## HTTP/2 required, peer speaks HTTP/1.1

ALPN cannot be restricted to `h2` from PHP: measured, even `CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE` over TLS offers `http/1.1` and silently downgrades. Behaviour when `$requireHttp2` is true:

- on a pool whose negotiated protocol is not yet known, the first request is sent **alone** (a pilot);
- if it was answered over HTTP/1.x, its Exchange is a failure, `Unknown` (it was sent and the peer answered; the response is discarded), message prefix `CurlTransport::HTTP2_REQUIRED`;
- the remaining requests of that `send()` are **not dispatched**: `NotSent`, `requestWritten = false` (provable: never handed to libcurl);
- the pool is closed, so the next `send()` negotiates again.

Cost: one extra round trip at pool start-up. With `$requireHttp2 = false` the HTTP/1.1 response is returned normally (`Response::$httpVersion = '1.1'`). `HttpVersion::Http11` profiles (OAuth token endpoints, plain HTTPS APIs) use keep-alive connections, at most `$maxConnections`, and cap the window at `$maxConnections` so that no request waits inside libcurl with its total timeout running (`ProtocolNegotiationTest`).

## Window, connections and refused streams

- `$window` caps the requests the transport holds in flight per `send()`; `CURLMOPT_MAX_CONCURRENT_STREAMS` is set to it as well. The peer's `SETTINGS_MAX_CONCURRENT_STREAMS` can only lower the effective concurrency: libcurl then queues the extra transfers internally (measured: peer limit 5, window 100 → never more than 5 streams, still one connection). Queued transfers' timeouts are running, so keep `$window ≤ peer limit × $maxConnections`. A transfer that times out while queued is `NotSent` (no request byte, no pretransfer).
- `CURLMOPT_MAX_HOST_CONNECTIONS` / `MAX_TOTAL_CONNECTIONS` / `MAXCONNECTS` = `$maxConnections`, `CURLOPT_PIPEWAIT` on, `CURLPIPE_MULTIPLEX` on. With peer limit 100, window 500 and 4 connections, libcurl opened exactly 4 connections.
- **Refused streams:** a failure with errno 65 (libcurl wanted to re-send) halves the window for the rest of the `send()` (minimum 1); each success adds 1 back up to `$window` (AIMD). libcurl itself stops using a connection after a refused stream and opens a new one; note that each blocked re-send still costs that new TLS handshake.
- Never `FORBID_REUSE`/`FRESH_CONNECT`; easy handles are recycled with `curl_reset()` (up to 256 spares per pool).

### Measured throughput (loopback, one connection unless noted, 200-byte bodies)

| window | server delay | req/s | max streams seen by server |
|---|---|---|---|
| 1 | 0 | 5,000 | 1 |
| 10 | 0 | 15,300 | 10 |
| 50 | 0 | 25,400 | 49 |
| 100 | 0 | 25,400 | 88 |
| 500 | 0 | 24,700 | 429 |
| 1000 | 0 | 20,500 | 886 |
| 10 | 20 ms | 460 | 10 |
| 100 | 20 ms | 4,260 | 100 |
| 500 | 20 ms | 13,000 | 500 |
| 500, peer limit 100 | 20 ms | 4,190 | 100 |
| 500, peer limit 100, 4 connections | 20 ms | 12,900 | 100 per connection |

With zero latency PHP is CPU-bound at ~25k req/s from window 50 on; with latency, throughput ≈ window / RTT until the peer limit. Real APNs/FCM latency (tens of ms) is what makes the window matter.

### Recommended defaults

- `window = 100`, `maxConnections = 1` (the `ConnectionProfile` defaults): ~4k req/s per pool at 20 ms RTT, well under typical peer stream limits, and a GOAWAY/connection loss puts at most 100 requests in `Unknown`.
- Large campaigns: raise `maxConnections` to 2–4 before raising the window above the peer limit; keep `window ≤ peer limit × maxConnections`.
- Keep `timeoutMs` well above RTT × (window / peer limit) when the window exceeds the peer limit.
- These are loopback numbers; confirm against the real providers in the M9 benchmarks before raising defaults.

## Pool registry

- One `CurlMultiHandle` per pool key. Different keys never share a connection, even to the same host and port (`ConnectionPoolTest`: two keys → two server-side sessions).
- `CurlPoolRegistry::process()` is the shared per-process registry; pass your own to isolate (tests, Octane workers). In Laravel, `transport.max_pools` set to its default (32) keeps the shared registry; any other value makes the `Transport` singleton use a **dedicated registry** of that size, whose pools are not shared with other `CurlTransport` instances of the process. Raise it when one process serves many distinct connections (multi-tenant); only 32 has been measured.
- Global limit (default 32, `transport.max_pools` in the Laravel config, env `MOBILE_PUSH_TRANSPORT_MAX_POOLS`, at least 1) with LRU eviction; the evicted pool is closed (server sees the connection close). Pools in use by a running `send()` are never evicted; the registry may exceed the limit only while more sends than the limit run at once.
- Idle expiry: a pool unused for more than its `$maxIdleSeconds` is closed on the next registry access.
- `close($key)` on a pool in use detaches it (new sends get a new pool) and closes it when its last send finishes.

### Connection reuse: `on`, `off`, `auto`

`new CurlTransport($registry, $capabilities, reuse: ConnectionReuse::…)`; in Laravel `mobile-push.transport.reuse` (`auto` by default). The mode is decided **once per `send()`, when `send()` is called**, and never changes half-way through that send.

- **`on`**: today's behaviour above. Every `send()` of a pool key uses the registry's persistent pool; connections are kept between sends, and concurrent sends of one key share one `curl_multi` handle.
- **`off`**: every `send()` builds its **own private pool** (its own `curl_multi` handle and connections) when its generator starts running, and closes it in the generator's `finally` block: when the generator is exhausted, when the consumer stops iterating, or when the generator is destroyed (including a suspended Fiber that is destroyed). The registry is never touched, nothing is kept between sends, and two concurrent sends never share a handle. A generator that is never iterated opens nothing. `close()` only closes registry pools; a running private pool ends with its send. `CurlTransport::privatePoolsOpen()` (open now) and `privatePoolsOpened()` (since construction) count them.
- **`auto`** (the default of the constructor and of the Laravel setting): `on`, except for a send that starts while `CoroutineCurlHook` reports a curl hook, which runs as `off`. The facts are read again at every send: `extension_loaded('swoole' | 'openswoole')`, then `Swoole\Runtime::getHookFlags()` (OpenSwoole: `OpenSwoole\Runtime::getHookFlags()`, then the `Swoole\Runtime` alias) through a dynamic call, masked with `SWOOLE_HOOK_CURL` (2048) and `SWOOLE_HOOK_NATIVE_CURL` (4096), plus the value of those constants (and of `OpenSwoole\Runtime::HOOK_CURL` / `HOOK_NATIVE_CURL`) when they are defined. An extension that is loaded but whose flags cannot be read counts as hooked. Nothing is imported; Swoole is not a dependency.

Why: under a curl coroutine hook a coroutine can be switched out *inside* `curl_multi_exec()`, and a second coroutine of the same worker then drives the same shared `curl_multi` handle (measured under Octane on Swoole 6.2: a fatal error in `curl_multi_exec`, the worker dies). With a private pool per send that handle is never shared: in the same CI job with `auto` the transport never failed and the mock counted one connection per send. That does not make the runtime safe: some concurrent requests still failed in Octane's shared application container (see [runtimes](/operations/runtimes)).

**Cost of `off`**: every send opens a new connection (TCP + TLS handshake) and closes it at the end. Measured on loopback against the mock: 200 sends of one request each → **200 connections** with `off` and **1** with `on`; about **3.1 ms** per send with `off` against **0.6 ms** with `on` (loopback, no network latency: over a real network each handshake adds round trips on top). With `requireHttp2` (the default) a new pool also sends its first request alone before filling the window (the pilot described above), once per send. Against APNs many short connections are a risk in themselves: Apple's documentation asks providers to keep connections open rather than opening one per notification and may treat rapid connect/disconnect as a denial-of-service attempt; how Apple reacts to a given rate was **not** measured here. Prefer `off`/`auto` only where reuse is not safe, and send in batches (one send, many requests) so that one connection carries many pushes.

Pinned by `ConnectionReuseTest` (scenarios on the mock: N sends → N connections, each closed; abandoned and never started generators leave nothing open; two interleaved Fiber sends of one pool key on two distinct server-side sessions, each receiving only its own answers; a suspended send released when its Fiber is destroyed; `auto` with a simulated hook, and a hook turned on mid-process) and `CoroutineCurlHookTest` (the detection with injected facts).

### Fork safety

On `getmypid()` change the registry abandons every inherited pool: it is never reused and never closed by the child (the child opens its own connections — measured, distinct server-side sessions). Closing would make libcurl `SSL_read()` from, and write a TLS `close_notify` to, a socket the parent still uses (`ossl_close()` in 8.5).

**Residual risk (measured):** when a forked child **exits normally**, PHP's shutdown frees the inherited handles anyway and libcurl closes them; the parent's pooled connection is then closed by the server (3 connections instead of 2 in the experiment). The parent recovers transparently on its next `send()` (dead connection detected, fresh connect), but requests in flight on that connection at that moment would come back `Unknown`. Avoid sending before forking; a child that must exit without touching inherited connections can end with `posix_kill(getmypid(), SIGKILL)` (as the test does). Laravel queue workers and Horizon start workers as new processes (Symfony Process / `proc_open`), not with `fork`, so they are not affected: read in Laravel's source, and the real `queue:work` loop and real forked children were exercised afterwards, and Horizon is run in CI on a real Redis (see [runtimes](/operations/runtimes)).

## Mock server (`tests/Support/H2Mock`)

`server.mjs` (Node built-ins only) + `MockServer.php`. Certificates (CA, server with SAN `localhost`/`127.0.0.1`, optional client cert) are generated at runtime with PHP's openssl functions into a private temp dir. Modes: `h2`, `h2+h1`, `h1`; optional mTLS; optional CONNECT proxy; configurable `maxConcurrentStreams`. A plain-HTTP control port scripts ordered rules (method, path regex, `matchHeaders`, `afterRequests`, `times`) → actions `respond` (status, headers, body, delay), `drop` (processed, then the TCP connection is destroyed with no response), `refuse` (`REFUSED_STREAM`), `goaway` (last-stream-id `previous`/`current`/number), `hang`, `close-mid-body`. Counters are kept by the server: connections, sessions, received, processed, responded, refused, GOAWAYs, PINGs, client resets, max concurrent streams per session, recorded requests (capped). Note: streams opened after the server sent GOAWAY with a lower last-stream-id are discarded inside Node's nghttp2 and never reach the application, so they are not counted as received.

---

*This page is generated from [`docs/transport.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/transport.md). To change it, edit that file in the repository, not this site.*
