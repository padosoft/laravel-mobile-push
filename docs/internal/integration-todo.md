# Integration to-do (orchestrator)

Pending wiring and config keys reported by finished slices. `config/mobile-push.php` is owned by W3-A until it returns; apply everything below in the integration pass right after.

## Config keys to add

### Ledger (W2-P4)
| Key | Env | Default | Allowed |
|---|---|---|---|
| `delivery.profile` | `MOBILE_PUSH_DELIVERY_PROFILE` | `light` | `light`, `durable` |
| `ledger.connection` | `MOBILE_PUSH_LEDGER_CONNECTION` | null | database connection name |
| `ledger.tables.effects` | `MOBILE_PUSH_LEDGER_EFFECTS_TABLE` | `mobile_push_effects` | table name |
| `ledger.tables.deliveries` | `MOBILE_PUSH_LEDGER_DELIVERIES_TABLE` | `mobile_push_deliveries` | table name |
| `ledger.lease_seconds` | `MOBILE_PUSH_LEDGER_LEASE_SECONDS` | 120 | ≥ 1, must exceed the chunk deadline |
| `ledger.dedupe_seconds` | `MOBILE_PUSH_LEDGER_DEDUPE_SECONDS` | 86400 | ≥ 0 |
| `ledger.chunk_size` | `MOBILE_PUSH_LEDGER_CHUNK_SIZE` | 500 | 1–5000 |
| `ledger.claim_strategy` | `MOBILE_PUSH_LEDGER_CLAIM_STRATEGY` | `auto` | `auto`, `skip_locked`, `optimistic` |

### Health, metrics, alerts (W3-E)
| Key | Env | Default | Allowed |
|---|---|---|---|
| `metrics.enabled` | `MOBILE_PUSH_METRICS` | true | bool |
| `metrics.max_series` | – | 1000 | int ≥ 10 |
| `metrics.prometheus.enabled` | `MOBILE_PUSH_PROMETHEUS` | false | bool |
| `metrics.prometheus.path` | – | `mobile-push/metrics` | string |
| `metrics.prometheus.middleware` | – | `[RequireMetricsToken::class]` | list; `[]` makes the endpoint public |
| `metrics.prometheus.token` | `MOBILE_PUSH_METRICS_TOKEN` | null | string |
| `health.ledger_backlog_seconds` | – | 300 | int |
| `health.ledger_backlog_failed_seconds` | – | 3600 | int ≥ warning threshold |
| `health.spatie.register` | – | true | bool |
| `alerts.enabled` | `MOBILE_PUSH_ALERTS` | false | bool |
| `alerts.mail` | `MOBILE_PUSH_ALERT_MAIL` | [] | list or comma string |
| `alerts.slack_webhook` | `MOBILE_PUSH_ALERT_SLACK` | null | https URL |
| `alerts.cooldown_seconds` | – | 3600 | int |
| `alerts.send_in_simulate` | – | false | bool |
| `alerts.error_rate.threshold` | – | 0.5 | 0..1 |
| `alerts.error_rate.min_sample` | – | 50 | int |
| `alerts.error_rate.window_seconds` | – | 300 | int ≥ 60 |
| `credentials.warn_days` | – | 30 | int |

Health reads `mobile-push.queue.connection` and `mobile-push.queue.timeout` (names guessed): align with the queue module's real keys.

## Wiring to do

- Sender observer → `Padosoft\MobilePush\Laravel\Health\MetricsRecorder::record(BindingRef, SendResult, TrafficClass)`, `recordThrottle(...)`, `recordGuardTransition(GuardTransition)`; and `OpsAlerter::guardTransition(GuardTransition)`.
- Scheduler (about once a minute) → `OpsAlerter::evaluate()`.
- Bind a real `Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource` from the manager's connections (the module registers a null source with `bindIf`).
- Commands still to write: `mobile-push:health`, `mobile-push:credentials:check` (uses `CredentialMonitor::check()`), `mobile-push:logs:prune` (`LogPruner`, `log.database.retention_days`), `mobile-push:devices:prune` (`DevicePruner`), `mobile-push:relay` / sweeper.
- Sender observer → `SinkManager::recordResult()` with runtime secrets in `secrets` (W3-A is doing this).

