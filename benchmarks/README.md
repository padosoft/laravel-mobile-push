# Benchmarks

Reproducible benchmarks for `padosoft/laravel-mobile-push`. The rule behind them: **no performance or comparison claim about this package is published without numbers that anyone can regenerate**, with payloads, batch sizes, concurrency, versions and hardware stated, warm and cold runs kept apart, and latency percentiles, peak memory, errors and connection counts reported.

Everything runs against the local HTTP/2 mock server in `tests/Support/H2Mock` (Node.js, TLS, loopback). **No real provider (APNs, FCM) is ever contacted.** The results say how the PHP side behaves against a fast, scriptable peer; they say nothing about real provider throughput.

## Requirements

- the package's dev dependencies installed (`composer install`), PHP 8.3+, libcurl with HTTP/2, `ext-openssl`
- Node.js 18+ on `PATH` (the mock server)
- for `compare-pushok`: `edamov/pushok` (a dev dependency of this repository)
- Linux (`/proc/cpuinfo`, `/proc/self/fd` are used for the hardware description and file-descriptor counts)

For meaningful numbers use an idle machine, note the load average (printed at the start of every run) and prefer `--repeat=5` or more. Every table reports the min-max range of the repeated runs; if a figure varies by more than about 15% between runs the output flags it (`SPREAD n%` on the console, `spread n%` in `RESULTS.md`) and the range is the result, not the median.

## Running

```bash
php benchmarks/run.php --scenario=transport-warm --count=20000 --window=100 --repeat=5
php benchmarks/run.php --scenario=window-sweep --json --out=benchmarks/results/window-sweep.json
php benchmarks/run.php --scenario=pipeline-apns,pipeline-fcm --count=10000 --server-delay-ms=20
php benchmarks/run.php --help

benchmarks/run-all.sh [--million]      # every scenario with the parameters used for RESULTS.md (20-30 minutes)
php benchmarks/render-results.php     # results/*.json -> RESULTS.md
```

Options: `--scenario=` (comma separated, or `all`), `--count=`, `--window=`, `--connections=`, `--payload-bytes=`, `--server-delay-ms=`, `--repeat=`, `--peer-limit=`, `--cold`, `--million`, `--tenants=`, `--per-tenant=`, `--json` (JSON on stdout, tables on stderr), `--out=FILE`. Each scenario has its own defaults, listed below. Defaults for `--window` and `--connections` are the package defaults (100 and 1).

## Scenarios

| scenario | what it runs | defaults |
|---|---|---|
| `transport-warm` | `CurlTransport` alone, APNs-shaped POSTs (`/3/device/<64 hex>`, `apns-topic`, `apns-push-type`, bearer header, JSON body). One request is sent first so the connection exists; every run reuses the pool. | 20,000 requests, delay 0, 3 runs |
| `transport-cold` | Same, but every run gets a new `CurlPoolRegistry`: one new TCP + TLS + HTTP/2 connection per run. | same |
| `pipeline-apns` / `pipeline-fcm` | The whole `Sender`: compile, guards (rate limiter, throttle gate, breakers over in-memory stores), safe-mode check, transport, classification, retry planning, one `SendResult` per destination, report consumed as a cursor. Real compilers/classifiers/token providers (throw-away keys generated at runtime); the mock answers 200 with an `apns-id` header / an FCM message name. Add `--cold` for a new pool per run. | 10,000 destinations |
| `pipeline-mixed-outcomes` | The APNs pipeline with 95% accepted, 3% `410 Unregistered`, 1% `400 BadDeviceToken`, 1% `500` (selected by the first hex digit of the token), next to an all-accepted control with identical settings. | 10,000 destinations |
| `window-sweep` | Raw transport, warm, windows 10, 50, 100, 250, 500, one connection, server delay 20 ms. | 10,000 requests, delay 20 |
| `connections-sweep` | Raw transport, warm, window 500, 1/2/4 connections, with the mock advertising 1000 and then 100 concurrent streams per connection (`--peer-limit=N` for a single value). | 20,000 requests, delay 20 |
| `large-batch-memory` | The APNs pipeline over a **generator** of 100,000 destinations (`--million` adds 1,000,000; `--count=N` runs only N). `memory_get_usage()` is sampled about every 5% of the destinations; latency is sampled to keep the recorder small. | 1 run |
| `many-tenants` | 200 different pool keys (`--tenants`) used one after the other, 5 requests each (`--per-tenant`), once with the default registry limit (32) and once with limit 1000. Reports pools, evictions, open file descriptors, PHP memory and the server's open connections every 20 tenants, then the same after `close()`. | 3 runs |
| `compare-pushok` | Same N alert notifications through `edamov/pushok` and through this package (raw transport, pipeline warm, pipeline with a new pool per run), plus behaviour on failure situations. See below. | 5,000 notifications, delay 10, window 100, 5 measured rounds |
| `behaviour-pushok` | Only the behaviour part of the comparison. | - |

## What every number means

