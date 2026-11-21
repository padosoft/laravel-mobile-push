# Integration to-do (orchestrator)

Pending wiring and config keys reported by finished slices. `config/mobile-push.php` is owned by W3-A until it returns; apply everything below in the integration pass right after.

## Config keys to add

**[x] W4-I: every key below is in `config/mobile-push.php` and in `Padosoft\MobilePush\Laravel\Integration\ConfigSchema` (`docs/configuration.md` is generated; a test enforces it). Env names of module flags follow the `_ENABLED` pattern (see the W4-I section at the end).**

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

(W4-I: items marked [x] are done; see the W4-I section at the end.)

- [x] Sender observer → `Padosoft\MobilePush\Laravel\Health\MetricsRecorder::record(BindingRef, SendResult, TrafficClass)`, `recordThrottle(...)`, `recordGuardTransition(GuardTransition)`; and `OpsAlerter::guardTransition(GuardTransition)`.
- [x] Scheduler (about once a minute) → `OpsAlerter::evaluate()`.
- [x] Bind a real `Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource` from the manager's connections (the module registers a null source with `bindIf`).
- Commands still to write: `mobile-push:health`, `mobile-push:credentials:check` (uses `CredentialMonitor::check()`), `mobile-push:logs:prune` (`LogPruner`, `log.database.retention_days`), `mobile-push:devices:prune` (`DevicePruner`), `mobile-push:relay` / sweeper.
- Sender observer → `SinkManager::recordResult()` with runtime secrets in `secrets` (W3-A is doing this).

## Small backlog

- [x] `EndpointCatalog::fcmTopicSubscriptionItemUrl()` (the topic client appends the topic segment itself today).
- [x] A dedicated `ReasonCode` for the APNs channel limit (`CannotCreateChannelConfig` maps to `QuotaExceeded` today).
- Make `tests/Scenario/Transport/ConnectionPoolTest` ("cleans up when the consumer stops early", `clientResets`) deterministic: it failed once in a full run.
- [x] Fingerprint of the ledger payload is an unkeyed SHA-256: consider keying it (short messages such as OTPs could be guessed offline).
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
- [x] Device-only gates (eligibility, frequency cap) must ignore Live Activity destination kinds: their `DestinationRef` ids are rows of the Live Activities tables.
- [x] `ApnsDriver::connectionProfile()` must support `EndpointRole::ApnsChannelManagement`.
- [x] Bind `EndpointCatalog` in the container.
- [x] Config keys under `mobile-push.live_activities.*` (enabled, binding, connection, tables, models, types, default_priority, high_priority.max_per_hour, local_expiry, max_update_retries, prune, channels).
- [x] Schedule `ActivityManager::flushDue()`; queued job carrying `ActivityTicket`.
- Commands still to write (later task): `live-activity:{start,update,end,prune}`, `channels:{list,create,delete,sync}`, `campaign:{status,pause,resume,cancel}`.
- Client contract for the docs is in the W3-L report (progress.md) and must be turned into `docs/live-activities.md`.

### Testing fakes (W4-T) — follow-up after W4-I releases `src/Laravel/Sending`
- [x] W5-T2: replaced by `PushManager::useDriver()/forgetDriver()`, `forceExecution(..., everyDestination: true)`, `forgetEligibility()`, `unregister()`; the environment is never touched. Was: the fakes work through reflection into `PushManager`'s private connection cache and by making `$app->environment()` read `production` while a fake is active (app-wide side effect: host code calling `app()->isProduction()` sees true during a faked test). Replace both with official seams: an execution override on the manager (`forceExecution(?ExecutionMode, ?LiveSettings)`) and a supported way to install/remove a driver for a connection name; then remove the reflection and the environment toggle from `src/Laravel/Testing`.
- [x] `PendingSend::options()` dropped `SendOptions::$observers` (passed to W4-I as F1).
- [x] W5-T2: `PushManager::unregister()`. Was: runtime `register()` cannot be undone (`unregister`), which the fakes need for cleanup.
- [x] Config key: `testing.allow_fakes` (bool, default false, no env).

### Whole-suite state on 2026-11-21 (2178 tests)
- [x] `tests/Unit/Laravel/Policies/PoliciesModuleTest` (2 cases) red while W4-I changes the `frequency.enabled` default (passed to W4-I as F2).
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

