# Runtime compatibility

How the package behaves in the PHP runtimes a Laravel application runs in, what was **verified in this repository
and how**, and what was not. A runtime that was not run here is never called "supported": its row says what the
code is designed to do and what remains to be tested.

All measurements: PHP 8.3.6 CLI (NTS) with `pcntl`/`posix`, libcurl 8.5.0 (nghttp2), OpenSSL 3.0.13, Laravel 13.43,
Node 22 (the local HTTP/2 mock server in `tests/Support/H2Mock`), redis-server 7 (local), SQLite. Every count of
connections and requests below was read from the mock server, not from the package.

## What "connection reuse" means here

`CurlTransport` keeps one persistent `curl_multi` handle per **pool key** (credential identity + endpoint role +
environment + TLS/proxy profile + generation) in a **per-process** registry (`CurlPoolRegistry::process()`, a static).
A connection is therefore reused only by later sends **in the same OS process**: across jobs of one queue worker,
across requests of one long-lived HTTP worker. Nothing is shared between processes. The registry holds at most 32
pools (LRU; the evicted pool is closed), closes a pool idle longer than its `max_idle_seconds`, and abandons
inherited pools when it notices a PID change. The manager keeps at most `mobile-push.connection_cache.size` (default
64) resolved connections and closes the pools of the one it evicts. See [transport.md](transport.md).

## Matrix