| field | meaning |
|---|---|
| requests | exchanges completed as seen by the client (retries done by this package would count again; libcurl-internal re-sends are invisible here and show up as `server.requests_received` > requests) |
| wall time, req/s | `hrtime` around the whole run (generating requests, sending, consuming results) |
| latency p50/p95/p99/max | per-request `Exchange::$durationMs` from the transport (request handed to libcurl until completion). It includes the server's artificial delay and any wait inside libcurl; with a window much larger than the server can handle it mostly measures queueing. Percentiles are nearest-rank over all (or, for large batches, sampled) requests. Not available for pushok (it exposes no durations). |
| peak memory | `memory_get_peak_usage()` after `memory_reset_peak_usage()`, minus `memory_get_usage()` at the start of the run. The latency recorder (about 16 bytes per request) is included; it is zero for the pushok comparison, where it is not used. libcurl's own C allocations are not part of PHP's memory counters. |
| memory growth | usage at the end minus at the start, raw and after `gc_collect_cycles()`. Garbage cycles awaiting the collector are counted in the raw figure. |
| cpu ms/req | user + system CPU time of the PHP process (`getrusage`) per request; the Node server is not included. Less sensitive to scheduling noise than wall time, but not immune. |
| transport errors | exchanges that carry a failure instead of a response |
| connections | TLS handshakes completed, **counted by the mock server**, for the run and since the case started (the warm-up connection included) |
| max concurrent streams | highest number of simultaneously open HTTP/2 streams the server saw on one connection since the server was last reset (cumulative over the case's runs) |
| fds | entries in `/proc/self/fd` before/after |
| warm / cold | warm: connection already established (one request sent and not measured), the registry is reused across runs. Cold: a new registry and pool for every run, so one TCP+TLS+HTTP/2 setup is inside the measured time. Both are in-process; PHP autoloading and opcache effects of a first process start are not part of "cold" (CLI, opcache off by default). |

## The pushok comparison

`compare-pushok` starts one mock server per variant (so counters never mix), sends the same notifications (alert title/body plus a padding field so that the body is about `--payload-bytes`; the mean body size the server actually received is printed per variant), with the same server delay and the same concurrency number (`--window`: `ConnectionProfile::$window` here, `setNbConcurrentRequests()` for pushok) and one connection. It runs `--repeat` measured rounds plus a **discarded** warm-up round, rotating the order of the variants and reversing it every other round, and reports medians and min-max.

How pushok is pointed at the mock without patching it: `Client::__construct()` takes curl options that win over the ones pushok sets itself. The options used are listed in `RESULTS.md`; in short: `CURLOPT_PORT`, `CURLOPT_RESOLVE` (`api.push.apple.com` to `127.0.0.1`), `CURLOPT_CAINFO` (the mock's CA, peer verification stays on), `CURLOPT_SSL_VERIFYHOST = 0` (the mock certificate is issued for `localhost`/`127.0.0.1`, so the hostname check cannot pass for `api.push.apple.com`; this package checks the host name), and `CURLOPT_PROXY = ''` (the container exports `HTTPS_PROXY`). pushok is used with a made-up pre-generated provider token, so no key signing happens in either library inside the measured section.

Two pushok variants are measured because its default closes the connection after every `push()`: `autoCloseConnections=false` (the warm, keep-alive configuration) and the library default.

Behaviour (not speed) is shown as observed facts for the same scripted situations: a request the server processed and then dropped (on a reused connection, on a fresh one, and inside a batch), a 503, a 503 then 200, a 410 with timestamp, and a response cut in the middle of the body. The report says what each library returned or threw and how many times the server received and processed each request.

## Limits of the method

- **Loopback and a mock.** No NIC, no WAN latency, no real TLS server, no provider rate limiting, no GOAWAY after hours, no real stream limits. The artificial delay is a fixed `setTimeout` in Node, not a latency distribution.
- **One machine, shared CPU.** The PHP process and the Node server run on the same cores. At delay 0 the server can be the bottleneck. Background load on the host adds noise: that is why every figure comes with its range.
- **Session resumption and handshake cost.** Cold runs pay a handshake with ECDSA certificates against a local server; real providers use different certificates, TLS session tickets and handshake RTTs. Cold-vs-warm differences here are not a prediction.
- **PHP CLI**, NTS build, opcache off, no JIT; FPM, Octane or queue workers may differ. libcurl 8.5 / OpenSSL 3.0 only.
- **Pipeline runs use real code but in-memory stores** (guard counters, token cache) and the system clock; a database or Redis store adds its own cost, not measured here.
- The pipeline benchmark sets the send options to the production environment so that the test-device allowlist (a linear scan, only used outside production) is not measured; the mock endpoint is selected through the driver configuration's test-only override.
- The mock records at most 50 requests (large-batch: the same) to keep the server's memory flat; counters are unaffected.
- The comparison covers one version of one library, configured as written down in the results. It is not a statement about other libraries or other versions.