## W4-I integration pass (2026-11-21)

Done (each with a scenario under `tests/Scenario/Laravel/Integration`):
- [x] Config: every key of every module present in `src/` (ledger, queue, receipts, metrics, health, alerts, credentials, Live Activities, frequency, badge, fallback, campaigns, Pulse, Telescope, testing, `devices.prune.*`, `ledger.retention_days`) in `config/mobile-push.php` + `ConfigSchema`; `docs/configuration.md` generated by `ConfigReference::write()`; `ConfigReferenceTest` fails on a key read but undocumented, a default that differs from the file (evaluated in a clean environment), an undocumented file key, a stale document.
- [x] Names guessed by queue/health (`transport.timeout_ms`, `connections.*.timeout_ms`, `queue.connection`, `queue.timeout`) are the real keys: nothing to change on the reader side.
- [x] Metrics + alerts: `Sending\OperationsObserver` per call (record, recordThrottle, recordGuardTransition, `OpsAlerter::guardTransition`), isolated; `OpsAlerter::evaluate()` scheduled every minute when `alerts.enabled` (`IntegrationModule`).
- [x] Credentials: `Integration\ManagerCredentialsSource` (public facts collected by `ConnectionFactory` into `ResolvedConnection::$credentials`), bound by `IntegrationModule`.
- [x] Receipts: `defaultOptions()` carries `ReceiptMarkerIssuer::callback()` when receipts are on and the profile durable; the manager issues a marker only for destinations with a ledger delivery id and hands it to the sinks as a secret (`Sending\ReceiptMarkers`). The relay's own `withReceiptMarker()` is the same callback: issued once.
- [x] Policies: frequency gate after eligibility and consent, restricted to registry kinds; every `PendingSend::send()`/`queue()` has an effect key; badge resolved once per owner and effect; `PushManager::resetBadge()` / `currentBadge()`. `frequency.enabled` default is now false (validated only when enabled).
- [x] `PendingSend::send()` on the durable profile writes no ledger row (documented in the class and in `docs/contracts/ledger.md`); no `durably()`.
- [x] `SendPushJob::failed()` records every destination as OutcomeUnknown in the sinks when they are enabled.
- [x] Rate limit per provider account verified (two FCM aliases share, APNs independent): no fix needed.
- [x] Small fixes C1–C7, D1–D5, E1–E3, F1, F2; `PushManager::forceExecution()` testing seam.
- [x] E5: `Integration\EffectSettled` dispatched once per effect after a relay run (`EffectSettlement`, via `JobProcessed`).

Remaining:
- [x] (done) The orchestrator adds `Padosoft\MobilePush\Laravel\Integration\IntegrationModule` to `MobilePushServiceProvider::MODULES` (until then the integration scenarios register it themselves).
- [x] W5-T2: Facade `@method` docblocks (every public manager method, internals marked) for the new manager/PendingSend methods: `resetBadge`, `currentBadge`, `forceExecution` (internal), `PendingSend::forNotification`, `PendingSend::onlyProvider`.
- [x] W5-T2: channel uses `forNotification()`/`onlyProvider()`; durable fallback = `Notifications\DurableFallbacks` (encrypted record per effect at queue time, TTL `fallback.idempotency_ttl`, max 64 KiB; `EffectSettled` listener evaluates once all effects of the push settled). Was: Notifications channel: use `forNotification()` and `onlyProvider()` instead of resolving routes itself; durable fallback: a listener on `EffectSettled` that rebuilds the notification and calls `FallbackEvaluator::evaluate()` (the package stores no Notification object).
- [x] W5-T2: the console takes the findings of a resolved connection from `ConnectionCredentialsSource` + the container `CredentialCheck` (test: four commands agree). Was: `src/Laravel/Console/Support/ConnectionInspector.php` and `ManagerCredentialsSource` inspect credentials twice: converge the console on `ConnectionCredentialsSource` / `ResolvedConnection::$credentials`.
- [x] W5-T2: `AttemptReport::payload()` (lazy, redacted) + `SendingObserver` passes `payload` to the sinks only when the level is not none. Was: `log.payload` = keys/full has no effect on the manager path: the observer never receives the message payload (`AttemptReport` carries only its size). Pass the compiled payload keys (redacted) if the feature must work.
- [x] (decided: 30) Campaigns: `campaigns.retention_days` default is 30 in `CampaignSettings` (the orchestrator suggested 90): the config file follows the code (30); confirm.
- [x] (decided: wanted, documented in docs/policies.md) Consent gates (`eligibleUsing`) still see every destination kind, Live Activity tokens included (the callback gets the typed destination and decides); confirm that this is wanted.
- [x] W5-T2 (see above). Was: Testing seams beyond `forceExecution()` (connection-cache injection, `unregister()`) for the testing module.