## Small backlog

- `EndpointCatalog::fcmTopicSubscriptionItemUrl()` (the topic client appends the topic segment itself today).
- A dedicated `ReasonCode` for the APNs channel limit (`CannotCreateChannelConfig` maps to `QuotaExceeded` today).
- Make `tests/Scenario/Transport/ConnectionPoolTest` ("cleans up when the consumer stops early", `clientResets`) deterministic: it failed once in a full run.
- Fingerprint of the ledger payload is an unkeyed SHA-256: consider keying it (short messages such as OTPs could be guessed offline).
- Real-database concurrency tests for the ledger claim (`SKIP LOCKED`) on MySQL and PostgreSQL in CI; Redis for cache locks.

### Receipts (W3-D)
| Key | Env | Default | Allowed |
|---|---|---|---|
| `receipts.enabled` | `MOBILE_PUSH_RECEIPTS_ENABLED` | false | bool |
| `receipts.marker_ttl` | `MOBILE_PUSH_RECEIPTS_TTL` | 604800 | seconds ≥ 60 |
| `receipts.max_batch` | – | 20 | ≥ 1 |
| `receipts.route.prefix` | `MOBILE_PUSH_RECEIPTS_PREFIX` | `mobile-push` | string (`POST {prefix}/receipts`) |
| `receipts.route.domain` | – | null | string or null |
| `receipts.route.middleware` | – | `['throttle:mobile-push-receipts']` | list; no auth by default |
| `receipts.rate_limit.per_ip` | – | 120 per minute | 0 disables |
| `receipts.rate_limit.per_marker` | – | 10 per minute | 0 disables |
| `receipts.keys` | – | derived from APP_KEY | map version (1–255) => base64 key ≥ 32 bytes |
| `receipts.key_current` | – | 0 | 0 = highest version |

Receipts wiring: the relay passes `ReceiptMarkerIssuer::callback($scope)` to `SendOptions::withReceiptMarker()` (told to W3-B). Client contract for the docs: `src/Laravel/Receipts/CLIENT_CONTRACT.md`.
Decision: the ledger assigns each delivery a random UUIDv4 `message_id` at insert (W3-FIX2), used as `apns-id` and as the receipt reference; the ULID-derived UUID path is only a fallback.

### Queue (W3-B)
| Key | Env | Default | Allowed |
|---|---|---|---|
| `queue.connection` | `MOBILE_PUSH_QUEUE_CONNECTION` | null (app default) | queue connection name |
| `queue.queue` | `MOBILE_PUSH_QUEUE` | `push` | non-empty string |
| `queue.bulk_queue` | `MOBILE_PUSH_BULK_QUEUE` | `push-bulk` | non-empty string (bulk traffic) |
| `queue.chunk` | `MOBILE_PUSH_QUEUE_CHUNK` | 500 | 1–10000 |
| `queue.chunk_bytes` | `MOBILE_PUSH_QUEUE_CHUNK_BYTES` | 98304 | ≥ 4096 |
| `queue.timeout` | `MOBILE_PUSH_QUEUE_TIMEOUT` | 120 | seconds ≥ 1 |
| `queue.chunk_deadline` | `MOBILE_PUSH_QUEUE_CHUNK_DEADLINE` | 60 | seconds ≥ 1 |
| `queue.after_commit` | `MOBILE_PUSH_QUEUE_AFTER_COMMIT` | true | bool |
| `queue.follow_up_bucket` | `MOBILE_PUSH_QUEUE_FOLLOW_UP_BUCKET` | 60 | seconds ≥ 0 |
| `queue.worker_timeout` | `MOBILE_PUSH_QUEUE_WORKER_TIMEOUT` | null | seconds (declare when not on Horizon) |
| `queue.visibility_timeout` | `MOBILE_PUSH_QUEUE_VISIBILITY_TIMEOUT` | null | seconds (SQS) |
| `queue.sweeper.schedule` | `MOBILE_PUSH_QUEUE_SWEEPER` | true | bool |
| `queue.sweeper.scopes_per_run` | – | 100 | ≥ 1 |

