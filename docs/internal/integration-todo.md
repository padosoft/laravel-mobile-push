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
