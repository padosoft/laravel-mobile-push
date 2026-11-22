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
- [x] (done: `ServerQueryPlansTest`, five scenarios) MySQL/PostgreSQL: run `ScalingQueryPlansTest`-like checks with `EXPLAIN` on those engines in the experimental CI jobs.
- [x] W9-D: `docs/campaigns.md` / `docs/delivery-profiles.md`: completion is immediate (RelayRunFinished), failed campaigns are not pruned, `IntentResult::$previousEffectId`, follow-up folding (`queue.follow_up_bucket` now also bounds relay follow-ups).

## State on 2026-11-21 (after the closing pass)

Open:
- [x] Query plans on MySQL and PostgreSQL: `ServerQueryPlansTest` asserts the claim plan and that an index can serve every other hot query (sweeps, health/settlement reads, start/record, audience slice, device lookup); the plan the planner picks at production size was not observed. Timing: the campaign scaling guard was run on PostgreSQL 16 locally (25,000 in 29.7 s, 200,000 in 273.6 s, ratio 9.2 for 8x) and, in the `scaling` workflow on hosted runners, on PostgreSQL 17 (33.6 s / 293.7 s, ratio 8.75) and MySQL 8.4 (47.1 s / 430.0 s, ratio 9.14 with a 2 GB buffer pool; 38.8 s / 630.5 s, ratio 16.2 and a failed guard with the image default of 128 MB).
- [ ] BLOCKED (needs the owner's credentials and a device): everything past authentication against the real providers (an accepted push, `BadDeviceToken`, `Unregistered`, quota answers, Live Activities, broadcast channels, topic subscriptions): needs the owner's credentials and a device.
- [x] Run in CI on 2026-11-21 (`backends` workflow and the `databases`/`runtimes` jobs): MariaDB 11.4 (all 639 database scenarios), SQS on ElasticMQ, memcached 1.6 and DynamoDB Local as cache stores (application smoke and the eight-process coordination scenario), Octane on Swoole 6.2 and FrankenPHP 1.14 (embedded PHP 8.6) next to RoadRunner, each with sixty requests through the real transport to the HTTP/2 mock (1 connection per worker counted by the server), and a 250-device campaign on Redis and SQS queues.
- [x] Run on 2026-11-21 after the item above: beanstalkd 1.13 (smoke and campaign), OpenSwoole 26.11, eight concurrent requests under Octane on RoadRunner/Swoole/OpenSwoole/FrankenPHP (120 requests on 2 connections each), Swoole's coroutine hooks (not supported: 4 to 9 of 60 concurrent requests succeeded; doctor warns), the APNs signer against an emulator of the AWS KMS API (`KmsSignerTest`), Pulse 1.11 and Telescope 5.29 in headless Chromium (two card defects found and fixed).
- [x] `xcrun simctl push` through `mobile-push:simulator` on a macOS runner (manual `simulator` workflow, macOS 26, Xcode 26.6, iPhone 17 Pro simulator): accepted by `booted` and by device id; exit 5 on an unknown device.
- [x] Plan §11 "Migrazioni: test di upgrade e di tutte le combinazioni con moduli spenti": `tests/Scenario/Laravel/Install/UpgradeMigrationsTest.php` (old schema, live data, leases held across the upgrade, rollback and re-run) and `ModuleCombinationsTest.php` (64 applications built on their configuration). Both run on the server engines in the `databases` CI jobs.
- [x] Application Default Credentials with the metadata server emulated under `google/auth`'s HTTP client (`AdcOnGoogleCloudTest`, runs in the normal suite).
- [ ] BLOCKED, not attemptable from here or from CI without the owner's accounts: Vapor/Lambda and real AWS SQS/DynamoDB/KMS (an AWS account), Application Default Credentials on Google Cloud itself (a GCP project).
- [ ] BLOCKED (needs the owner's `.p8` key): the `ExpiredProviderToken` refresh path against the real APNs. Against the fake transport it is covered by `ApnsProviderTokenLifecycleTest` (fifty workers, one new token), `FirstSendStoriesTest` and `RefusedBearerTest`; the real APNs only ever answered the credential-less probe (`InvalidProviderToken`).

Verified by hand or by CI in the closing pass (see `progress.md`): install into a fresh Laravel 12 and 13 application (`fresh-app` job), MySQL 8.4 and PostgreSQL 17 scenarios, credential-less probes of APNs sandbox / FCM / Google OAuth from a hosted runner (`providers` workflow), FCM Discovery drift check, mutation testing workflow.
- [x] Mutation testing (weekly `mutation` workflow, 16 groups). Scores on 2026-11-21, unit tests + scenarios: signatures 99%, ledger rules 98%, APNs compiler 98%, token providers 95%, FCM compiler 95%, retry 93%, guards 91%, safe mode 91%, ledger core 91%, send pipeline 90%, APNs outcome 89%, privacy 88%, FCM outcome 85%, database ledger 92% (SQLite 91.9%, PostgreSQL 92.0%, MySQL 92.9%; all 77 survivors of the first run settled one by one in the M9 entry of `progress.md`), message support 71%, transport parsers 53%. Reading them: the coverage driver reports constants and static tables as uncovered even when a test kills the mutant (all 80 of the transport group, 27 of FCM outcome), and the survivors left are listed, with the reason each is equivalent, unreachable or concurrency-only, in the M1-M8 entries of `progress.md`. Message support keeps its termination guards on purpose.


## Plan audit of 2026-11-21 (wave 6) and decided deviations from `docs/PLAN.md`

An independent read of the plan against code and tests found gaps; each was closed in wave 6 (see `progress.md`, entries W6-A to W6-H) or is recorded here as a deliberate deviation.

Closed:
- [x] §6.4 `fcm.prefer`: `devices.fcm_prefer` = `token` | `fid` (W6-A, `FcmPreferenceStoriesTest`).
- [x] §6.6 chunk deadline in the light profile: `SendPushJob` hands the destinations it has not attempted to one follow-up job (W6-C, `LightChunkDeadlineTest`).
- [x] §6.3 lenient warnings on the result (W6-B, `LenientWarningsTest`); §6.2 rotation closes the old pool and never reuses the old token (W6-B, `CredentialRotationTest`).
- [x] §4.4 environment probe refused before any request for a key declared as restricted (`connections.*.key_environments`, W6-E).
- [x] §11 release table: concurrent retries with the cache unavailable, counted by the HTTP/2 mock (`Release/RetriesWithCacheDownTest`); canaries on every surface with a retried push and the receipt marker, then `forget` (`Release/ConfidentialityCanariesTest`); RSS in the runtime scenarios (W6-E); badge not applied to topics, conditions and broadcast (W6-E).
- [x] Long-lived runtimes, «se un runtime non supera i test di riuso, il riuso viene disattivato»: `transport.reuse` = `auto` | `on` | `off` (W6-G).
- [x] §11 mutation testing: `Core\Outcome` and the OpenSSL signers are in the workflow, and every group has a minimum score.
- [x] §11 CI «sonde live … su ref protetto»: `.github/workflows/live-probes.yml` exists (protected environment, manual, `main` only). Never run: no credential.
- [x] Defects found by the new scenarios and fixed: follow-up relays did not survive an unreachable cache (W6-H); `forget` left the encrypted message snapshot of effects addressed to the owner alone (no production code ever set an effect's origin); a registry device sent through a connection of another binding was reported as `row_missing` instead of `binding_mismatch`; a routing notifiable with destinations of both providers threw when a connection was named.

Deviations kept on purpose:
- §4.2 «un override incongruente è un errore prima della rete»: a named connection NARROWS a notifiable to the devices and routed destinations of that connection, so `to($users)` cannot fail because one user has no device there. An explicit destination of the other provider still throws before the network; a registry device of another binding is `Skipped` / `binding_mismatch` (never an exception: "never throw for a single destination").
- §6.2 «drenano … cache»: on rotation the old provider token is not deleted from the shared cache; it becomes unreachable (the cache key includes the credential identity) and expires on its TTL. Deleting it would make workers still on the old configuration mint again, and Apple refuses provider tokens minted too often.
- §7 `->queue($msg)->onQueueConnection(...)->onQueue(...)`: the options come BEFORE `queue()`, which is the terminal call and returns the effect id.
- §8 receipts «Marcatore per evento e destinazione»: one marker per delivery (destination), with the event type in the callback; uniqueness is per `(delivery, event type)`.
- Light profile «best effort … nei report»: `queue()` returns the effect id, not a report; what can be observed afterwards (events, send-log records with the effect id, `SendPushJob::failed()`) is in `docs/sending.md`.
- Swoole with curl coroutine hooks: reuse is switched off for such sends by `transport.reuse = auto`, which stops the crash inside curl (one connection per send, no worker dies in the pool). The configuration stays unsupported for HTTP workers: in two CI runs 60 and 55 of 60 concurrent requests succeeded, the failures being Laravel container errors under Octane's shared per-worker application (before the switch: 4 and 9 of 60).
- §4.3 `queueMany()`: there is no such method; many targets go through `to($users)->queue()` (one effect, one delivery per destination), which is what `queueMany()` was meant to do. (Found unwritten by the plan closure audit of 2026-11-22, like the next six entries.)
- §4.5 / §4.6 config key names: `guards.rate.fcm` (plan: `rate.fcm_per_minute`) and `connections.*.broadcast_host` (plan: `broadcast_publish_host`), so that every guard setting lives under `guards.*` and every per-connection setting under its connection.
- §4.5 «quando segnalati» for FCM quotas: `QUOTA_EXCEEDED` pauses the whole Firebase project, whichever quota (project, device, topic) was exceeded. Google's `ErrorCode` reference (read on 2026-11-22, once `firebase.google.com` was allowed in the session network) says a `google.rpc.QuotaFailure` extension "is returned to specify which quota was exceeded", but no page read documents the extension's content per case; the Discovery document (rev. 20261112) does not describe the error details, and Google's Admin SDKs (`firebase-admin` 14.5 for Node, 7.8 for Python) map the v1 `QUOTA_EXCEEDED` to one generic rate error. Telling the cases apart would mean reading undocumented strings. Stated in `docs/retries-and-limits.md`.
- §4.5 Apple's 15-minute advice: cited in code (`ApnsReasonCatalog`) and in `docs/retries-and-limits.md`, not in a test fixture; the delay itself is pinned by the classifier tests.
- §5 names and layout: `CurlPool` / `CurlPoolRegistry` / `ResponseHeaderParser` (plan: `ConnectionPool` / `PoolRegistry` / `HeaderParser`); no `Lane`, `RecordingTransport`, `RefreshPolicy`, `AppBinding`, Core `EligibilityGuard`, shared `PayloadLimits`, `Expiry`, capability interfaces (`SupportsLiveActivities`, `ManagesChannels`, `ManagesTopicSubscriptions`), `CertificateAuth`, `BroadcastPublisher` classes: their behaviour lives in the classes the contracts name (`docs/contracts/*.md`); `ApnsOverride` is `FcmApnsConfig`; `ConfigSchema` is in `Laravel/Integration`; jobs and events live in the module that owns them (`Laravel/Queue`, `Laravel/Sending/Events`, ...) instead of `Laravel/Jobs` and `Laravel/Events`; migrations are `.php` files published by the install command, not `.stub`.
- §6.6 `SendChunkJob` is `SendPushJob`.
- §7 `MobilePush::assertSent(...)`: CLOSED on 2026-11-22, the assertions of the fake are forwarded by the facade (`StaticAssertionsTest`).
- Written until now only in the contracts or the public docs, repeated here so that this list is complete: the extra `Failed` status (`docs/contracts/outcomes.md`); the two-level uniqueness of the ledger and `send()` never writing the ledger (`docs/contracts/ledger.md`); no quota breaker class, the guards living in `Core/Guard` (`docs/contracts/retry.md`); the token cache encrypted with the package's `TokenCipher` rather than Laravel's Encrypter (`docs/security-and-privacy.md`); the capability matrix as a reference that the compilers enforce (`docs/messages.md`); modules that are on by default (metrics, badge, fallback, Telescope `auto`; README "Features at a glance"), where the plan says "all off by default".


## Release 1.0.0 (2026-11-22)

- [x] Owner's decision: the pre-release notice is removed and the package is released as 1.0.0 (the owner reported a successful production test; nothing about it is stated in the public docs, by the owner's choice).
- [x] PHP 8.2 support, asked by the owner before the release (an application on Laravel 12 + PHP 8.2): `Randomizer::getFloat()` replaced, three `modify()` calls replaced by `add()`, `php ^8.2`, Pest 3 allowed on 8.2. Verified on a real PHP 8.2.36 in CI: 3,728 tests with Laravel 12 (lowest and latest dependencies, Pest 3.8, PHPUnit 11.5) and a freshly created Laravel 12 application; static analysis also at the 8.2 language level (`phpstan.php82.neon.dist`). The server-database and runtimes jobs run on 8.2 too since 2026-11-22 (see below); the `backends`, `scaling` and `mutation` workflows run on 8.3 only.
- [x] Mutation scores before the verification wave of 2026-11-22 (run 44960379703; superseded by the list below): signatures and signers 97.0%, outcome 97.3%, ledger rules 98.2%, APNs compiler 97.9%, token providers 95.0%, FCM compiler 94.8%, retry 92.5%, guards 91.3%, safe mode 90.6%, send pipeline 90.4%, ledger core 90.1%, database ledger 90.6% (SQLite) and 91.0% (PostgreSQL), APNs outcome 89.4%, privacy 87.7%, FCM outcome 84.9%, message support 71.4%, transport parsers 52.9% (constants reported as uncovered). Every group is above its floor.
- [ ] BLOCKED for this session (the session's GitHub proxy refuses tag pushes, tag creation through the API and repository-settings writes; not worked around): creating the tag `v1.0.0` and making the repository public. Both are one step each for the owner.
- [ ] BLOCKED for this session, checked on 2026-11-22: **Packagist**. `https://repo.packagist.org/p2/padosoft/laravel-mobile-push.json` answers 404 (the package is not registered); registering it needs a login to the vendor's Packagist account and a repository Packagist can read, and the repository is private (see the line above). No Packagist credential exists in the session. Ready on our side: `composer validate --strict --no-check-lock` passes, the dist archive carries no test, CI or site file (`.gitattributes`).
- [x] **Cloudflare Pages**: DONE by the owner's Git integration. Every commit on `main` carries the check run "Cloudflare Pages: Deployed successfully" of the app `cloudflare-workers-and-pages` (project `laravel-mobile-push`; seen on `5a31b38`, `18138b7`, `2fec69c`). An earlier version of this line said the site was not published because `doc.laravel-mobile-push.padosoft.com` did not answer from the session: that was the session's own network proxy refusing the host (CONNECT 403), not the site. Not visible from the session: whether the custom domain is attached to the Pages project (the owner can open it). A request for a Cloudflare API token (to publish from the session) was declined by the owner on 2026-11-22: it was not needed. One deploy failed: commit `15138e4` (two internal note files only) stayed in "Building" for about 55 minutes and ended "Build failed", while the site build of the same commit passed in GitHub Actions; the published site was unaffected (those files are not part of it). The next push retriggers the build; the Cloudflare logs are visible only to the owner.

### After the release notes: verification on the owner's stack (2026-11-22)

- [x] PHP 8.2 + Laravel 12 on the server engines and runtimes, in CI: the database-backed scenarios on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17; Redis + Horizon + Octane on RoadRunner; the `slow` group; a freshly created Laravel 12 application on PHP 8.2 with database, queue and cache store on MySQL 8.4.
- [x] **Defect found and fixed**: with the `database` cache store on MySQL, a deadlock on the lock table (error 1213) was treated as an unreachable store and the guards of that process failed open for five seconds (175 permits granted from a budget of 100 on MySQL 8.4 in CI; reproduced on a local MySQL 8.0, four runs of six). `CacheLocks` now reads a concurrency error as "lock not taken" and retries a release; `CacheGuardStore` never marks the store unavailable for it. Eight of eight local runs then granted exactly 100; CI green on MySQL 8.4 with PHP 8.2 and 8.3. The frequency cap and the badge counter use the same store.
- [x] The `database` cache store on the server engines is part of `CacheCoordinationTest` (eight processes), in the `databases` job.
- [x] Campaign scaling guard: each run now starts from an empty server schema (the second campaign used to plan the 25,000 devices of the first too); new figures in `docs/delivery-profiles.md`. Chosen plans of the registry reads at 200,000 devices: 4 statements, no whole-table read, PostgreSQL 17 and MySQL 8.4 (run 44973578934).
- [x] The whole Laravel layer (everything under `tests/Unit/Laravel` and `tests/Scenario/Laravel`) runs on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17, on PHP 8.2 and 8.3, and those jobs gate the build (they were allowed to fail, which let a red MySQL job hide inside a green run).
- [x] PHP-FPM run for real in CI (`runtimes` job, both PHP/Laravel pairs): 60 requests on 60 connections, 5 pushes of one request on one connection (`tests/Support/FreshApp/fpm-smoke.sh`).
- [x] **Defect found and fixed**: the frequency cap could be exceeded across processes (6 from a cap of 5, 61 from 60, PostgreSQL 16 `database` store, default `on_store_failure = allow`): compare-and-set exhaustion was reported as a store failure. Now `FrequencyStoreContended` → `Deferred` under either policy; same retry policy in `BadgeCounter`. `PolicyCoordinationTest`: exact caps and 200/200 badges on Redis, SQLite, MySQL, MariaDB, PostgreSQL, file, memcached, DynamoDB Local.
- [x] **Defect found and fixed**: metric counters were never recorded on the `database` cache store (Laravel's default), memcached and DynamoDB (`increment` does not create the key), and lost counts on the `file` store. `MetricsCoordinationTest`: 200/200 on Redis, file, MySQL, MariaDB, PostgreSQL, memcached, DynamoDB Local; a shared SQLite file keeps 162 to 198 of 200 under eight processes (stated in the test and in `docs/observability.md`).
- [x] **Defect found and fixed**: a `null` frequency limit (an unset `env()`) or a `null` bucket removed the cap silently; now a `ConfigurationException`.
- [x] **Defect found and fixed** (fifth of the wave): on the `file` cache store a guard entry could be lost at the moment it was created. Laravel's `FileStore` creates the file empty (`file_put_contents` with `LOCK_EX` opens, then locks, then writes) and `FileStore::getPayload()` deletes a file it reads empty; a concurrent reader therefore deletes the entry being written (2,511 of 7,188 creations lost with six readers on the store alone). Seen twice in CI as 6 pushes through a cap of 5 and as duplicate badge numbers (155 distinct of 200), in full-suite runs (45026749753, 45030595798); not reproduced by repeating the scenario 320 times, then isolated with a targeted experiment. `CacheGuardStore` now takes the key's lock for `get()` and `put()` on a `FileStore`; `FileStoreCreationRaceTest` (one writer, six readers) loses entries without the fix and none with it. Metrics on the `file` store can still lose a first count the same way (stated in `docs/observability.md`).
- [x] Pinned and documented: the fallback claim when its cache store throws (no unclaimed message, the error surfaces, a retry sends once).
- [x] Audit of every other optimistic retry loop (`grep` for the loops in `src/Laravel/{Campaigns,LiveActivities,Devices}`): on exhaustion they throw or leave the work to a sweep; none lets something through.
- [x] Scaling guards on MariaDB 11.4 (run 44980718038); a second run for PostgreSQL and MySQL. Figures in `docs/delivery-profiles.md`.
- [x] Mutation groups added: `cache-stores` (89.8%), `policies` (88.7%). The retry group is back to 92.8% after the PHP 8.2 change of `Backoff`.
- [x] Mutation scores on the final code (runs 44987232862 on `32136bf` for every group, 44991494371 on `8097189` for `metrics`, whose class changed after): signatures and signers 97.0%, outcome 97.3%, ledger rules 98.2%, APNs compiler 97.9%, token providers 95.0%, FCM compiler 94.8%, retry 92.8%, guards 91.3%, safe mode 90.6%, metrics 90.5%, send pipeline 90.4%, ledger core 90.2%, database ledger 90.8% (SQLite), 91.2% (PostgreSQL) and 92.2% (MySQL), cache stores 89.8%, APNs outcome 89.4%, policies 88.7%, privacy 87.7%, FCM outcome 84.9%, message support 71.4%, transport parsers 52.9% (80 mutants on a constant table that the coverage driver does not see; the 90 it sees are all killed). Every group is above its floor (21 jobs of 21 green). After that run the classes of two groups changed and were run again alone: `metrics` 90.0% on `04b76e2` (queue wait gauge), `cache-stores` 90.2% on `c139be4` (lock for reads on the file store).
