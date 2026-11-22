---
title: "Observability"
description: "Send logs, Pulse, Telescope, health checks, Prometheus metrics, operational alerts and credential monitoring. Everything here is optional and off by default unless the table says otherwise."
---

# Observability

Send logs, Pulse, Telescope, health checks, Prometheus metrics, operational alerts and credential monitoring. Everything here is optional and **off by default** unless the table says otherwise.

| Piece | Switch | Migration tag |
|---|---|---|
| Send log, `log` sink (a Laravel log channel) | `mobile-push.log.sinks` contains `log` (env `MOBILE_PUSH_LOG_SINKS=log`) | none |
| Send log, `database` sink | `log.sinks` contains `database` | `php artisan vendor:publish --tag=mobile-push-migrations-logs` then `php artisan migrate` (or `mobile-push:install`; with `MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true` `migrate` loads the package file and nothing is published) |
| Send log, `otel` sink | `log.sinks` contains `otel` and `open-telemetry/api` installed | none |
| Pulse card | `mobile-push.pulse.enabled` (env `MOBILE_PUSH_PULSE_ENABLED=true`) | none (views: `--tag=mobile-push-views`) |
| Telescope entries | `mobile-push.telescope.enabled` = `auto` (default), `true`, `false` (env `MOBILE_PUSH_TELESCOPE_ENABLED`) | none |
| Metrics recording | `mobile-push.metrics.enabled` (env `MOBILE_PUSH_METRICS_ENABLED`, default **`true`**) | none |
| Prometheus endpoint | `mobile-push.metrics.prometheus.enabled` (env `MOBILE_PUSH_PROMETHEUS_ENABLED=true`) | none |
| Alerts | `mobile-push.alerts.enabled` (env `MOBILE_PUSH_ALERTS_ENABLED=true`) | none |
| Health | always available (`mobile-push:health`) | none |

Related pages: [outcomes](/reliability/outcomes), [troubleshooting](/operations/troubleshooting), [commands](/operations/commands), [security and privacy](/operations/security-and-privacy), [safe mode](/reliability/safe-mode), [configuration](/get-started/configuration).