### Findings from the module documentation pass (W5-D3), 2026-11-21
- Live Activities: `local_expires_at` enforced only by the pruner → passed back to the module author (enforce at transition time).
- `log.payload = keys|full` inert on the manager path; `SendingObserver` passes no `payload`, `effect_id`, `campaign_id`, `origin_*`, `notification` to the sinks → W5-T2 item 5 (extend it to the ids/origin context too).
- Durable-profile fallback: `PushChannel` never fills `EffectOrigin`, so `EffectSettled::origin` is always null → W5-T2 item 3.
- `TopicSubscriptionClient` is not bound in the container; built only by the command (without the gate closure it would make real requests in simulation) → bind it with the safe-mode gate in the integration module.
- Nothing schedules `devices:prune`, `logs:prune`, `deliveries:prune`, `credentials:check`, `live-activity:prune`, `campaign:prune`: decide which the package schedules itself (behind config flags) and which are left to the host app; document either way.
- The badge counter increments before the gates run: a device later skipped or capped has still moved it. Decide (resolve the badge after the gates, or document).
- Consent gates still see Live Activity tokens (only the device-only gates were restricted): document or restrict.
- `DeviceRegistrar::forgetOwner()` is a logout, not an erasure: rename or document.
- Migration guides: no equivalent here for pushok's `url-args` / `action-loc-key` (Safari/legacy keys) nor for the apn channel's `NotificationFailed` event → consider small additions (`ApnsMessage` already has loc keys? check) or state as intentional.

### M10 — docs site and banner (requested by the owner on 2026-11-21)
- `docs-site/` built with docmd, modelled on `/home/user/padosoft/laravel-flow` (read-only clone), generated from `README.md` + `docs/*.md` by a sync script with a `--check` mode; Cloudflare Pages settings in `docs-site/README.md`. Owner must confirm the domain.
- Banner: `art/banner.svg` → `resources/laravel-mobile-push-banner.png`; favicon `art/favicon.svg`.

### Findings from the operational documentation pass (W5-D2) and the campaign commands (W5-K4), 2026-11-21
- **Migrations run twice (real defect, verified on SQLite for the ledger):** an enabled module registers its migration files with the migrator AND `mobile-push:install` publishes copies under new names → `migrate` runs both and the second `create table` fails. Same pattern in devices, Live Activities, logs, campaigns, receipts. → W6-F.
- `mobile-push:install` says a crashed worker "never loses or duplicates a push" on the durable profile: over-claim → W6-F.
- Default config fails `doctor`: `queue.timeout` 120 s vs Laravel's default `retry_after` 90 → W6-F (coherent defaults).
- Environment probe has no time-based limit (plan §4.4 asked for one), only `--limit` per run → W6-F.
- Binding mismatch is inconsistent: direct `send()` returns `Skipped`/`binding_mismatch` + event; the durable relay records it without the event; the light job writes a warning and no result → W5-P.
- `CampaignStore::pruneFinished()` also deletes `failed` campaigns, which are resumable; no list / prune-count query on the manager (commands query the table directly) → W5-P.
- KMS: the `Signer` interface exists but `ConnectionFactory` always builds OpenSSL signers; no config path → after W5-T2 releases `src/Laravel/Sending`.
- Endpoint override has no config key (test-only, by design): document as such.
- Stale text in `docs/contracts/{ledger,modes,retry}.md` ("no implementation yet", open points since answered) → W6-F.
- A synchronous `send()` never retries a `Deferred` result with `inline_threshold_ms = 0`: by design (documented).
- The `explain` catalog now has 95 entries; `docs/commands.md` documents 30 commands (+8 campaign commands just added: re-sync the page in the final reconciliation).
- The banner PNG is marked `assume-unchanged` in git until its author finishes iterating (to avoid committing every intermediate render); undo with `git update-index --no-assume-unchanged resources/laravel-mobile-push-banner.png` and commit the final file.

