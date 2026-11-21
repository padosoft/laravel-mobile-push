---
title: "Configuration reference"
description: "Every key of config/mobile-push.php, its environment variable, default and allowed values. Keys are relative to mobile-push (read them with config('mobile-push.<key>')). Every optional module is off by default."
---

# Configuration reference

Every key of `config/mobile-push.php`, its environment variable, default and allowed values. Keys are relative to `mobile-push` (read them with `config('mobile-push.<key>')`). Every optional module is off by default. Publish the file with `php artisan vendor:publish --tag=mobile-push-config`.

## Sections

- [General](/get-started/configuration#configuration-reference-general) (4 keys)
- [Connections](/get-started/configuration#configuration-reference-connections) (29 keys)
- [Transport](/get-started/configuration#configuration-reference-transport) (7 keys)
- [Retry budget](/get-started/configuration#configuration-reference-retry-budget) (10 keys)
- [Guards: rate limits and circuit breakers](/get-started/configuration#configuration-reference-guards-rate-limits-and-circuit-breakers) (9 keys)
- [Delivery profile and events](/get-started/configuration#configuration-reference-delivery-profile-and-events) (3 keys)
- [Migrations](/get-started/configuration#configuration-reference-migrations) (1 keys)
- [Shared cache](/get-started/configuration#configuration-reference-shared-cache) (2 keys)
- [Privacy keys](/get-started/configuration#configuration-reference-privacy-keys) (4 keys)
- [Device registry](/get-started/configuration#configuration-reference-device-registry) (7 keys)
- [Send log](/get-started/configuration#configuration-reference-send-log) (12 keys)
- [Durable ledger](/get-started/configuration#configuration-reference-durable-ledger) (8 keys)
- [Queue](/get-started/configuration#configuration-reference-queue) (13 keys)
- [Client receipts](/get-started/configuration#configuration-reference-client-receipts) (10 keys)
- [Metrics and health](/get-started/configuration#configuration-reference-metrics-and-health) (10 keys)
- [Operational alerts](/get-started/configuration#configuration-reference-operational-alerts) (8 keys)
- [Live Activities](/get-started/configuration#configuration-reference-live-activities) (18 keys)
- [Frequency cap and badge](/get-started/configuration#configuration-reference-frequency-cap-and-badge) (7 keys)
- [Fallback to another channel](/get-started/configuration#configuration-reference-fallback-to-another-channel) (4 keys)
- [Campaigns](/get-started/configuration#configuration-reference-campaigns) (8 keys)
- [Laravel Pulse, Telescope and test fakes](/get-started/configuration#configuration-reference-laravel-pulse-telescope-and-test-fakes) (5 keys)

## General

Tenant scope, default connection, routing and execution mode.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `scope` | `MOBILE_PUSH_SCOPE` | `'default'` | `string` | letters, digits, ".", "_", ":", "-"; max 64 | Tenant scope used when the application supplies none (MobilePush::scopeUsing() / ->scope()); never read from client payload. | sending |
| `default` | `MOBILE_PUSH_CONNECTION` | `null` | `string\|null` |  | Connection used by MobilePush::connection() without a name; null = route by provider (see routes). | sending |
| `routes` | `MOBILE_PUSH_ROUTES` | `null` | `list<string>\|null` |  | Connections used when none is named (comma-separated in the env var); null = every configured connection. | sending |
| `mode` | `MOBILE_PUSH_MODE` | `'auto'` | `string` | `auto`, `live`, `simulate` | Execution mode: auto = live only when APP_ENV=production, otherwise simulate (no network). | sending |

## Connections

One entry per app and provider under `connections.<name>`. Secrets are SOURCES with exactly one of `path`, `inline`, `base64` or `resolver` + `reference`; never put a closure here (config:cache). Each connection is validated at first use: a wrong key, an expired certificate or a secondary credential of another team/project is a ConfigurationException before any request.

Keys of one connection (`connections.<name>.<key>`). Transport keys left out fall back to the `transport` block.

| Key | Default | Type | Allowed | Description |
|---|---|---|---|---|
| `driver` | – | `string` | `apns`, `fcm` | Provider of the connection (required). |
| `auth` | – | `string` | `token`, `certificate`, `service_account`, `adc` | APNs: token (a .p8 key, default) or certificate (mTLS). FCM: service_account (default) or adc (Application Default Credentials, needs google/auth). |
| `team_id` | – | `string` |  | APNs: Apple team id (10 characters). |
| `key_id` | – | `string` |  | APNs token auth: id of the .p8 key. |
| `key` | – | `secret source` |  | APNs token auth: the .p8 key. |
| `certificate` | – | `secret source` |  | APNs certificate auth: the PEM certificate (may also contain the key). |
| `certificate_key` | – | `secret source` |  | APNs certificate auth: the private key when it is a separate file. |
| `passphrase` | – | `string\|secret source` |  | APNs certificate auth: the key passphrase. |
| `bundle_id` | – | `string` |  | APNs: the app bundle id (the APNs topic). |
| `environment` | `'production'` | `string` | `production`, `sandbox` | APNs: environment of the app build; never guessed. |
| `port` | `443` | `int` | `443`, `2197` | APNs port. |
| `broadcast_host` | `'standard'` | `string` | `standard`, `api-broadcast` | APNs: host used to publish Live Activity broadcasts. |
| `credentials` | – | `secret source` |  | FCM service_account auth: the service-account JSON. |
| `project_id` | – | `string\|null` |  | FCM: Firebase project id; null = from the service-account JSON (required with adc). |
| `http_version` | `'2'` | `string` | `2`, `1.1` | FCM: HTTP version. |
| `secondary` | – | `map` |  | Optional rotation twin with the same keys as the primary credential; must belong to the same team/environment (APNs) or project (FCM). |
| `live.allow` | `false` | `bool` |  | Allow live sends from this connection OUTSIDE production. |
| `live.only_to` | `[]` | `list<string\|array>` |  | Raw TEST device tokens allowed to receive live sends outside production (or [kind, token] pairs); converted to keyed indexes at load, never stored raw. |
| `live.allow_topics` | `false` | `bool` |  | Outside production, also allow FCM topics and conditions. |
| `live.allow_broadcast` | `false` | `bool` |  | Outside production, also allow APNs broadcast channels. |
| `live.allow_channel_management` | `false` | `bool` |  | Outside production, also allow creating/deleting broadcast channels. |
| `window` | – | `int` | 1..10000 | Per-connection override of transport.window. |
| `max_connections` | – | `int` | >= 1 | Per-connection override of transport.max_connections. |
| `connect_timeout_ms` | – | `int` | >= 1 | Per-connection override of transport.connect_timeout_ms. |
| `timeout_ms` | – | `int` | >= 1 | Per-connection override of transport.timeout_ms (also read by the queue timeout chain). |
| `max_idle_seconds` | – | `int` | >= 1 | Per-connection override of transport.max_idle_seconds. |
| `proxy` | – | `string\|null` |  | Per-connection override of transport.proxy. |
| `ca_bundle` | – | `string\|null` |  | Per-connection override of transport.ca_bundle. |

APNs with a `.p8` key, driven by `.env`:

```php
'connections' => [
    'ios' => [
        'driver' => 'apns',
        'auth' => 'token',
        'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
        'key_id' => env('MOBILE_PUSH_APNS_KEY_ID'),
        'key' => ['path' => env('MOBILE_PUSH_APNS_KEY_PATH')],          // or 'base64' => env('MOBILE_PUSH_APNS_KEY_BASE64')
        'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),
        'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),
        'live' => ['allow' => false, 'only_to' => []],
    ],
],
```

FCM HTTP v1 with a service account:

```php
'connections' => [
    'android' => [
        'driver' => 'fcm',
        'auth' => 'service_account',
        'credentials' => ['path' => env('MOBILE_PUSH_FCM_CREDENTIALS_PATH')],  // or 'base64' => env('MOBILE_PUSH_FCM_CREDENTIALS_BASE64')
        'project_id' => env('MOBILE_PUSH_FCM_PROJECT_ID'),                   // null = from the JSON
        'timeout_ms' => 10000,                                               // a per-connection transport override
    ],
],
```

## Transport

HTTP/2 transport defaults for every connection that does not set its own value.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `transport.window` | `MOBILE_PUSH_WINDOW` | `100` | `int` | 1..10000 | Requests multiplexed per window. | sending |
| `transport.max_connections` | `MOBILE_PUSH_MAX_CONNECTIONS` | `1` | `int` | >= 1 | HTTP/2 connections per pool. | sending |
| `transport.connect_timeout_ms` | `MOBILE_PUSH_CONNECT_TIMEOUT_MS` | `5000` | `int` | >= 1 | Connect timeout, milliseconds. | sending |
| `transport.timeout_ms` | `MOBILE_PUSH_TIMEOUT_MS` | `30000` | `int` | >= connect_timeout_ms | Request timeout, milliseconds (first link of the queue timeout chain). | sending |
| `transport.max_idle_seconds` | `MOBILE_PUSH_MAX_IDLE_SECONDS` | `3600` | `int` | >= 1 | An idle pooled connection is closed after this many seconds. | sending |
| `transport.proxy` | `MOBILE_PUSH_PROXY` | `null` | `string\|null` |  | HTTP proxy URL, e.g. http://proxy.internal:3128. | sending |
| `transport.ca_bundle` | `MOBILE_PUSH_CA_BUNDLE` | `null` | `string\|null` |  | Path of a CA bundle; null = the system store. | sending |

## Retry budget

One budget per destination, shared by every component that may retry.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `retry.max_attempts` | `MOBILE_PUSH_RETRY_MAX_ATTEMPTS` | `5` | `int` | >= 1 | Attempts per destination, the first included. | sending |
| `retry.max_elapsed` | `MOBILE_PUSH_RETRY_MAX_ELAPSED` | `3600` | `int` | >= 0 | Seconds from the first attempt after which nothing is retried. | sending |
| `retry.backoff_base_ms` | `MOBILE_PUSH_RETRY_BACKOFF_BASE_MS` | `1000` | `int` | >= 0 | First backoff, doubled at every retry. | sending |
| `retry.backoff_max_ms` | `MOBILE_PUSH_RETRY_BACKOFF_MAX_MS` | `300000` | `int` | >= 0 | Longest backoff. | sending |
| `retry.jitter` | `MOBILE_PUSH_RETRY_JITTER` | `0.2` | `float` | 0..1 | Random share added to a backoff (never earlier than a provider minimum). | sending |
| `retry.inline_threshold_ms` | `MOBILE_PUSH_RETRY_INLINE_THRESHOLD_MS` | `0` | `int` | >= 0 | Longest wait done in-process; anything longer becomes deferred. 0 = never wait. | sending |
| `unknown_outcome.retry` | `MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY` | `'replaceable'` | `string` | `never`, `replaceable`, `always` | Retry after an unknown outcome (answer lost): never, replaceable pushes only, or always. | sending |
| `apns.server_error_retry_delay` | `MOBILE_PUSH_APNS_SERVER_ERROR_RETRY_DELAY` | `900` | `int` | >= 0 | Seconds before retrying an APNs 5xx (Apple: 15 minutes). | sending |
| `apns.device_rate_limit_delay` | `MOBILE_PUSH_APNS_DEVICE_RATE_LIMIT_DELAY` | `60` | `int` | >= 0 | Seconds before retrying after APNs TooManyRequests. | sending |
| `fcm.throttled_min_delay` | `MOBILE_PUSH_FCM_THROTTLED_MIN_DELAY` | `60` | `int` | >= 0 | Minimum seconds before retrying FCM QUOTA_EXCEEDED / UNAVAILABLE (Retry-After wins when longer). | sending |

## Guards: rate limits and circuit breakers

Rates count messages per minute per provider ACCOUNT (Firebase project, Apple team), never per connection alias: two aliases of one Firebase project share one budget. They are application ceilings, not the provider quota.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `guards.rate.fcm` | `MOBILE_PUSH_RATE_FCM` | `500000` | `int\|null` | >= 1 | Messages per minute per Firebase project; null = unlimited. | sending |
| `guards.rate.apns` | `MOBILE_PUSH_RATE_APNS` | `null` | `int\|null` | >= 1 | Messages per minute per Apple team; null = unlimited. | sending |
| `guards.rate.reserved_share` | `MOBILE_PUSH_RATE_RESERVED_SHARE` | `0.2` | `float` | 0..1 | Share of the rate reserved to transactional traffic (bulk never uses it). | sending |
| `guards.rate.burst` | `MOBILE_PUSH_RATE_BURST` | `null` | `int\|null` | >= 1 | Bucket capacity; null = one minute of traffic. | sending |
| `guards.auth.threshold` | `MOBILE_PUSH_AUTH_BREAKER_THRESHOLD` | `5` | `int` | >= 1 | Consecutive credential failures that open the authentication breaker. | sending |
| `guards.auth.cooldown` | `MOBILE_PUSH_AUTH_BREAKER_COOLDOWN` | `60` | `int` | >= 1 | Seconds before a probe of an open authentication breaker. | sending |
| `guards.destination_quality.min_sample` | `MOBILE_PUSH_QUALITY_MIN_SAMPLE` | `200` | `int` | >= 1 | Minimum results before the destination-quality breaker can open. | sending |
| `guards.destination_quality.threshold` | `MOBILE_PUSH_QUALITY_THRESHOLD` | `0.5` | `float` | 0.01..0.99 | Share of bad destinations that opens the destination-quality breaker. | sending |
| `guards.destination_quality.cooldown` | `MOBILE_PUSH_QUALITY_COOLDOWN` | `300` | `int` | >= 1 | Seconds before a probe of an open destination-quality breaker. | sending |

## Delivery profile and events

The light profile is best effort and keeps no tables; the durable profile records every queued effect in the ledger first. Only queue() uses the ledger: send() is synchronous in both profiles.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `delivery.profile` | `MOBILE_PUSH_DELIVERY_PROFILE` | `'light'` | `string` | `light`, `durable` | Delivery profile of queue(). | sending |
| `events.per_result` | `MOBILE_PUSH_EVENTS_PER_RESULT` | `'auto'` | `string` | `auto`, `always`, `never` | When to dispatch PushResultProduced per destination: auto = transactional sends only. Pulse needs always to see the reason codes of bulk traffic. | sending |
| `connection_cache.size` | `MOBILE_PUSH_CONNECTION_CACHE_SIZE` | `64` | `int` | 1..100000 | Resolved connections (drivers, pools) kept per process. | sending |

## Migrations

Where the tables of the modules come from. By default the migrations are published into your application (`mobile-push:install` does it) and the modules never register their own copies with `migrate`.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `migrations.autoload` | `MOBILE_PUSH_MIGRATIONS_AUTOLOAD` | `false` | `bool` |  | false (default): module migrations are published by mobile-push:install and `migrate` runs the copies. true: enabled modules register their package files with `migrate` and nothing is published (a copy already published wins). | install |

## Shared cache

Provider tokens, breakers, rate limits, throttle gates, metrics, frequency counters and badges are shared between workers through this store. Cross-process guarantees need a lock-capable shared store (redis, memcached, database, dynamodb).

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `cache.store` | `MOBILE_PUSH_CACHE_STORE` | `null` | `string\|null` |  | Cache store name from config/cache.php; null = the default store. | cache |
| `cache.prefix` | `MOBILE_PUSH_CACHE_PREFIX` | `'mobile-push'` | `string` |  | Prefix of every cache key of the package. | cache |

## Privacy keys

Two independent key rings: the HMAC lookup index of device tokens and the encryption at rest (also keys the ledger payload fingerprint). Each is a map version => base64 key (>= 32 bytes). Empty = version 1 derived from APP_KEY with a purpose-specific HKDF label. To rotate, add a version and keep the old ones until every row is re-indexed / re-encrypted.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `privacy.index_keys` | `MOBILE_PUSH_INDEX_KEY` | `[]` (derived from APP_KEY) | `map<int, string>` |  | Index key ring (the env var sets version 1). | privacy |
| `privacy.index_key_current` | `MOBILE_PUSH_INDEX_KEY_VERSION` | `0` | `int` |  | Version used for new writes; 0 = the highest. | privacy |
| `privacy.encryption_keys` | `MOBILE_PUSH_ENCRYPTION_KEY` | `[]` (derived from APP_KEY) | `map<int, string>` |  | Encryption key ring (the env var sets version 1). | privacy |
| `privacy.encryption_key_current` | `MOBILE_PUSH_ENCRYPTION_KEY_VERSION` | `0` | `int` |  | Version used for new writes; 0 = the highest. | privacy |

## Device registry

Installations stored per owner so MobilePush::to($user) works. Tokens are encrypted at rest and looked up through the keyed index; the environment is the one the app declares at registration.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `devices.enabled` | `MOBILE_PUSH_DEVICES_ENABLED` | `false` | `bool` |  | Enable the registry (its migration is published by mobile-push:install, or auto-loaded with migrations.autoload). | devices |
| `devices.table` | `MOBILE_PUSH_DEVICES_TABLE` | `'mobile_push_devices'` | `string` |  | Table name. | devices |
| `devices.connection` | `MOBILE_PUSH_DEVICES_CONNECTION` | `null` | `string\|null` |  | Database connection; null = default. | devices |
| `devices.model` | – | `\Padosoft\MobilePush\Laravel\Devices\PushDevice::class` | `class-string` |  | Eloquent model (extend PushDevice to customise). | devices |
| `devices.probe.cooldown_hours` | `MOBILE_PUSH_DEVICES_PROBE_COOLDOWN_HOURS` | `24` | `int` | >= 0 | mobile-push:devices:probe-environment contacts a device at most once per this many hours (0 = no cooldown). | console |
| `devices.prune.invalidated_days` | – | `30` | `int` | >= 0 | mobile-push:devices:prune deletes invalidated rows older than this. | console |
| `devices.prune.inactive_days` | – | `365` | `int` | >= 0 | mobile-push:devices:prune deletes rows not seen for this many days. | console |

## Send log

Any combination of sinks. Nothing sensitive is written: a destination is only its keyed index and masked form; bearers and receipt markers are redacted. A failing sink never affects a send.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `log.sinks` | `MOBILE_PUSH_LOG_SINKS` | `[]` | `list<string>` | `log`, `database`, `otel` | Sinks, comma-separated in the env var; empty = no send log. | logging |
| `log.payload` | `MOBILE_PUSH_LOG_PAYLOAD` | `'none'` | `string` | `none`, `keys`, `full` | How much of the message is recorded (full is redacted). | logging |
| `log.channel` | `MOBILE_PUSH_LOG_CHANNEL` | `null` | `string\|null` |  | Laravel log channel of the "log" sink; null = default. | logging |
| `log.levels` | – | `[]` | `map<string, string>` |  | Status => PSR-3 level overrides (defaults: accepted/simulated/validated debug; deferred/skipped info; the rest warning). | logging |
| `log.buffer.size` | `MOBILE_PUSH_LOG_BUFFER_SIZE` | `100` | `int` | >= 0 | Records buffered before a flush. | logging |
| `log.buffer.max` | `MOBILE_PUSH_LOG_BUFFER_MAX` | `10000` | `int` | >= 1 | Buffer ceiling; older records are dropped (counted) beyond it. | logging |
| `log.database.table` | `MOBILE_PUSH_LOG_TABLE` | `'mobile_push_logs'` | `string` |  | Table of the database sink. | logging |
| `log.database.connection` | `MOBILE_PUSH_LOG_CONNECTION` | `null` | `string\|null` |  | Database connection of the database sink. | logging |
| `log.database.mode` | `MOBILE_PUSH_LOG_DB_MODE` | `'failures'` | `string` | `all`, `failures`, `sampled` | Which outcomes the database sink keeps. | logging |
| `log.database.sample_rate` | `MOBILE_PUSH_LOG_DB_SAMPLE_RATE` | `0.01` | `float` | 0..1 | Share of routine successes kept in sampled mode. | logging |
| `log.database.retention_days` | `MOBILE_PUSH_LOG_RETENTION_DAYS` | `30` | `int` | >= 1 | Rows older than this are pruned (mobile-push:logs:prune). | logging |
| `log.database.insert_chunk` | – | `100` | `int` | >= 1 | Rows per INSERT. | logging |

## Durable ledger

Used by queue() when delivery.profile = durable: one row per effect and per destination, written before dispatch (inside the caller transaction on this connection).

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `ledger.connection` | `MOBILE_PUSH_LEDGER_CONNECTION` | `null` | `string\|null` |  | Database connection; null = default. | ledger |
| `ledger.tables.effects` | `MOBILE_PUSH_LEDGER_EFFECTS_TABLE` | `'mobile_push_effects'` | `string` |  | Effects table. | ledger |
| `ledger.tables.deliveries` | `MOBILE_PUSH_LEDGER_DELIVERIES_TABLE` | `'mobile_push_deliveries'` | `string` |  | Deliveries table. | ledger |
| `ledger.lease_seconds` | `MOBILE_PUSH_LEDGER_LEASE_SECONDS` | `120` | `int` | >= 1 | Lease of a claimed delivery; must exceed queue.chunk_deadline. | ledger |
| `ledger.dedupe_seconds` | `MOBILE_PUSH_LEDGER_DEDUPE_SECONDS` | `86400` | `int` | >= 0 | Window in which the same effect key is the same effect. | ledger |
| `ledger.chunk_size` | `MOBILE_PUSH_LEDGER_CHUNK_SIZE` | `500` | `int` | 1..5000 | Rows per insert / claim. | ledger |
| `ledger.retention_days` | `MOBILE_PUSH_LEDGER_RETENTION_DAYS` | `30` | `int` | >= 0 | mobile-push:deliveries:prune keeps finished deliveries this many days. | console |
| `ledger.claim_strategy` | `MOBILE_PUSH_LEDGER_CLAIM_STRATEGY` | `'auto'` | `string` | `auto`, `skip_locked`, `optimistic` | How workers claim rows (auto = SKIP LOCKED where the database supports it). | ledger |

## Queue

The QUEUE connection and names used by queue() (distinct from the push connection). Timeout chain checked by mobile-push:doctor: transport timeout < chunk_deadline < timeout < worker timeout < retry_after (SQS: visibility timeout).

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `queue.connection` | `MOBILE_PUSH_QUEUE_CONNECTION` | `null` | `string\|null` |  | Queue connection; null = the application default. | queue |
| `queue.queue` | `MOBILE_PUSH_QUEUE` | `'push'` | `string` |  | Queue of transactional traffic. | queue |
| `queue.bulk_queue` | `MOBILE_PUSH_BULK_QUEUE` | `'push-bulk'` | `string` |  | Queue of bulk() traffic. | queue |
| `queue.chunk` | `MOBILE_PUSH_QUEUE_CHUNK` | `500` | `int` | 1..10000 | Destinations per light job / deliveries per relay claim. | queue |
| `queue.chunk_bytes` | `MOBILE_PUSH_QUEUE_CHUNK_BYTES` | `98304` | `int` | >= 4096 | Estimated bytes of one light job before encryption. | queue |
| `queue.timeout` | `MOBILE_PUSH_QUEUE_TIMEOUT` | `50` | `int` | >= 1 | Job timeout, seconds. | queue |
| `queue.chunk_deadline` | `MOBILE_PUSH_QUEUE_CHUNK_DEADLINE` | `40` | `int` | >= 1 | Wall time of one relay run before it hands over, seconds. | queue |
| `queue.after_commit` | `MOBILE_PUSH_QUEUE_AFTER_COMMIT` | `true` | `bool` |  | Dispatch light jobs after the open transaction commits. | queue |
| `queue.follow_up_bucket` | `MOBILE_PUSH_QUEUE_FOLLOW_UP_BUCKET` | `60` | `int` | >= 0 | Deferred destinations whose next attempts are this close share one continuation job, seconds. | queue |
| `queue.worker_timeout` | `MOBILE_PUSH_QUEUE_WORKER_TIMEOUT` | `null` | `int\|null` |  | Declared worker timeout when it is not readable from Horizon. | queue |
| `queue.visibility_timeout` | `MOBILE_PUSH_QUEUE_VISIBILITY_TIMEOUT` | `null` | `int\|null` |  | Declared SQS visibility timeout. | queue |
| `queue.sweeper.schedule` | `MOBILE_PUSH_QUEUE_SWEEPER` | `true` | `bool` |  | Durable profile: schedule the ledger sweeper every minute. | queue |
| `queue.sweeper.scopes_per_run` | – | `100` | `int` | >= 1 | Scopes the sweeper visits per run. | queue |

## Client receipts

Durable profile only. The app reports received/opened with the opaque `mp_r` marker; the HMAC key stays on the server and the marker is never logged. No receipt exists for topics and broadcasts.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `receipts.enabled` | `MOBILE_PUSH_RECEIPTS_ENABLED` | `false` | `bool` |  | Enable markers and the receipts endpoint. | receipts |
| `receipts.marker_ttl` | `MOBILE_PUSH_RECEIPTS_TTL` | `604800` | `int` | >= 60 | Seconds a marker is accepted. | receipts |
| `receipts.max_batch` | – | `20` | `int` | >= 1 | Reports per request. | receipts |
| `receipts.route.prefix` | `MOBILE_PUSH_RECEIPTS_PREFIX` | `'mobile-push'` | `string` |  | Route prefix: POST {prefix}/receipts. | receipts |
| `receipts.route.domain` | – | `null` | `string\|null` |  | Route domain. | receipts |
| `receipts.route.middleware` | – | `['throttle:mobile-push-receipts']` | `list<string>` |  | Route middleware (no auth by default: the marker is the proof). | receipts |
| `receipts.rate_limit.per_ip` | – | `120` | `int` | >= 0 | Requests per minute per IP; 0 = no limit. | receipts |
| `receipts.rate_limit.per_marker` | – | `10` | `int` | >= 0 | Reports per minute per marker; 0 = no limit. | receipts |
| `receipts.keys` | `MOBILE_PUSH_RECEIPTS_KEY` | `[]` (derived from APP_KEY) | `map<int, string>` |  | Marker key ring, version (1..255) => base64 key >= 32 bytes (the env var sets version 1). | receipts |
| `receipts.key_current` | `MOBILE_PUSH_RECEIPTS_KEY_VERSION` | `0` | `int` |  | Version used for new markers; 0 = the highest. | receipts |

## Metrics and health

Counters in the package cache with bounded labels only (connection, provider, environment, status, reason code, traffic class). The Prometheus endpoint is off by default and refuses everything until a token is set.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `metrics.enabled` | `MOBILE_PUSH_METRICS_ENABLED` | `true` | `bool` |  | Record metrics for every send. | health |
| `metrics.max_series` | – | `1000` | `int` | >= 10 | Ceiling of distinct series. | health |
| `metrics.prometheus.enabled` | `MOBILE_PUSH_PROMETHEUS_ENABLED` | `false` | `bool` |  | Expose GET {path} in the Prometheus text format. | health |
| `metrics.prometheus.path` | – | `'mobile-push/metrics'` | `string` |  | Endpoint path. | health |
| `metrics.prometheus.middleware` | – | `[\Padosoft\MobilePush\Laravel\Health\RequireMetricsToken::class]` | `list<string>` |  | Endpoint middleware; [] makes it public. | health |
| `metrics.prometheus.token` | `MOBILE_PUSH_METRICS_TOKEN` | `null` | `string\|null` |  | Bearer token required by RequireMetricsToken. | health |
| `health.ledger_backlog_seconds` | – | `300` | `int` | >= 1 | Oldest due delivery older than this: readiness warning. | health |
| `health.ledger_backlog_failed_seconds` | – | `3600` | `int` | >= ledger_backlog_seconds | Oldest due delivery older than this: readiness failed. | health |
| `health.spatie.register` | – | `true` | `bool` |  | Register the check with spatie/laravel-health when installed. | health |
| `credentials.warn_days` | – | `30` | `int` | >= 0 | Warn this many days before a certificate expires. | health |

## Operational alerts

Mail and/or Slack when a circuit opens, a credential fails over, expires or is about to, the error rate is too high, the ledger backlog grows or a sink keeps failing. Once per cooldown; evaluated every minute by the scheduler when enabled.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `alerts.enabled` | `MOBILE_PUSH_ALERTS_ENABLED` | `false` | `bool` |  | Enable alerts (and their scheduled evaluation). | health |
| `alerts.mail` | `MOBILE_PUSH_ALERT_MAIL` | `[]` | `list<string>` |  | Recipients, comma-separated in the env var. | health |
| `alerts.slack_webhook` | `MOBILE_PUSH_ALERT_SLACK` | `null` | `string\|null` |  | Slack incoming-webhook URL (https). | health |
| `alerts.cooldown_seconds` | – | `3600` | `int` | >= 1 | Minimum seconds between two alerts of the same kind and subject. | health |
| `alerts.send_in_simulate` | – | `false` | `bool` |  | Also deliver alerts in simulate mode (otherwise only logged). | health |
| `alerts.error_rate.threshold` | – | `0.5` | `float` | 0..1 | Error share of a connection that raises an alert. | health |
| `alerts.error_rate.min_sample` | – | `50` | `int` | >= 1 | Minimum results in the window. | health |
| `alerts.error_rate.window_seconds` | – | `300` | `int` | >= 60 | Error-rate window. | health |

## Live Activities

Activity registry, push-to-start and update tokens, broadcast channels. Coalesced same-second updates and planned retries are flushed every minute by the scheduler when enabled.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `live_activities.enabled` | `MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED` | `false` | `bool` |  | Enable the module (its migrations are published by mobile-push:install, or auto-loaded with migrations.autoload). | live_activities |
| `live_activities.binding` | `MOBILE_PUSH_LA_BINDING` | `'apns'` | `string` |  | APNs connection used by default. | live_activities |
| `live_activities.connection` | `MOBILE_PUSH_LA_DB_CONNECTION` | `null` | `string\|null` |  | Database connection of the tables. | live_activities |
| `live_activities.table` | – | `'mobile_push_live_activities'` | `string` |  | Activities table. | live_activities |
| `live_activities.push_to_start_table` | – | `'mobile_push_live_activity_push_to_start_tokens'` | `string` |  | Push-to-start tokens table. | live_activities |
| `live_activities.channels_table` | – | `'mobile_push_broadcast_channels'` | `string` |  | Broadcast channels table. | live_activities |
| `live_activities.models.activity` | – | `\Padosoft\MobilePush\Laravel\LiveActivities\PushActivity::class` | `class-string` |  | Activity model. | live_activities |
| `live_activities.models.push_to_start_token` | – | `\Padosoft\MobilePush\Laravel\LiveActivities\PushToStartToken::class` | `class-string` |  | Push-to-start token model. | live_activities |
| `live_activities.models.broadcast_channel` | – | `\Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel::class` | `class-string` |  | Broadcast channel model. | live_activities |
| `live_activities.types` | – | `[]` | `map<string, class-string>` |  | Key => class implementing LiveActivityType. | live_activities |
| `live_activities.default_priority` | `MOBILE_PUSH_LA_PRIORITY` | `'high'` | `string` | `high`, `normal` | Priority of updates that do not choose one. | live_activities |
| `live_activities.high_priority.max_per_hour` | `MOBILE_PUSH_LA_HIGH_PRIORITY_PER_HOUR` | `20` | `int\|null` |  | High-priority updates per activity per hour; beyond it they go at priority 5. null = no limit. | live_activities |
| `live_activities.local_expiry.hours` | – | `8` | `int` | >= 1 | Hours after which iOS ends an activity: nothing is sent after it. | live_activities |
| `live_activities.local_expiry.from` | – | `'start'` | `string` | `start`, `last_update` | Instant the local expiry counts from. | live_activities |
| `live_activities.max_update_retries` | – | `3` | `int` | >= 0 | Retries of a failed replaceable update. | live_activities |
| `live_activities.prune.closed_after_days` | – | `7` | `int` | >= 0 | Closed activities are pruned after this many days. | live_activities |
| `live_activities.channels.max_per_environment` | `MOBILE_PUSH_LA_CHANNEL_CAP` | `9500` | `int` | 1..9999 | Local cap of broadcast channels per app and environment (Apple allows 10,000). | live_activities |
| `live_activities.channels.cleanup_after_hours` | – | `24` | `int` | >= 0 | Unused channels are deleted after this many hours. | live_activities |

## Frequency cap and badge

Decided once per logical effect and per person (all devices of a user count once), never per device or retry. Never applied to topics, broadcasts or Live Activity transitions; bypassFrequencyCap() never bypasses consent. The badge counter acts only for messages with incrementBadge(): every device of the owner gets the same number once per effect; reset it with MobilePush::resetBadge($owner).

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `frequency.enabled` | `MOBILE_PUSH_FREQUENCY_ENABLED` | `false` | `bool` |  | Enable the frequency cap. | policies |
| `frequency.limits` | – | `['default' => ['per_hour' => 5, 'per_day' => 20], 'marketing' => ['per_day' => 2], 'security' => 'unlimited']` | `map<string, array\|string>` |  | Bucket => [per_hour, per_day] (either or both) or "unlimited"; a message picks its bucket with ->policy(). | policies |
| `frequency.no_policy_bucket` | `MOBILE_PUSH_FREQUENCY_NO_POLICY_BUCKET` | `'default'` | `string\|null` |  | Bucket of messages without a policy; null or "" = not capped. | policies |
| `frequency.on_store_failure` | `MOBILE_PUSH_FREQUENCY_ON_STORE_FAILURE` | `'allow'` | `string` | `allow`, `deny` | What to do when the counters cannot be read. | policies |
| `badge.enabled` | `MOBILE_PUSH_BADGE_ENABLED` | `true` | `bool` |  | Resolve incrementBadge() on the server. | policies |
| `badge.ttl_days` | `MOBILE_PUSH_BADGE_TTL_DAYS` | `90` | `int` | >= 1 | An idle counter is forgotten after this many days. | policies |
| `badge.per_binding` | `MOBILE_PUSH_BADGE_PER_BINDING` | `false` | `bool` |  | One counter per connection instead of one per owner. | policies |

## Fallback to another channel

Only for notifications that opt in (pushFallback()): when every push ended without being accepted (never after an unknown outcome or an opt-out), the fallback channel is sent once.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `fallback.enabled` | `MOBILE_PUSH_FALLBACK_ENABLED` | `true` | `bool` |  | Master switch (a notification still needs the opt-in). | notifications |
| `fallback.when_no_destination` | `MOBILE_PUSH_FALLBACK_WHEN_NO_DESTINATION` | `true` | `bool` |  | Also fall back when the notifiable has no push destination at all. | notifications |
| `fallback.idempotency_ttl` | `MOBILE_PUSH_FALLBACK_IDEMPOTENCY_TTL` | `86400` | `int` | >= 1 | Seconds "this fallback was sent" is remembered. | notifications |
| `fallback.cache_store` | `MOBILE_PUSH_FALLBACK_CACHE_STORE` | `null` | `string\|null` |  | Cache store of that memory; null = cache.store. | notifications |

## Campaigns

Durable profile only: a stable upper bound fixed at planning time, a final eligibility check before the network, pause/resume/cancel.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `campaigns.enabled` | `MOBILE_PUSH_CAMPAIGNS_ENABLED` | `false` | `bool` |  | Enable campaigns (the migration is published by mobile-push:install, or auto-loaded with migrations.autoload). | campaigns |
| `campaigns.table` | – | `'mobile_push_campaigns'` | `string` |  | Campaigns table. | campaigns |
| `campaigns.connection` | – | `null` | `string\|null` |  | Database connection; null = default. | campaigns |
| `campaigns.slice` | – | `1000` | `int` | 1..5000 | Destinations per planner job. | campaigns |
| `campaigns.default_rate_per_minute` | – | `null` | `int\|null` |  | Planner pacing when a campaign sets none; null = as fast as possible. | campaigns |
| `campaigns.retention_days` | – | `30` | `int` | >= 1 | Finished campaigns are pruned after this many days (also the ledger dedupe window of a campaign). | campaigns |
| `campaigns.sweeper.schedule` | – | `true` | `bool` |  | Schedule the campaign sweeper every minute. | campaigns |
| `campaigns.audiences` | – | `[]` | `map<string, class-string>` |  | Alias => Audience class. | campaigns |

## Laravel Pulse, Telescope and test fakes

Optional integrations, active only when the package is installed, and the guard of the test fakes.

| Key | Env | Default | Type | Allowed | Description | Module |
|---|---|---|---|---|---|---|
| `pulse.enabled` | `MOBILE_PUSH_PULSE_ENABLED` | `false` | `bool` |  | Feed the Pulse card. | pulse |
| `pulse.sample_rate` | `MOBILE_PUSH_PULSE_SAMPLE_RATE` | `1.0` | `float` | 0..1 | Share of events recorded. | pulse |
| `pulse.max_connections` | – | `50` | `int` | >= 1 | Distinct connection names used in Pulse keys. | pulse |
| `testing.allow_fakes` | – | `false` | `bool` |  | Allow MobilePush::fake() / fakeTransport() outside APP_ENV=testing (never in a real deployment). | testing |
| `telescope.enabled` | `MOBILE_PUSH_TELESCOPE_ENABLED` | `'auto'` | `string\|bool` | `auto`, `true`, `false` | Record package entries in Telescope: auto = when Telescope is enabled. | telescope |

---

*This page is generated from [`docs/configuration.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/configuration.md). To change it, edit that file in the repository, not this site.*