The queue module reads `transport.timeout_ms` and `connections.*.timeout_ms` (names guessed): align with W3-A's real transport keys.

Queue follow-ups: `SendPushJob::failed()` logs to the default logger, not to the log sinks; `DeviceEligibility::checkMany()` does not return rows so `RegistryResolver` duplicates its rules (add a `resolveMany()` on the devices side); the ledger has no "effects with due work" query (the sweeper dispatches scope-wide relays); `tests/Scenario/Send/SendKit::apnsToken()` pads with 'a' so token 10 equals token 170.

### Live Activities (W3-L) — passed to W4-I as section D
- Device-only gates (eligibility, frequency cap) must ignore Live Activity destination kinds: their `DestinationRef` ids are rows of the Live Activities tables.
- `ApnsDriver::connectionProfile()` must support `EndpointRole::ApnsChannelManagement`.
- Bind `EndpointCatalog` in the container.
- Config keys under `mobile-push.live_activities.*` (enabled, binding, connection, tables, models, types, default_priority, high_priority.max_per_hour, local_expiry, max_update_retries, prune, channels).
- Schedule `ActivityManager::flushDue()`; queued job carrying `ActivityTicket`.
- Commands still to write (later task): `live-activity:{start,update,end,prune}`, `channels:{list,create,delete,sync}`, `campaign:{status,pause,resume,cancel}`.
- Client contract for the docs is in the W3-L report (progress.md) and must be turned into `docs/live-activities.md`.

### Testing fakes (W4-T) — follow-up after W4-I releases `src/Laravel/Sending`
- The fakes work through reflection into `PushManager`'s private connection cache and by making `$app->environment()` read `production` while a fake is active (app-wide side effect: host code calling `app()->isProduction()` sees true during a faked test). Replace both with official seams: an execution override on the manager (`forceExecution(?ExecutionMode, ?LiveSettings)`) and a supported way to install/remove a driver for a connection name; then remove the reflection and the environment toggle from `src/Laravel/Testing`.
- `PendingSend::options()` dropped `SendOptions::$observers` (passed to W4-I as F1).
- Runtime `register()` cannot be undone (`unregister`), which the fakes need for cleanup.
- Config key: `testing.allow_fakes` (bool, default false, no env).

### Whole-suite state on 2026-11-21 (2178 tests)
- `tests/Unit/Laravel/Policies/PoliciesModuleTest` (2 cases) red while W4-I changes the `frequency.enabled` default (passed to W4-I as F2).
- `tests/Scenario/Laravel/Queue/LightProfileTest` "queue it and forget" fails only in whole-suite runs (passes alone and with every sub-group tried): order- or load-dependent; under investigation.

### Operational commands and erasure (W4-K2)
Config keys to add: `ledger.retention_days` (MOBILE_PUSH_LEDGER_RETENTION_DAYS, 30), `devices.prune.invalidated_days` (MOBILE_PUSH_DEVICES_PRUNE_INVALIDATED_DAYS, 30), `devices.prune.inactive_days` (MOBILE_PUSH_DEVICES_PRUNE_INACTIVE_DAYS, 365).
Follow-ups found:
- `DeviceRegistrar::forgetOwner()` only detaches the owner and keeps the encrypted token: it is a logout, not an erasure (the `forget` command deletes the rows itself). Rename/document.
- `DatabaseLedger::forgetOwner()`: deliveries to raw tokens carry no owner and cannot be found by owner; it erases the message snapshot of an effect that also addressed other recipients (their deliveries then end Skipped/Cancelled); `effect_key` is not erased.
- `TopicSubscriptionClient` is not constructed anywhere in the Laravel layer except by the command.
- Commands resolve `PushManager` directly because `PushDispatcher` cannot enumerate connections.
- Schedule suggestions: `deliveries:prune`, `devices:prune`, `logs:prune`, `credentials:check`.
- RESOLVED: `LightProfileTest` whole-suite failure was `queue:work`'s default `--memory=128` (now `--memory=2048` in the test).