## W5-T2 follow-ups
- [x] G1: `PendingSend::send()/queue()` pre-flight every connection before the network/queue; a message invalid for some connections only gives Rejected(PayloadInvalid) results for them (queue: nothing queued nor written for them), invalid for all throws.
- [x] G2: the intent fake validates with the real compilers by default; `PushFake::withoutValidation()` opts out.
- [x] `PushManager::campaign()`.
- [x] (fixed: phpstan max is clean on the whole repository) `src/Laravel/Console/Ops/ProbeEnvironmentCommand.php` (edited by another agent at 01:04) has 2 phpstan errors (`cast.string`, lines 113 and 140).
- [x] (fixed: the health test world binds a null credentials source) With `IntegrationModule` now in MODULES, three health tests that expect "not verifiable" without a credentials source are red (`HealthScenariosTest`, `SpatieCheckTest`, `HealthAndCredentialsTest` "not verifiable"): ManagerCredentialsSource is always available now; the tests must bind `NullConnectionCredentialsSource` themselves (not in W5-T2's lane).

### Resolved by W6-F (2026-11-21)
- [x] **Migrations run twice.** One rule for every module: migrations are published, not auto-loaded (`ModuleMigrations::wire()` in `src/Laravel/Integration`); opt-in `mobile-push.migrations.autoload` (`MOBILE_PUSH_MIGRATIONS_AUTOLOAD`, default false) registers the package files instead, publishes nothing, and a published copy wins over the package file. Every migration is idempotent (`hasTable`/`hasColumn`/index checks, symmetric `down()`). Tests: `tests/Scenario/Laravel/Install`.
- [x] `mobile-push:install` no longer claims that a crashed worker "never loses or duplicates a push": one accurate sentence per profile, taken from `docs/delivery-profiles.md`.
- [x] Default config fails `doctor`: defaults are now transport 30 s < `queue.chunk_deadline` 40 s < `queue.timeout` 50 s < worker 55 s (printed by install) < `retry_after` 90 s; lease stays 120 s. **Still to align by the owners of `src/Laravel/Queue`:** the literal fallbacks in `QueueSettings::fromConfig()` (`queue.timeout` 120, `queue.chunk_deadline` 60) and the constructor defaults of `QueueSettings`/`SendPushJob` if any; they only matter when the config key is missing (config not merged).
- [x] Environment probe frequency limit: `devices.probe.cooldown_hours` (24), last probe stored in `devices.metadata.environment_probe`, `cooling_down` outcome, `--ignore-cooldown` (asks, `--force` does not skip it).
- [x] Stale text in `docs/contracts/{ledger,modes,retry,classification,messages,outcomes,identity}.md` and `contracts/README.md`.
- [x] `docs/live-activities.md` (local expiry enforced at transition time), `docs/commands.md` (eight `campaign:*` commands, probe options), `docs/campaigns.md` (commands exist).
- [x] (done) For the orchestrator: `AGENTS.md` still says migrations are "also loaded by the module only when that module is enabled"; it should say they are published, with `migrations.autoload` as the opt-in.
- [x] (done) `InstallPlan` campaigns group has no file suffixes (its "already published" detection does not run); `CampaignsModule::migrationPath()` could be used.
- [x] (accepted as is: the test removes what it publishes; the publish targets are fixed at boot, so moving it needs a dedicated test case) `tests/Scenario/Laravel/Console/InstallTest.php` publishes into `vendor/orchestra/testbench-core/laravel` (and cleans up); move it to a temporary application path like `tests/Scenario/Laravel/Install`.

## W5-P scaling pass (2026-11-21)

Done (scenarios in `tests/Scenario/Laravel/{Ledger,Queue,Campaigns}`, slow ones in the `slow` group):
- [x] New migrations `2026_11_21_000013_add_mobile_push_ledger_scaling_indexes.php` (claim, effect-claim, work indexes; drops the two prefixes they replace) and `2026_11_21_000002_add_mobile_push_devices_audience_index.php`; listed in `LedgerModule::MIGRATIONS` / `DevicesModule::MIGRATIONS` (new `DevicesModule::migrationPaths()`), published with the module tags, idempotent.
- [x] Claim in index order per state; set-based claim/start/record (`Core\Ledger\DeliveryBatches`); PK-only guards; device lookup by PK; `scopesWithWork()` per state; `Core\Ledger\SettlementProbe`.
- [x] B1 `Core\Driver\TransmitObserver` (optional, per-call observers): the relay marks attempts started right before `Transport::send()`.
- [x] B2 logical key of a registry device without generation (legacy keys still deduplicate a replay).
- [x] B3 `IntentResult::$previousEffectId` / `reusedExpiredKey()`; rule documented in `docs/contracts/ledger.md`.
- [x] B4 `Queue\Events\RelayRunFinished` after every relay run; campaign completion and `EffectSettlement` react to it (sweeps stay the safety net); `EffectSettlement` probes first, skips campaign effects (announced by `CampaignCompletion` on completion) and announces once per set of deliveries.
- [x] C1 follow-ups of many effects folded per scope/queue/bucket (`RelayFollowUps::MAX_EFFECT_FOLLOW_UPS` = 20 precise ones, then one scope-wide relay).
- [x] H1 binding mismatch in the relay and in `SendPushJob`: `BindingMismatchDetected` once per run, Skipped(BindingMismatch) per destination in the log sinks (`Queue\BindingMismatches`).
- [x] H2 `CampaignStore::pruneFinished()` keeps `failed` campaigns; `CampaignManager::list()` / `countPrunable()`; List/Prune commands use them.

Remaining (outside W5-P's lane):
- [x] (done: it uses migrationPaths()) `src/Laravel/Console/Support/InstallPlan.php` lists `DevicesModule::migrationPath()` (the table only): use `DevicesModule::migrationPaths()` so install/doctor show the devices index migration too.
- [x] (removed) `IntegrationModule`'s `JobProcessed` listener is now redundant with the `RelayRunFinished` listener of `QueueModule` (both go through `EffectSettlement`, deduplicated by its cache marker); it can be removed.
- [ ] MySQL/PostgreSQL: run `ScalingQueryPlansTest`-like checks with `EXPLAIN` on those engines in the experimental CI jobs (the plans are asserted on SQLite only).
- [x] W9-D: `docs/campaigns.md` / `docs/delivery-profiles.md`: completion is immediate (RelayRunFinished), failed campaigns are not pruned, `IntentResult::$previousEffectId`, follow-up folding (`queue.follow_up_bucket` now also bounds relay follow-ups).

## State on 2026-11-21 (after the closing pass)

Open:
- [ ] Query plans on MySQL and PostgreSQL: the scenarios pass on both engines in CI and the whole suite passed on a local PostgreSQL 16; the plans of the claim, audience and lookup queries are asserted on SQLite only (`ScalingQueryPlansTest`). Timing: the campaign scaling guard was run on PostgreSQL 16 (25,000 in 29.7 s, 200,000 in 273.6 s, ratio 9.2 for 8x); MySQL was never timed.
- [ ] Everything past authentication against the real providers (an accepted push, `BadDeviceToken`, `Unregistered`, quota answers, Live Activities, broadcast channels, topic subscriptions): needs the owner's credentials and a device.
- [ ] Octane, Swoole, RoadRunner, FrankenPHP, Horizon, Vapor, Redis/SQS queues, memcached/dynamodb cache stores, MariaDB, a real KMS, `xcrun` on a Mac, Pulse/Telescope in a browser: never run.
- [ ] `ExpiredProviderToken` refresh path against the real APNs (not observable without a real key).

Verified by hand or by CI in the closing pass (see `progress.md`): install into a fresh Laravel 12 and 13 application (`fresh-app` job), MySQL 8.4 and PostgreSQL 17 scenarios, credential-less probes of APNs sandbox / FCM / Google OAuth from a hosted runner (`providers` workflow), FCM Discovery drift check, mutation testing workflow.