What was verified in a browser: on a freshly created Laravel 13 application with Pulse 1.11 and Telescope 5.29, the Pulse dashboard and Telescope were opened in headless Chromium after a few simulated pushes. The card shows the two connections, the top reason codes and the circuit and throttle blocks; Telescope lists `mobile-push.batch` events with their tags and the sanitised payload (no token). That check found and fixed two defects of the card (it came back empty on the second poll with Laravel 13's default `cache.serializable_classes = false`, and its layout relied on CSS classes Pulse does not ship). It was a manual run, not a CI job, with simulated sends only. The OpenTelemetry sink was verified against an in-memory SDK; an absent SDK makes the API a no-op, which the package cannot detect. The database log sink and everything else that needs Laravel also runs on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17 in CI; the metric counters were exercised across processes on the cache stores listed under [Prometheus](/modules/observability#observability-prometheus).

## Send log sinks

Every result of a send (one per destination) can be recorded in any combination of sinks. A failing sink **never affects a send**: the batch is counted as failed and forgotten, never retried and never rethrown, and the other sinks are not starved.

```php
// config/mobile-push.php
'log' => [
    'sinks' => ['log', 'database'],      // or MOBILE_PUSH_LOG_SINKS=log,database,otel ; empty = no send log
    'payload' => 'none',                 // none | keys | full
    'channel' => null,                   // Laravel log channel for the "log" sink; null = the default channel
    'levels' => [],                      // status => PSR-3 level
    'buffer' => ['size' => 100, 'max' => 10000],
    'database' => [
        'table' => 'mobile_push_logs',
        'connection' => null,
        'mode' => 'failures',            // all | failures | sampled
        'sample_rate' => 0.01,
        'retention_days' => 30,
        'insert_chunk' => 100,
    ],
],
```

| Key | Env | Default | Notes |
|---|---|---|---|
| `log.sinks` | `MOBILE_PUSH_LOG_SINKS` | `[]` | array or comma string. Built in: `log`, `database`, `otel`. Others via `SinkManager::extend()`. An unknown name is a `ConfigurationException` |
| `log.payload` | `MOBILE_PUSH_LOG_PAYLOAD` | `none` | `none`, `keys`, `full` |
| `log.channel` | `MOBILE_PUSH_LOG_CHANNEL` | `null` | |
| `log.levels` | | `[]` | map of status value => PSR-3 level (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`) |
| `log.buffer.size` | `MOBILE_PUSH_LOG_BUFFER_SIZE` | `100` | records that trigger a flush; `0` = flush only on terminate or after a queue job |
| `log.buffer.max` | `MOBILE_PUSH_LOG_BUFFER_MAX` | `10000` | hard cap: beyond it the oldest record is dropped and counted |
| `log.database.table` | `MOBILE_PUSH_LOG_TABLE` | `mobile_push_logs` | |
| `log.database.connection` | `MOBILE_PUSH_LOG_CONNECTION` | `null` | |
| `log.database.mode` | `MOBILE_PUSH_LOG_DB_MODE` | `failures` | |
| `log.database.sample_rate` | `MOBILE_PUSH_LOG_DB_SAMPLE_RATE` | `0.01` | |
| `log.database.retention_days` | `MOBILE_PUSH_LOG_RETENTION_DAYS` | `30` | used by `mobile-push:logs:prune` |
| `log.database.insert_chunk` | | `100` | rows per bulk insert |

Records go into a bounded in-memory buffer and are flushed when it reaches `buffer.size`, on application terminate, and after each queue job (processed, failed or exception).

### What a record contains, and what it never contains

A destination appears only as its **kind**, its **keyed index** and a **masked** form. Redaction happens once, when the record is built, so no sink ever sees a raw value; bearer tokens, receipt markers and raw tokens are passed as runtime secrets and scrubbed from every text field. Provider message text is cut to 191 characters and non-printable characters become `?`. Fields: scope, provider, binding (connection) name, app id, environment, operation (`send` or `validate`), push type, traffic class, destination kind / index / masked, status, reason code, raw reason, HTTP status, provider message id, attempts, duration in ms, payload bytes, payload level, payload, effect id, delivery id, campaign id, origin type / id, notification, time (UTC).

**What the manager fills in.** The send pipeline hands the sinks: operation, traffic class, duration, payload bytes, push type, the result itself (including the delivery id when it came from the ledger) and, depending on `log.payload`, the payload. For a queued send (a light-profile job or the durable relay) it also passes the **effect id**, the id `queue()` returned; a direct `send()` has none. It does **not** pass the campaign id or the notification origin, so with the manager's own wiring `campaign_id`, `origin_type`, `origin_id` and `notification` are empty in the table and the log lines (they are filled only for records you hand to `SinkManager::recordResult()` yourself with those context keys).

### The payload level

`log.payload` works on the manager's send path (`send()`, `queue()` through the relay, and the other pipeline users). The payload is the **JSON body of the last attempt made for that destination**, as it was sent to the provider. A destination that never reached the network (skipped by a gate, refused by the pre-flight, expired before sending) has no payload, and its `payload` field stays empty at every level. With the default `none` the payload is never decoded or copied, so the level costs nothing when off.

| Level | What a record carries |
|---|---|
| `none` (default) | no payload (only `payload_bytes`, the size of the body) |
| `keys` | the dotted key names only, never a value: `aps.alert.title`, `aps.alert.body`, `order` for APNs; `message.token`, `message.notification.title`, `message.data.order` for FCM. List indexes are dropped; at most 200 keys, each cut to 128 characters |
| `full` | the payload with every value passed through the redactor; replaced by `{"truncated":true,"bytes":N}` when the redacted JSON exceeds 8192 bytes |

**What is redacted at `full`** (replaced by `[redacted]` or a typed placeholder such as `[redacted:secret]`):

- the secrets of that attempt, wherever they appear inside a string: the provider **bearer token**, the **receipt marker** (`mp_r`) the package adds when receipts are on, and the **destination's own token**;
- any value stored under a key whose name contains `token`, `secret`, `password`, `authorization`, `cookie`, `receipt`, `credential`, `api_key`, `bearer`, `channel_id` and similar (so the FCM `message.token` field and an app field called `debug_token` are masked by name);
- strings shaped like a bearer header, a JWT or a PEM private key.

The same redaction is applied to every text field of every record.

**What is not redacted.** Anything else in the message is shown as the application wrote it: titles, bodies and the values of `data`. In particular, **another device's token that your code places in the message data is just data to the package**: only the token of the destination the record is about is known as a secret, so a different device's token under a neutral key name (`notify_device`, say) is written as is. Do not put tokens, personal data or secrets in a push if you log at `full`; use `keys` when the values must not be written anywhere. Sinks persist or ship what they are given: with the `database` sink the payload is stored in the `payload` column.

### The `log` sink

One structured line per record, message `mobile-push.send`, context = the record as a snake-case map without nulls. Default levels: `accepted`, `simulated`, `validated` -> `debug`; `deferred`, `skipped` -> `info`; `expired`, `rejected`, `failed`, `outcome_unknown` -> `warning`. Override with `log.levels`:

```php
'log' => ['sinks' => ['log'], 'channel' => 'push', 'levels' => ['accepted' => 'info', 'rejected' => 'error']],
```

```php
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Logging\LogChannelSink;
use Psr\Log\NullLogger;

$sink = new LogChannelSink(new NullLogger, ['accepted' => 'info']);

expect($sink->levelFor(SendStatus::Accepted))->toBe('info')
    ->and($sink->levelFor(SendStatus::OutcomeUnknown))->toBe('warning')   // an unknown outcome is not an error
    ->and(LogChannelSink::MESSAGE)->toBe('mobile-push.send');
```

### The `database` sink

Bulk inserts into `mobile_push_logs` (columns mirror the fields above; indexes on `created_at`, `(destination_index, created_at)`, `(status, created_at)` and `(campaign_id, status)`). `PushLog` is an Eloquent read model of the table.

| Mode | Persists |
|---|---|
| `all` | every record |
| `failures` (default) | every record except routine successes (`accepted`, `simulated`, `validated`) |
| `sampled` | every non-routine record plus a random share (`sample_rate`, 0 to 1) of the routine ones |

Prune with `php artisan mobile-push:logs:prune [--days=N] [--pretend] [--json]` (default `log.database.retention_days`). The package schedules it daily while the `database` sink is enabled (see [commands](/operations/commands#artisan-commands-what-the-package-schedules-by-itself)); run it yourself with `--days=` for another retention. The command skips with a warning when the `database` sink is not enabled.

### The `otel` sink (OpenTelemetry)

Needs `open-telemetry/api` (`composer require open-telemetry/api`); with `otel` listed and the API missing the application refuses to boot with a clear message. The application chooses the SDK and the exporter: providers bound in the container win, otherwise the global ones. **Without an SDK the API is a no-op, which is not telemetry delivered** and the package cannot tell the difference.

Instrumentation name: `padosoft/laravel-mobile-push`. One **span** per record (name `mobile-push send`, kind client) plus three metrics:

| Metric | Type | Unit |
|---|---|---|
| `mobile_push.send.outcomes` | counter | `{outcome}` |
| `mobile_push.send.duration` | histogram | `s` |
| `mobile_push.send.payload.size` | histogram | `By` |

Metric attributes are bounded: `messaging.system` (the provider), `mobile_push.binding`, `mobile_push.environment`, `mobile_push.status`, `mobile_push.reason`, and when known `mobile_push.push_type`, `mobile_push.traffic_class`. Ids never become metric attributes.

Span attributes (those that are known): `messaging.system`, `messaging.operation.name`, `messaging.operation.type` (`send`), `messaging.message.id`, `messaging.message.body.size`, `http.response.status_code`, `mobile_push.binding`, `mobile_push.app_id`, `mobile_push.environment`, `mobile_push.status`, `mobile_push.reason`, `mobile_push.outcome_unknown`, `mobile_push.push_type`, `mobile_push.traffic_class`, `mobile_push.attempts`, `mobile_push.destination.kind`, `mobile_push.destination.index`, `mobile_push.destination.masked`, `mobile_push.delivery_id`, `mobile_push.effect_id`, `mobile_push.campaign_id`. A `Rejected` or `Failed` record also sets `error.type` and the span status to error; an unknown outcome is **not** marked as an error. The `messaging.*`, `error.type` and `http.response.status_code` names were written from the OpenTelemetry specification by memory, not from the semantic-conventions package (not installed), so check them against your backend.

### Custom sinks

Implement `Padosoft\MobilePush\Laravel\Logging\LogSink` (`write(LogRecord ...$records)`, `flush()`) and register it in a service provider: `SinkManager::extend('mysink', fn ($app) => new MySink)`, then list `mysink` in `log.sinks`. Records are already redacted. `SinkManager::stats()` returns per-sink `written`, `failed`, `dropped`, `filtered`, `last_error` (the exception class only) plus `buffered` and `peak_buffered`; these counters are **per process**.

## Laravel Pulse

Needs `laravel/pulse` and Livewire. Nothing in the package extends a Pulse class unless Pulse is installed.

1. Enable: `MOBILE_PUSH_PULSE_ENABLED=true` (`mobile-push.pulse.enabled`). Also available: `pulse.sample_rate` (`MOBILE_PUSH_PULSE_SAMPLE_RATE`, 0 to 1, default 1.0) and `pulse.max_connections` (default 50).
2. Add the card to your published Pulse dashboard (`resources/views/vendor/pulse/dashboard.blade.php`):
   ```blade
   <livewire:mobile-push.pulse-card cols="full" />
   ```
   The table has nine columns: on a narrower card (`cols="6"`) it scrolls sideways instead of being cut off.
3. Optionally publish the card view to customise it: `php artisan vendor:publish --tag=mobile-push-views` (to `resources/views/vendor/mobile-push/pulse`).

**What it shows:** per `provider / connection`: accepted, rejected, failed (failed + expired), unknown, deferred and skipped counts, an accepted rate (`accepted / (accepted + rejected + failed + unknown)`), and the batch time (max and average); the ten most frequent **reason codes** among non-accepted results; the connections whose **circuit is open** and those **throttled** right now; and totals of throttle events and circuit transitions.

How it works: a recorder listens to the package events (`PushBatchCompleted`, `PushResultProduced`, `ProviderThrottled`, `CircuitOpened`, `CircuitClosed`, `CredentialFailedOver`) and writes Pulse aggregates. Status counts come from batch events, which exist for every batch. **Reason codes come from `PushResultProduced`, which by default is dispatched for transactional traffic only**: set `events.per_result` to `always` (`MOBILE_PUSH_EVENTS_PER_RESULT=always`) to see the reasons of bulk traffic too. With `pulse.sample_rate` below 1 the card scales the counts up by the inverse of the rate and says so.

**Cardinality:** keys contain only the provider, the connection name (normalised, and at most `max_connections` distinct values per process; beyond that they fold into `_other`), the environment, the status, the reason code, the guard and the transition. Never a device, user, campaign, message or token. A recorder failure is swallowed and counted.

## Laravel Telescope

When Telescope is installed and enabled, and `mobile-push.telescope.enabled` is not false, every completed batch and every result that needs attention (`rejected`, `failed`, `deferred`, `outcome_unknown`, `expired`, `skipped`) is recorded. Accepted results are counted by their batch entry; simulated and validated results are not recorded.

**Why they appear as events.** The package's HTTP transport does not use Laravel's `Http` client, so Telescope's client request watcher never sees a push. Telescope has a fixed list of entry types and its UI has one screen per type with the routes compiled into its JavaScript bundle, so a custom type would be stored but never listed. The entries are therefore recorded as **event** entries named `mobile-push.batch` and `mobile-push.result`, with the tags `mobile-push`, `push:<connection>`, `push-provider:<provider>`, `push-status:<status>` and `push-reason:<code>`, and the payload as JSON. Search by tag in the Events screen.

**Never recorded:** device tokens, bearers, receipt markers, payloads, the provider's raw reason text (a masked destination and the hint only). Whenever Telescope is installed the module also adds a Telescope filter that keeps Telescope's own event watcher from capturing the package's raw events (which carry provider reason text): the sanitised entries are the only trace.

## Health: liveness and readiness

```bash
php artisan mobile-push:health [--json]
```

It never sends a request to a provider. **Liveness** (can the package run in this process?): `php_extensions` (curl, openssl, json), `curl_capabilities` (libcurl with TLS and HTTP/2; a warning when client certificates in memory are not supported), `config`. **Readiness** (can it do its job now?): `credentials` (one check per connection, from the credential inspectors), `cache` (reachable, returns what was written, can take locks), `ledger` (durable profile only: reachable, oldest due delivery within `health.ledger_backlog_seconds` (warning, default 300) and `health.ledger_backlog_failed_seconds` (failed, default 3600)), `log_sinks` (failing or dropping records, in this process), `guards` (open circuits, throttled accounts), `queue` (the queue `retry_after` must exceed the job timeout), `execution_mode` (warns on **simulate mode in production** and on live mode outside production).

Statuses: `ok`, `warning`, `failed`, `not_verifiable` (cannot be decided from here; counts like ok). **Exit codes: 0 ok (or only not-verifiable), 1 warning, 2 failed.** `--json` prints `status`, `exit_code`, `generated_at`, a `liveness` and a `readiness` section with their checks, and `live_probe: not_run`. A real probe is deliberately not part of health because health runs automatically; use `php artisan mobile-push:doctor --live` for that (it sends real requests, one per connection).

**spatie/laravel-health:** when the package is installed, a `MobilePushHealthCheck` is registered automatically (unless `health.spatie.register` is `false`): ok / warning / failed map from the report and the failing checks are summarised in the message. Register it yourself with `Health::checks([MobilePushHealthCheck::new()])` if you disabled the automatic one.

## Prometheus

Off by default. Enable with `MOBILE_PUSH_PROMETHEUS_ENABLED=true` and a token:

| Key | Env | Default |
|---|---|---|
| `metrics.enabled` | `MOBILE_PUSH_METRICS_ENABLED` | `true` (counters are recorded in the package cache) |
| `metrics.max_series` | | `1000` (at least 10; beyond it new series are silently not recorded) |
| `metrics.prometheus.enabled` | `MOBILE_PUSH_PROMETHEUS_ENABLED` | `false` |
| `metrics.prometheus.path` | | `mobile-push/metrics` |
| `metrics.prometheus.middleware` | | `[RequireMetricsToken::class]` |
| `metrics.prometheus.token` | `MOBILE_PUSH_METRICS_TOKEN` | `null` |

The endpoint is `GET /mobile-push/metrics`. The default middleware requires `Authorization: Bearer <token>`:

- no token configured: **every request is refused with 403** (the endpoint is never public by accident);
- a missing or wrong token: 401; the comparison is constant-time.

Making it public is an explicit choice: set `metrics.prometheus.middleware` to your own list (`[]` for none). Routes are not registered when your routes are cached.

Counters live in the package cache (`mobile-push.cache.store`), one key per series plus an index. A cache flush or eviction resets them to zero, which Prometheus treats as a counter reset. Labels are bounded: connection, provider, environment, status, reason code, traffic class; never a user, device or campaign. Exposition format 0.0.4.

How the counters behave on each cache store (eight processes recording 25 results each into one series, at the same instant, then a scrape: `MetricsCoordinationTest`):

| Store | Counted of 200 | Notes |
|---|---|---|
| redis | 200 | atomic increment, no TTL |
| database on MySQL, MariaDB, PostgreSQL | 200 | the store does not create a key on increment: the counter is created with `add()` and lives one year, then restarts from zero |
| memcached, dynamodb | 200 (in the `backends` workflow) | as above |
| file | 198 to 200 | the store increments by reading and writing a file: the recorder serialises it with the store's lock, which it polls for about half a second; a process starved of the lock that long counts without it |
| database on a shared SQLite file | 162 to 198 | SQLite refuses a write while another process writes to the same file; the recorder retries ten times within a few milliseconds, then gives the count up rather than hold the send |

Two **defects were found and fixed** here on 2026-11-22: on the `database` store (the default cache store of a new Laravel application), and on memcached and DynamoDB, **no counter was recorded at all** (`increment` on a missing key answers false and creates nothing); and on the `file` store concurrent processes lost more than half of the counts. Recording stays best effort: it never throws and never delays a send by more than a few tens of milliseconds.

| Metric | Type | Labels | Meaning |
|---|---|---|---|
| `mobile_push_results_total` | counter | `provider`, `connection`, `environment`, `status`, `reason`, `class` | send outcomes |
| `mobile_push_throttled_total` | counter | `provider`, `connection`, `environment`, `class` | times a connection was paused by a provider throttle |
| `mobile_push_guard_transitions_total` | counter | `provider`, `connection`, `environment`, `guard`, `transition` | circuit breaker transitions |
| `mobile_push_circuit_open` | gauge | `provider`, `connection`, `environment`, `guard` | 1 while open or half-open |
| `mobile_push_account_throttled` | gauge | `provider`, `connection`, `environment`, `class` | 1 while paused by a throttle |
| `mobile_push_queue_wait_seconds` | gauge | `provider`, `connection`, `environment` | light profile: seconds the most recent job of the connection waited in the queue before starting; disappears after 10 minutes without a job |
| `mobile_push_metrics_series` | gauge | none | number of recorded series |
| `mobile_push_ledger_reachable` | gauge | none | durable profile: 1 when the ledger could be read at scrape time |
| `mobile_push_ledger_due` | gauge | none | deliveries due now |
| `mobile_push_ledger_pending` | gauge | none | deliveries waiting for a first attempt |
| `mobile_push_ledger_deferred` | gauge | none | deliveries waiting for a retry |
| `mobile_push_ledger_in_flight` | gauge | none | deliveries leased by a worker |
| `mobile_push_ledger_unknown` | gauge | none | deliveries that ended with an unknown outcome |
| `mobile_push_ledger_oldest_due_age_seconds` | gauge | none | age of the oldest due delivery; growing means the relay is stuck |
| `mobile_push_log_records_written_total` | counter | none | log records written by the sinks **of the process serving the scrape** |
| `mobile_push_log_records_failed_total` | counter | none | ... failed |
| `mobile_push_log_records_dropped_total` | counter | none | ... dropped (buffer full) |
| `mobile_push_log_buffered` | gauge | none | records waiting in that process's buffer |

The ledger gauges are read from the database at scrape time and are global; the log-sink metrics are process-local. Queue age is the ledger gauge `mobile_push_ledger_oldest_due_age_seconds` for the durable profile and `mobile_push_queue_wait_seconds` for the light profile.

```php
use Padosoft\MobilePush\Laravel\Health\Metric;
use Padosoft\MobilePush\Laravel\Health\MetricType;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;

$text = PrometheusExporter::format([
    new Metric('mobile_push_results_total', MetricType::Counter, 'Send outcomes.', ['provider' => 'apns', 'connection' => 'ios', 'status' => 'accepted'], 1200),
    new Metric('mobile_push_results_total', MetricType::Counter, 'Send outcomes.', ['provider' => 'apns', 'connection' => 'ios', 'status' => 'outcome_unknown'], 3),
]);

expect($text)->toContain('# TYPE mobile_push_results_total counter')
    ->and($text)->toContain('mobile_push_results_total{connection="ios",provider="apns",status="accepted"} 1200');
```

## Alerts

Mail and/or a Slack incoming webhook. Off by default.

| Key | Env | Default |
|---|---|---|
| `alerts.enabled` | `MOBILE_PUSH_ALERTS_ENABLED` | `false` |
| `alerts.mail` | `MOBILE_PUSH_ALERT_MAIL` | `[]` (comma-separated string accepted) |
| `alerts.slack_webhook` | `MOBILE_PUSH_ALERT_SLACK` | `null` (an `https://hooks.slack.com/...` URL) |
| `alerts.cooldown_seconds` | | `3600` |
| `alerts.send_in_simulate` | | `false` |
| `alerts.error_rate.threshold` | | `0.5` (0 to 1) |
| `alerts.error_rate.min_sample` | | `50` |
| `alerts.error_rate.window_seconds` | | `300` (at least 60) |
| `credentials.warn_days` | | `30` |

Kinds (`AlertKind`):

| Kind | Raised when |
|---|---|
| `circuit_opened` | a circuit breaker opens or re-opens (auth guard: the provider refuses the credentials; destination-quality guard: too many destinations refused) |
| `credential_failed_over` | the primary credential was refused and sends moved to the secondary one |
| `credential_expiring` / `credential_expired` | a credential check finds a certificate within `credentials.warn_days` of its expiry, or past it |
| `error_rate_high` | over the last `window_seconds`, at least `min_sample` outcomes were sampled and the error share reached `threshold` for a connection. Errors are `failed`, `outcome_unknown` and `rejected` for reasons that are not plain destination churn; skipped, simulated, validated, deferred and expired are not sampled |
| `ledger_backlog` | durable profile: the oldest due delivery is older than `health.ledger_backlog_seconds` |
| `log_sink_failing` | a log sink failed to write records since the last evaluation |

**Cooldown.** Each alert has a stable key (`kind:subject`); the same condition alerts at most once per `alerts.cooldown_seconds` (a cache-backed atomic claim; if the cache is down an in-process cooldown prevents a storm). A circuit that closes re-arms its own alert. Titles, bodies and context pass through the redactor; they are built from public facts (connection name, counts, ages), never tokens or provider responses.

**Simulate behaviour.** In simulate mode an alert is written to the log instead of being sent, unless `alerts.send_in_simulate` is `true`. With no route configured (no mail address, no webhook) the alert is logged as a warning. The sending itself uses `Notification::route()` and `notifyNow()`.

Circuit and failover alerts are raised while sending (the manager's observer calls the alerter). The periodic ones (error rate, ledger backlog, failing sinks) come from `OpsAlerter::evaluate()`, which `IntegrationModule` schedules **every minute** while `alerts.enabled` is true (the event is named `mobile-push:alerts`, without overlapping, not `onOneServer()`; check with `php artisan schedule:list`). Your scheduler must run `schedule:run` every minute: see [commands](/operations/commands#artisan-commands-what-the-package-schedules-by-itself) for everything the package schedules.

## Credential monitoring

```bash
php artisan mobile-push:credentials:check [--no-alert] [--json]
```

Inspects every connection with the credential inspectors (APNs `.p8` key shape, certificate validity and Apple's extensions, FCM service-account JSON) **without any request to a provider**, reports findings and, unless `--no-alert`, raises `credential_expiring` / `credential_expired` alerts. Exit code **0 ok, 1 warnings, 2 errors**. It reads the connections of the push manager (bound by `IntegrationModule`); a connection whose secret cannot be read is reported as an error finding, and if no source is available it says that credentials cannot be verified from here instead of reporting ok. `mobile-push:doctor` gives a wider environment check. Apple's certificate extensions are decoded best-effort and were not verified against real certificates. The package schedules it daily (at `maintenance.daily_at`, default 03:17, unless `maintenance.schedule` is false), so the expiry alerts need only the scheduler running and `alerts.enabled`. With alerts off the scheduled run sends nothing: it only computes the findings (see its exit code, and `mobile-push:health`).

---

*This page is generated from [`docs/observability.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/observability.md). To change it, edit that file in the repository, not this site.*