| Runtime | Connection reuse | Verified here, and how | Known limits | Required settings |
|---|---|---|---|---|
| **PHP-FPM** (and any one-request-per-process SAPI) | **No reuse between requests.** Within one request, yes (all the pushes of that request share the connection). | Not run under FPM. Follows from the design (per-process registry) and was **read in source**. | Every web request that sends pays a TCP + TLS handshake and, on a cold token cache, an APNs/Google token mint. With many pushes per request this adds latency to the user's request. | **Queue the sends** (`->queue()`, notifications with `ShouldQueue`): the queue worker is the process that reuses the connection. A shared cache store (`mobile-push.cache.store`: redis, database, memcached) so that every FPM worker uses the same provider token instead of minting one per process. |
| **`queue:work`** | **Yes**, for the whole life of the worker. | **Real process test** (worker loop): `Artisan::call('queue:work')` (the real `Illuminate\Queue\Worker` daemon loop, in-process) on the `database` queue processed **2,000** light-profile `SendPushJob`s (10,000 in the `slow` group) through the real manager and the real `CurlTransport` → **1 connection** for the whole run, 1 pool, 1 cached connection, memory growth < 1 MiB after warm-up, open descriptors unchanged (`QueueWorkerRuntimeTest`). Transport level, one process: 2,000 consecutive sends with idle gaps → 1 connection; memory growth < 256 KiB; descriptors unchanged; 20,000 sends in `slow` (`LongLivedWorkerTest`). Source: `Worker::daemon()` is a loop in one process, no fork (`vendor/laravel/framework/src/Illuminate/Queue/Worker.php`). | Documented reconnects: after more than `max_idle_seconds` without a send (exactly one new connection, the old one closed); after the provider closes the connection (transparent, nothing lost when nothing was in flight). A job timeout kills the worker (`SIGKILL`), which ends its connections with it. | Pass `--memory` above the process size (Laravel's default 128 MB stops the worker early). Keep `max_idle_seconds` (default 3600) as is unless the network drops idle connections sooner. |
| **`queue:listen`** | **No reuse between jobs.** | **Read in source**: `Illuminate\Queue\Listener` starts a new `queue:work --once` process per job (Symfony Process / `proc_open`). | Same cost as PHP-FPM per job. | Use `queue:work` in production. |
| **Horizon** | **Designed: yes, per worker process** (Horizon workers run `queue:work`). | **Not verified: Horizon is not installed in this repository.** Laravel's own queue code starts workers with `proc_open`, not `fork`. To confirm for Horizon, its supervisor source must show worker processes started with Symfony Process (`proc_open`) running `horizon:work` / `queue:work`, and no `pcntl_fork` after the application is booted. | As `queue:work`. A Horizon supervisor that forked a booted worker would be exposed to the fork problem below. | As `queue:work` (`memory` in the supervisor config). Check yourself with the scenarios below. |
| **Octane + Swoole / OpenSwoole** | **Not verified. Treat as unsupported until tested.** Designed: yes per worker process, **only without coroutines**. | **Not verified**: Swoole is not installed and cannot be installed here. **Simulated**: (1) Octane's request sandbox (`clone $app` per request) → see "Octane-style sandbox" below; (2) two `send()` generators interleaved with PHP Fibers on one pool (the coroutine concern at the boundary between pushes) → correct (`OverlappingSendsTest`). | **Coroutines are not supported**: the transport blocks in `curl_multi_select()`; it was never run with Swoole's curl hooks (`SWOOLE_HOOK_CURL` / `SWOOLE_HOOK_NATIVE_CURL`), which replace curl's I/O and can switch coroutines *inside* a curl call — the case the Fiber simulation cannot reproduce. Octane-specific state issues below. | Run Octane workers **without** curl coroutine hooks for sending, or (safer) **send from queue workers only**. A shared cache store (not `array`). One `scopeUsing()` at boot (see below). |
| **Octane + RoadRunner** | **Not verified.** Designed: yes per worker process (RoadRunner workers are long-lived PHP processes, no coroutines). | **Not verified** (no `rr` binary). **Simulated**: Octane sandbox per request (`OctaneSandboxTest`) and request isolation on a shared app (`RequestIsolationTest`). | Octane-specific state issues below. | As Swoole, minus the coroutine rule. |
| **Octane + FrankenPHP** | **Not verified.** Designed: yes per worker thread **only if** each worker is its own PHP process; in FrankenPHP's threaded worker mode the per-process statics (the registry) are per-thread under ZTS, which was not tested. | **Not verified** (no `frankenphp` binary; this PHP is NTS). | As above; ZTS/threads untested. | As RoadRunner. Verify with the scenarios below before relying on it. |
| **AWS Lambda / Laravel Vapor** | **Designed: within one warm invocation container only**; a frozen container may find its connection closed by the peer when it thaws. | **Not verified.** | A thawed container's first send may hit a dead connection: libcurl detects it before reuse (measured on server-closed idle connections: transparent reconnect, nothing lost when nothing was in flight). Cold starts pay a handshake and a token mint. | Shared cache store (DynamoDB/redis) for tokens and guards; queue the sends (SQS worker). |
| **Forked children** (`pcntl_fork`, `spatie/fork`, Laravel `Concurrency::driver('fork')`) | Each child opens its own pools; **the parent's pools are put at risk by children that exit normally**. | **Real process test** (`ForkSafetyTest`, see below). | See "Fork" below. | Do not send from a process that forks, or close the pools before forking (`MobilePush::flush()`). |

### How to check a runtime yourself

```bash
vendor/bin/pest tests/Scenario/Runtime                  # transport: worker life, fork, many tenants, overlapping sends
vendor/bin/pest tests/Scenario/Laravel/Runtime          # queue:work, 200 runtime connections, request isolation, Octane sandbox, cache races
vendor/bin/pest tests/Scenario/Runtime tests/Scenario/Laravel/Runtime --group=slow   # soak variants
```

They need Node ≥ 18 (mock server), `pcntl`/`posix` (fork scenarios) and, for the redis case of the cache race,
`redis-server` + `ext-redis` (skipped otherwise). They prove the package's mechanisms, not a runtime: to verify a
runtime, run the same kind of scenario inside it (for Octane: a route that sends to a test endpoint, hit by many
concurrent requests, counting the connections on the endpoint), and compare the server's counters with the
expectations above.

## Scenario results

### 1. A queue worker that lives for a day

- Transport, one process: 2,000 batches of 5 requests on one pool key, a 300 ms pause every 250 batches →
  10,000 requests received, **1 connection**, memory growth after 200 batches < 256 KiB, open descriptors equal.
  Soak (`slow`): 20,000 batches / 100,000 requests, same results.
- Idle longer than `max_idle_seconds` (1 s in the test) → exactly **2** connections in total, the old one closed.
- The server closes the idle connection after each of 5 jobs → 20/20 requests answered, 5 connections (one reconnect
  per close, never more), no failure.
- Laravel: real `queue:work` loop on the `database` queue, 2,000 `SendPushJob`s → see the matrix.

### 2. Fork

- **(a) Child sends while the parent sends** (child ends with `SIGKILL`): the child's registry resets for its PID
  (`CurlPoolRegistry::inheritedCount()` = 1), it opens **its own** connection, the parent keeps **one** connection
  before, during and after; each process receives exactly the answers to its own request ids (the server echoes the
  request path in the body); 95/95 requests received and processed, 2 connections in total.
- **(b) Residual risk, reproduced**: when a child **exits normally**, PHP's shutdown frees the inherited curl
  handles; libcurl then reads from and writes a TLS `close_notify` on the socket the parent still uses.
  - parent idle at that moment: its connection is closed (server sees 0 open); the next send reconnects
    transparently, 3/3 answered;
  - parent with **3 requests in flight**: all 3 come back `Unknown` (errno 55 `SSL_write: SSL_ERROR_ZERO_RETURN` /
    errno 65 `bad record mac`), **although the server processed all 3** (→ `OutcomeUnknown`, possible duplicates if
    retried). The next send reconnects.
- **Can the transport prevent it? No safe fix exists in PHP.** The damage is done by `curl_multi` free handlers
  during the child's shutdown, which run for every live object even in fast shutdown, before and regardless of any
  code of the package; libcurl offers no option to drop a connection without the TLS shutdown, and PHP cannot close
  a raw descriptor in the child only (that would need FFI). A shutdown function that kills the child would change
  the child's exit status and skip the application's own shutdown work. The registry already never reuses or
  closes inherited pools itself.
- **Recommended patterns** (both pinned by tests): close the pools before forking (`$transport->close()` /
  `MobilePush::flush()`): the parent then sends on a fresh connection; or end children with
  `posix_kill(getmypid(), SIGKILL)` after their work. Best: do not send from a process that forks; let each worker
  open its own pools.
- Laravel `queue:work` does not fork; `queue:listen` and the framework's process helpers use `proc_open`
  (read in source). Laravel's `Concurrency` **fork** driver (spatie/fork) does fork and its children exit normally:
  do not use it in a process that has sent pushes.

### 3. Two hundred tenants on one worker

- Transport: 200 pool keys in sequence, then all again: the server never saw more than **32** open connections
  (the registry limit) after each send, 400 connections in total (an LRU cycle longer than the limit reconnects every
  revisit), 368 evictions, each evicted pool closed (server-side close observed), process sockets = 32, descriptors
  and memory flat between the first and the second pass. A working set under the limit (8 tenants × 3 passes) →
  8 connections, no eviction. Soak (`slow`): 10 passes.
- Laravel: 200 tenants registered at runtime (`MobilePush::register(..., scope)`), each with its own APNs key, served
  twice: with `connection_cache.size` 16 the server never saw more than **16** open connections (the manager closes
  the pools of the drivers it evicts); with the default 64 above the registry's 32, never more than **32**.
  400/400 accepted, memory growth on the second pass < 512 KiB, descriptors equal; `MobilePush::flush()` closes all.
- The registry limit (32) is not configurable from the Laravel config: `SendingModule` builds `new CurlTransport`
  on the process registry.

### 4. Overlapping sends in one process (simulated coroutines)

Two "requests" (PHP Fibers) each iterate their own `send()` on the **same pool** and yield to each other after every
answer, so the generators overlap on one curl multi handle:

- 40 + 40 requests interleaved (the server received them alternating): every answer reached the generator that
  sent it, nothing lost or duplicated, one connection.
- One generator abandoned after 3 answers with requests still in flight: its streams were reset (`RST_STREAM`,
  counted by the server), the other finished 30/30 correctly, the connection survived, the next send was correct.
- A request suspended forever keeps its pool "in use" (never evicted; `close()` defers until release); destroying
  its Fiber runs the generator's cleanup and the deferred close happens.
- **Defect found and fixed** (`CurlPool::perform()`): while one generator waited for its answer and another,
  suspended generator had completions queued, the waiting generator skipped `curl_multi_select()` and spun on
  `curl_multi_exec()` for the whole wait (measured 0.15 s of CPU for a 0.4 s wait). The wait is now skipped only
  when the caller itself has a completion to take (measured < 1 ms of CPU). Pinned by `OverlappingSendsTest`.
- Safe rule: concurrent sends on one pool are correct **when the switching happens between pushes** (Fibers,
  generators, a consumer starting a second send inside the first). Switching **inside** a curl call (Swoole curl
  hooks) was not tested and is not supported; under a coroutine runtime send from queue workers, or give each
  coroutine its own registry (`new CurlTransport(new CurlPoolRegistry)`, i.e. no reuse between coroutines).

### 5. State between requests (Octane-style)

**Shared app** (the app booted once, the same container for every request — a queue worker, or an Octane worker where
the manager was resolved at boot), 40 tenants in a row, each request with `scopeUsing()`, a tenant resolver, its own
key and device (`RequestIsolationTest`): every request sent only with its own scope, its own team/key JWT, to its own
device; the `PushBatchCompleted` event and the log record carried its scope; the log buffer was empty after
`terminate()`; one bearer per tenant, never another tenant's. A tenant whose key is refused trips **its** auth
breaker only (shared store, keyed by account): the next tenant is accepted, the refused tenant stays off the wire in
its next request (`Skipped` / `CircuitOpen`).

Pinned limitations of a shared manager (the API is process-level, not request-level):

- a request that does not call `scopeUsing()` **inherits the previous request's scope** → call `scopeUsing()` once at
  boot with a resolver that reads the current request (`fn () => app(CurrentTenant::class)->id ?? 'default'`);
- `eligibleUsing()` called per request **accumulates**: every earlier check keeps running in later requests
  (`FallbackPolicy::when()` of the notifications module has the same shape) → register checks once, at boot;
- `register()` keeps every runtime connection (with its key material) for the life of the process, with no way to
  remove it → for many tenants use `resolveConnectionUsing()` (resolved on demand, the drivers bounded by the cache).

**Octane sandbox** (`OctaneSandboxTest`; Octane's mechanism reproduced: `clone $app` per request, the clone bound as
container and facade root, cloned config, `terminate()` at the end; Octane itself not installed):

- A manager resolved during the request lives only in that request: nothing set by one request was seen by the next
  (scope, runtime registrations). Connections still reused: 20 sandboxed requests → **1** connection, because the
  transport each request builds uses the per-process registry; one provider token for all of them when the cache
  manager is warm (Octane warms `cache` by default).
- With the `array` cache store resolved per request, **every request minted a new APNs token** (3 requests → 3
  tokens): Apple rejects frequent token updates (`TooManyProviderTokenUpdates`). Use a shared store.
- A manager resolved at boot (e.g. by calling `MobilePush::resolveConnectionUsing()` in a service provider) is shared
  by every sandbox: the limitations of a shared manager apply.
- **Defect (Logging module)**: records buffered by a `SinkManager` resolved inside a sandbox are **never flushed**:
  `LoggingModule::boot()` registers `$app->terminating(fn () => $app->resolved(SinkManager::class) ? ... )` with the
  booted `$app` captured, so at the end of a sandboxed request it looks at the booted application, sees no resolved
  `SinkManager` and does nothing; the record is lost with the clone (pinned). Fix for the module owner: take the
  application as the callback's parameter (`$app->terminating(function (Application $app) { ... })`, which
  `Application::terminate()` injects), or flush after each `PushBatchCompleted`.

Singletons registered by the package and what a long-lived process must know about them:

| Singleton | Shared state | Classification |
|---|---|---|
| `Transport` (`CurlTransport`) | pools in the static per-process registry | **share** (designed to be shared; the registry survives even a per-request transport) |
| `SendingSettings`, `HealthSettings`, `LiveActivitiesConfig`, `FrequencyLimits`, `EndpointCatalog` | config snapshot at first resolution | share; a config change at runtime needs `forgetInstance()` (as with any Laravel singleton) |
| `TokenIndex`, `TokenCipher`, `Redactor`, `ReceiptMarker` | key rings from config | share |
| `TokenStore` (`CacheTokenStore`), `GuardStore` (`CacheGuardStore`), `CacheHealth` | none (refresh locks only during a call; warning throttle) | share; the **cache store** behind them must be shared across processes (not `array`) |
| `Senders`, `Guards`, `Sender`, `SafeModeGuard`, `ConnectionFactory` | no per-request state (guard state is in the store) | share |
| `PushManager` (`PushDispatcher`, facade `MobilePush`) | scope resolver, tenant resolver, runtime registrations, consent checks, bounded connection cache | **needs care**: configure at boot only (see above); per-request calls to `scopeUsing()` / `eligibleUsing()` / `register()` leak or accumulate. With Octane, either resolve it at boot and configure it once, or flush it per request (Octane `flush` list: `Padosoft\MobilePush\Laravel\Sending\PushManager` — but then connections are rebuilt per request; pools survive in the registry) |
| `SinkManager` | buffered log records, sink stats; static `$extensions` | **needs a flush per request**; under Octane see the defect above (until fixed, add a listener on Octane's `RequestTerminated` that flushes `app(SinkManager::class)`) |
| `LogRecordFactory`, `LogPruner`, `DevicePruner`, `ActivityPruner`, `MessageVault`, `Rows` | none | share |
| `DatabaseLedger`, `DeviceRegistrar`, `DeviceOutcomeApplier`, `DeviceEligibility`, `ReceiptRecorder`, `ReceiptFunnelReader` | none in PHP (state in the database) | share |
| `ReceiptMarkerIssuer` | bounded set of message ids issued by this process | share (process-level by design) |
| `FrequencyCounter`, `FrequencyCapGate`, `PolicyGates`, `BadgeCounter`, `BadgeResolver` | none in PHP (state in the store) | share |
| `TypeRegistry`, `ActivityRegistry`, `ChannelRegistry`, `ActivityManager` | none per request | share |
| `MetricsRecorder` | 60 s memo of known/rejected connection labels | share |
| `OpsAlerter` | local alert cooldowns, last seen sink failures | share (process-level by design) |
| `CredentialMonitor`, `HealthInspector`, `PrometheusExporter`, `MobilePushRecorder` (Pulse), `PushWatcher` (Telescope) | counters of their own failures | share |
| `FallbackPolicy` (notifications) | list of checks added by `when()` | **needs care**: register at boot only (accumulates) |
| `ConnectionCredentialsSource` (integration) | closures over the manager | share |
| `TestingSession`, `StdinReader`, `XcrunRunner` | testing / console only | n/a |

Octane `flush`/reset hooks are **not required** for correctness when the package is configured at boot. They would be
needed only for `PushManager` (if the application calls its setters per request) and `SinkManager` (until the
defect above is fixed).

### 6. Cache-backed coordination across real processes

Eight PHP processes (`proc_open`), each booting the application, start at the same instant on a **cold** store, ask
for the APNs provider token and take 30 permits each (240 asked) from a rate limiter of 100 with a frozen clock
(`CacheCoordinationTest`):

| Store | Exercised | Token | Permits granted |
|---|---|---|---|
| redis (real `redis-server`, `phpredis`) | yes | one token used by all 8 (1–8 signatures: on a cold store every process may sign, the compare-and-swap keeps one) | exactly 100 |
| database (shared SQLite file, WAL) | yes | one token | exactly 100 |
| file (shared directory) | yes | one token | exactly 100 |
| memcached, dynamodb | not available here | — | — |

With the store **unreachable**: each process signs and uses its own token (the designed local fallback), and every
`RateLimiter::acquire()` throws `RuntimeException('Guard state is too contended to update.')` after 50 failed
compare-and-set attempts. **Defect (Core/Guard, not fixed here)**: `SendRun::guardsBefore()` rethrows that exception
when nothing has been sent yet in the call, so with the cache down every send that has a rate ceiling (FCM has one by
default, `guards.rate.fcm` = 500,000) **throws** instead of returning `Deferred` results, and the message is
misleading (the store is down, not contended).

## Not verified

- Laravel Octane itself (any server), Swoole/OpenSwoole coroutine hooks, RoadRunner, FrankenPHP (and ZTS builds),
  AWS Lambda/Vapor, Horizon (not installed), PHP-FPM (not run; the conclusion follows from the design).
- A worker running for real hours: idle periods were simulated with short pauses and a 1-second `max_idle_seconds`;
  the libcurl default for idle reuse (118 s, overridden by the transport) and TCP keepalive were measured by the
  transport author (see transport.md), not re-measured here.
- `queue:work` was run in the test process through `Artisan::call()` (the real worker loop), not as a separate
  `php artisan` process.
- memcached and dynamodb cache stores; MySQL/PostgreSQL for the database store.
