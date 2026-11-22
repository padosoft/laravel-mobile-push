# Plan closure: `docs/PLAN.md` item by item

- Date: 2026-11-22
- Commit audited: `18138b7` (`git rev-parse --short HEAD`), working tree clean except this file.
- Task: M15-plan-closure (read-only audit, independent of the status notes).

**How the list was built.** Every sentence of `docs/PLAN.md` sections 4 to 13 that promises a behaviour, a file, a
command, a test kind, a document or a CI job became one row. Each row was checked against the code as it is: the
classes under `src/`, `config/mobile-push.php`, `database/migrations/`, the tests under `tests/` (names and, where the
row depends on it, the assertions), the workflows under `.github/workflows/` (opened, not judged by name), the public
docs and `docs/internal/integration-todo.md` (its "Deviations kept on purpose" list). Command names were read from the
`protected $signature` of every command under `src/Laravel`. The full suite was not run (known green at this commit);
no single test was run for this audit. "Not verified by this audit" marks what was not opened or not run here.

Statuses:

- **DONE**: implemented as the plan says.
- **DONE, DIFFERENTLY**: implemented with a deviation. The cell says where the deviation is written down, or
  **UNDOCUMENTED** when it is written nowhere (a finding).
- **NOT DONE, ATTEMPTABLE**: missing or partial; nothing outside the repository prevents doing it.
- **BLOCKED**: needs something outside the repository (real credentials or device, a cloud account, the owner's
  Packagist/Cloudflare account, repository settings or tags).

Where an item has an emulated part and a real-service part, it has two rows.

**Totals: 329 rows.** DONE 271; DONE, DIFFERENTLY 31 (17 of them with a deviation written down nowhere); NOT DONE,
ATTEMPTABLE 7; BLOCKED 20.

## §4.1 Outcomes

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| `SendStatus` with `Accepted`, `Rejected`, `Deferred`, `OutcomeUnknown`, `Expired`, `Skipped`, `Simulated`, `Validated` | DONE, DIFFERENTLY | `src/Core/Outcome/SendStatus.php` has the eight cases plus `Failed` ("known not accepted, and no attempt left"). The extra case is written down in `docs/contracts/outcomes.md` (table row and §37, §56), not in the decided-deviations list. |
| `Accepted` means accepted by the provider, never "delivered" or "seen" | DONE | Docblock of `SendStatus`; `docs/outcomes.md`, `docs/live-activities.md` ("Accepted means accepted by APNs"). |
| `Reason { ReasonCode code; ?string rawReason; ?string hint }`, raw string outside the enum and sanitised | DONE | `src/Core/Outcome/Reason.php` (readonly, `code`, `rawReason`, `hint`); `ReasonCode` enum separate. |
| `NotSent` only with proof (DNS/connect/TLS before the request, or an explicit HTTP response); generic HTTP/2 error, timeout, reset after write = unknown | DONE | `src/Core/Transport/TransportEvidence.php`, `Curl/CurlErrorClassifier.php`; `tests/Scenario/Transport/NotSentProofTest.php`, `ResponseLostTest.php`, `tests/Unit/Core/Transport/CurlErrorClassifierTest.php`. |
| Promote `REFUSED_STREAM` / last stream of `GOAWAY` to proof only if M1 measured the binding distinguishes them | DONE | Measured not distinguishable: `lesson.md` entry "REFUSED_STREAM e GOAWAY non si possono promuovere a NotSent da PHP"; `docs/transport.md`; `tests/Scenario/Transport/StreamRefusalTest.php`, `LibcurlBehaviourTest.php`. They stay unknown. |
| `unknown_outcome.retry = never \| replaceable \| always`, default `replaceable` | DONE | `config/mobile-push.php` `unknown_outcome.retry` (env `MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY`); `src/Core/Retry/UnknownOutcomeRetry.php`; `RetryPolicy` default `Replaceable`. |
| Replaceable: Live Activity update/end, background/widgets/controls, messages marked `->replaceable()`; alert and `start` not retried without opt-in | DONE | `ApnsDriver::isReplaceable()` (LA event != Start; push types Background/Widgets/Controls; `PushMessage::$replaceable` or silent), `FcmDriver::isReplaceable()`, `PushMessage::replaceable()`; `tests/Unit/Core/Retry/RetryPlannerTest.php`, `tests/Scenario/Laravel/Queue/DurableCrashTest.php` (alert not re-sent, replaceable retried). |
| No fallback and no invalidation from an unknown state | DONE | `Notifications/FallbackRules.php` rule 4; `Devices/DeviceOutcomeApplier::apply()` returns `UnknownOutcome` before any change. |
| Globally invalid input/config: exception before the network; single-target error: result, never exception | DONE | `PendingSend::preflight()` and `PushManager::preflight()` (valid for none → throws, for some → `Rejected(PayloadInvalid)` per connection); `tests/Scenario/Laravel/Sending/PreflightStoriesTest.php`, `tests/Scenario/Send/ValidationTest.php`, `MixedBatchTest.php`. |
| `SendReport`: bounded counters + `results()` cursor + `each(callable)`; never collects millions of objects | DONE | `src/Core/Outcome/SendReport.php` (`counts()`, `results()` generator, `each()`, `retain` cap); `tests/Unit/Core/Outcome/SendReportTest.php`, `tests/Scenario/Send/MillionDestinationsTest.php`. |

## §4.2 Identity

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Scope: string, default `default`, from `MobilePush::scopeUsing()` or `->scope()`, never from the client payload | DONE | `Core/Identity/Scope::normalize()`, `PushManager::scopeUsing()`, `scope()`, `PendingSend::scope()`; `docs/multi-tenancy.md`. |
| App binding `(scope, name, provider, app_id, environment, generation)`; generation changes on rotation/reconfiguration | DONE | `src/Core/Identity/BindingRef.php`; `ConnectionFactory::generation()` from credential identities; `tests/Scenario/Laravel/Sending/TenantBindingStoriesTest.php` ("old job with the new key ... new generation"). |
| Jobs queue an immutable `BindingRef` + destination id and generation, never the secret | DONE | `Queue/SendPushJob.php`, `RelayDeliveriesJob.php` (ids only, docblock); `QueuedDestination`. |
| On consumption the credential is resolved for that identity; mismatch → `Skipped(BindingMismatch)` with event, no fallback to default | DONE | `PushManager::mismatch()`, `Queue/BindingMismatches.php`, event `Sending/Events/BindingMismatchDetected.php`; `TenantBindingStoriesTest` ("zero requests, an event, no default fallback"), `tests/Scenario/Laravel/Queue/DurableRegisteredElsewhereTest.php`. |
| Typed disjoint destinations `ApnsDeviceToken`, `ApnsLiveActivityToken`, `ApnsPushToStartToken`, `ApnsBroadcastChannel`, `FcmToken`, `FcmFid`, `FcmTopic`, `FcmCondition` | DONE | `src/Core/Identity/Destination/*` (also `ApnsVoipToken`); `tests/Scenario/Apns/Compile/DestinationsTest.php`, `tests/Scenario/Fcm/Compile/TargetsScenarioTest.php`. |
| For `to($user)` the registry metadata rule; an incongruent override is an error before the network | DONE, DIFFERENTLY | A named connection narrows a notifiable to that connection's devices; explicit destination of the other provider still throws; registry device of another binding is `Skipped/binding_mismatch`. Written down in `docs/internal/integration-todo.md`, "Deviations kept on purpose" (§4.2). `NamedConnectionStoriesTest.php`. |
| The same scope applies to cache, locks, dedup, receipts, `export`/`forget`, channel actions | DONE | `Auth/TokenCacheKey` includes scope; ledger rows and unique keys carry scope; `Privacy/Erasure/*` scoped (`ForgetAndExportTest` "strictly scoped"); `tests/Scenario/Laravel/LiveActivities/TenantsAndPrivacyTest.php`, `tests/Scenario/Laravel/Campaigns/TenantsTest.php`, `tests/Scenario/Runtime/ManyTenantsTest.php`. |

## §4.3 Effect ledger and profiles

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Light profile is the default, no tables | DONE | `delivery.profile` default `light` in `config/mobile-push.php`; `docs/delivery-profiles.md`. |
| Light profile: dedup only in the process | DONE | Dedup inside one `queue()` call (`docs/delivery-profiles.md` "At a glance"); `Queue/LightQueuedSender.php`. |
| Light profile declared best effort in docs **and in reports**, also for `queueMany()` | DONE, DIFFERENTLY | Best effort is in `docs/delivery-profiles.md` and README; "in the reports" is replaced by what can be observed after `queue()` (deviation "Light profile «best effort … nei report»" in integration-todo). No `queueMany()` exists: many targets go through `to($users)->queue()`; that renaming is **UNDOCUMENTED**. |
| Durable profile `delivery.profile = durable` | DONE | `config/mobile-push.php`; `Queue/ProfiledQueuedSender.php`, `DurableQueuedSender.php`. |
| `mobile_push_effects`: id before dispatch, scope, binding, `effect_key`, fingerprint, encrypted snapshot, type, replaceability, logical expiry, `policy` | DONE | `database/migrations/2026_11_20_000010_create_mobile_push_ledger_effects_table.php` (ULID id, `scope`, `binding`, `effect_key`, `fingerprint`, `message` encrypted, `operation`, `replaceable`, `expires_at`, `policy`). |
| `mobile_push_deliveries`: one row per logical destination with state, attempts, `next_attempt_at`, lease owner/expiry, version, provider id, `reason_code`, `raw_reason`, timestamps | DONE | `database/migrations/2026_11_20_000011_create_mobile_push_ledger_deliveries_table.php` (all listed columns). |
| Unique `(scope, effect_key, logical destination)`; same key with a different fingerprint → `IdempotencyConflictException` | DONE, DIFFERENTLY | Two-level uniqueness: effect `(scope, binding_identity, dedupe_key)` + delivery `(effect_id, logical_key)`, with a dedup window. Written in `docs/contracts/ledger.md` items 5-6. Conflict: `Exceptions/IdempotencyConflictException.php`; `tests/Scenario/Ledger/LedgerContract.php`, `tests/Scenario/Laravel/Queue/DurableOutboxTest.php`. |
| Outbox: inside a transaction on the ledger connection the intent commits with business data | DONE | `Laravel/Ledger/DatabaseLedger.php`; `docs/contracts/ledger.md` item 7; `tests/Scenario/Laravel/Ledger/OutboxTransactionTest.php`, `DurableOutboxTest.php`. |
| Recoverable relay `RelayDeliveriesJob` + scheduled sweeper | DONE | `Queue/RelayDeliveriesJob.php`, `DeliveryRelay.php`, `SweepLedgerJob.php`, `LedgerSweeper.php`; schedule in `QueueModule`; `tests/Scenario/Laravel/Queue/DurableRelayTest.php`, `Console/Ops/RelayAndSweepTest.php`. |
| The worker re-reads unfinished destinations and records outcomes before ack; no SQL transaction during the network | DONE | `docs/contracts/ledger.md` items 8-9 and "Worker writes in batches"; `DeliveryBatches`, `RelayRecorder`; `tests/Scenario/Laravel/Ledger/LedgerStatementsTest.php`, `ConcurrentWritesTest.php`. |
| A lease expired after the send started does not prove `NotSent` | DONE | `reclaimExpiredLeases()` ends `sending` rows as `outcome_unknown` (`docs/contracts/ledger.md` item 10); `DurableCrashTest.php` ("crash after the response, before saving: the outcome is unknown"). |
| Default `effect_key`: Notification UUID, else ULID at call time; dedup duration configurable; keys also cover topics, conditions, channels | DONE | `Notifications/EffectKeys.php`, `Core/Ledger/Ulid.php`, `ledger.dedupe_seconds`; `docs/contracts/ledger.md` item 6. |
| Synchronous `send()` and the ledger (not in the plan's wording; the plan's outbox sentence covers "the call") | DONE, DIFFERENTLY | Only `queue()` writes the ledger; `send()` never does. Written in `docs/contracts/ledger.md` ("Only `queue()` uses the ledger", decision W4-I) and `docs/delivery-profiles.md`. |

## §4.4 Single classifier

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| One `OutcomeClassifier` per operation gives a final `Decision` (status, `nextAttemptAt`, destination action none/quarantine/invalidate, credential action, throttle signal) | DONE | `Core/Outcome/OutcomeClassifier.php`, `Decision.php`, `DestinationAction`, `CredentialAction`; `Apns/Outcome/ApnsOutcomeClassifier.php`, `Fcm/Outcome/FcmOutcomeClassifier.php`; unit tests for both. |
| Listeners and breakers act only on the final decision, never on intermediate answers | DONE | Breakers fed by the guard facade from decisions (`AuthBreaker` docblock: refresh signals never reported); events from final results (`tests/Scenario/Send/ObserverTest.php`, `GuardOriginTest.php`). Per-attempt feeding was not traced line by line by this audit. |
| Quarantine on `BadDeviceToken`, `DeviceTokenNotForTopic`, FCM `SENDER_ID_MISMATCH`: current send dropped, registration suspended in its scope, no retry | DONE | `ApnsReasonCatalog` (BadDeviceToken → `DestinationRejected`, DeviceTokenNotForTopic → `DestinationMismatch`), `FcmReasonCatalog` (SENDER_ID_MISMATCH); `DeviceOutcomeApplier` (Quarantine); `tests/Scenario/Outcome/ApnsDestinationTest.php`, `tests/Unit/Laravel/Devices/DeviceOutcomeApplierTest.php`. |
| Invalidation on APNs 410 only if topic and generation match and `timestamp` (ms) is after registration; FCM `UNREGISTERED` | DONE | `ApnsOutcomeClassifier::timestampMs()` (ms), 410 without reason/timestamp kept; `DeviceOutcomeApplier` (generation check, `StaleTimestamp`); `FcmReasonCatalog` UNREGISTERED. |
| FCM `INVALID_ARGUMENT` read from `error.details`, normally payload: does not invalidate | DONE | `FcmReasonCatalog` lines for INVALID_ARGUMENT (`PayloadInvalid`, "The registration is not touched"); `FcmOutcomeClassifier` reads `details`. |
| A late answer on generation 7 does not touch generation 8, even with the same token | DONE | `DeviceOutcomeApplier` docblock and `GenerationChanged`; `DeviceOutcomeApplierTest.php`. |
| Probe command `mobile-push:devices:probe-environment {--device=} {--quarantined} {--allow-cross-environment}` sends a silent push on the other environment and fixes the label (emulated) | DONE | `Console/Ops/ProbeEnvironmentCommand.php` (all three options plus `--limit`, `--ignore-cooldown`), `EnvironmentProber.php`; `tests/Scenario/Laravel/Console/Ops/ProbeEnvironmentTest.php`. |
| Probe: rate-limited; refused for keys bound to one environment | DONE | `devices.probe.cooldown_hours` (`ProbeCooldownTest.php`); `connections.*.key_environments` refusal (integration-todo W6-E). |
| Probe against the real APNs other environment | BLOCKED | Needs a real `.p8` key and a real device token (owner). |
| The normal environment is the one declared by the app at registration; no automatic environment switch while sending | DONE | `devices.environment` column, `DeviceRegistrar`; no code path changes the environment outside the probe (`docs/devices.md`). |

## §4.5 Single retry budget

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| One budget per destination: absolute deadline = min(logical expiry, `retry.max_elapsed`), `max_attempts`, `next_attempt_at` | DONE | `Core/Retry/RetryBudget.php`, `retry.max_attempts`, `retry.max_elapsed`; `tests/Unit/Core/Retry/BackoffAndBudgetTest.php`. |
| Only `RetryPlanner` plans; transport, sender, job and failover consume the same counter | DONE | `Core/Retry/RetryPlanner.php`, used by `Driver/SendRun.php` and `LedgerRules`; budgets travel in `SendPushJob` and the ledger `budget` column. |
| No long sleep in the worker: beyond a short threshold the attempt becomes `Deferred` | DONE | `retry.inline_threshold_ms` (default 0); `docs/retries-and-limits.md`. |
| Durable: `next_attempt_at` persisted; light queued: a new job with the remaining list, not `release()` | DONE | Deliveries `next_attempt_at`; `SendPushJob` never calls `release()` (only `RelayRecorder::release()`, a lease release); `docs/delivery-profiles.md` "never `release()`s itself"; `LightProfileTest.php`. |
| A deferred attempt past the expiry never touches the network → `Expired` | DONE | `tests/Scenario/Send/ExpiryAndResumeTest.php`, `NowOrNeverTest.php`. |
| Limits counted in messages, keyed by Firebase project / Apple team, not by alias | DONE | `Guard/RateLimiter.php`, `AccountKey.php`; `tests/Scenario/Laravel/Integration/SharedRateBudgetTest.php`. |
| ... then per topic/device when signalled | NOT DONE, ATTEMPTABLE | APNs `TooManyRequests` defers only that destination (`apns.device_rate_limit_delay`), but FCM `QUOTA_EXCEEDED` always pauses the whole project (`FcmReasonCatalog`: "project, device or topic ... sends to this project are throttled"); no per-topic or per-device limiter exists and the choice is not written as a deviation. |
| Reserved quota for transactional traffic | DONE | `guards.rate.reserved_share` (0.2), `TrafficClass`; `tests/Scenario/Guard/RateLimitScenariosTest.php`. |
| `rate.fcm_per_minute` is an application ceiling, not Google's quota | DONE, DIFFERENTLY | Key is `guards.rate.fcm` (env `MOBILE_PUSH_RATE_FCM`), stated as a ceiling in `config/mobile-push.php` and `docs/retries-and-limits.md`. The renaming is **UNDOCUMENTED** as a deviation. |
| Separate breakers: authentication (per credential), quota (per project), destination quality (per binding, minimum sample, controlled probe before reopening) | DONE, DIFFERENTLY | `Guard/AuthBreaker.php`, `DestinationQualityBreaker.php` (min sample, half-open probe batch). No quota breaker class: the quota signal is `ThrottleGate` + `RateLimiter`, written in `docs/contracts/retry.md` and `docs/retries-and-limits.md`. Tests: `tests/Scenario/Guard/BreakerScenariosTest.php`, unit tests per breaker. |
| APNs `ExpiredProviderToken`: one coordinated refresh of the relevant generation, then one attempt (emulated) | DONE | `ApnsReasonCatalog` (`RefreshToken`), `ApnsTokenProvider` (single lock winner); `tests/Scenario/Auth/ApnsProviderTokenLifecycleTest.php`, `tests/Scenario/Send/RefusedBearerTest.php`. |
| ... against the real APNs | BLOCKED | Needs the owner's `.p8` key (integration-todo, "ExpiredProviderToken refresh path"). |
| APNs `TooManyProviderTokenUpdates`: slow renewals, never switch to the secondary | DONE | `ApnsReasonCatalog` (`SlowTokenRefresh`), `AuthBreaker` docblock; `docs/retries-and-limits.md`. |
| APNs 5xx on device send: `Deferred` per Apple (15 min, **fixed with a citation in a fixture**); `Expired` if the expiry comes first | DONE, DIFFERENTLY | 900 s (`apns.server_error_retry_delay`), quote of Apple in `ApnsOutcomeClassifier.php` docblock, `docs/retries-and-limits.md` and `lesson.md`, not in a fixture under `tests/Fixtures/apns`. The location of the citation is **UNDOCUMENTED** as a deviation (minor). |
| FCM `QUOTA_EXCEEDED` / `UNAVAILABLE`: initial minimum 1 min, `Retry-After` when present, jitter never earlier than the minimum | DONE | `fcm.throttled_min_delay`, `Retry/RetryAfter.php` (delta-seconds or HTTP-date), `Retry/Backoff.php` (never below the minimum); `tests/Scenario/Outcome/FcmQuotaAndBatchTest.php`. |
| Generic HTTP/2 error, timeout after send, unparsable answer → `OutcomeUnknown` with evidence kept | DONE | Classifiers return `TransportUnknown`; `SendResult::$meta` / attempt diagnostics (`tests/Scenario/Send/AttemptDiagnosticsTest.php`, `ResponseLostTest.php`). |

## §4.6 Execution modes and endpoints

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| `mode = auto \| live \| simulate`; `auto` = live only with `APP_ENV=production`, otherwise simulate, outcome `Simulated` | DONE | `mode` in config; `Policy/ModeResolver.php`, `ExecutionMode`; `tests/Unit/Core/Policy/ModeResolverTest.php`, `tests/Scenario/Send/SafeModeTest.php`. |
| Real sends outside production only with `live.allow` **and** destinations in `live.only_to`; topics, broadcast, channel management blocked unless explicitly allowed; inconsistency blocks | DONE | `Policy/SafeModeGuard.php`, `LiveSettings` (`allow_topics`, `allow_broadcast`, `allow_channel_management`); `tests/Scenario/Policy/SafeModeScenarioTest.php` (all three cases). |
| The check runs before any external effect and covers CLI, worker, failover, fallback, channel management | DONE | Guard in the send pipeline and `ChannelManagementGate`, topic client gate; `SendTest.php` ("exits 3 ... opens no connection"), `LiveActivities/Console/Support/SafeMode.php`, `tests/Scenario/Laravel/Sending/SafetyStoriesTest.php`. |
| `->validateOnly()` (FCM) is an authenticated remote call → `Validated` | DONE | `PendingSend::validateOnly()`; `tests/Scenario/Fcm/Compile/ExpiryAndValidateOnlyScenarioTest.php`; APNs refused with `UnsupportedCapabilityException` (`PushManager::preflight()`). |
| APNs has no dry run: `preview` is local and does not check credentials; `doctor --live` described as a real request | DONE | `Console/Commands/PreviewCommand.php`, `Support/LocalPreview.php`; `DoctorCommand` option text "Also send REAL probe requests". |
| Official hosts per role (send, channel management, broadcast publish, Google token exchange), not free URLs | DONE | `Policy/EndpointRole.php` (five roles), `EndpointCatalog.php`; `tests/Scenario/Policy/NoArbitraryHostScenarioTest.php`, `tests/Unit/Core/Policy/EndpointCatalogTest.php`. |
| `broadcast_publish_host = standard \| api-broadcast` | DONE, DIFFERENTLY | Implemented as `connections.*.broadcast_host` (`Policy/ApnsBroadcastHost.php`). The key name differs; **UNDOCUMENTED** as a deviation. |
| TLS and hostname/CA verification always on | DONE | `CurlTransport` sets `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2` unconditionally. |
| Test endpoints injectable only with test credentials and never in production; the message cannot name a host | DONE | `Policy/EndpointOverride.php` (`$testCredentials` and not production); `NoArbitraryHostScenarioTest` ("A message cannot influence the URL"). |

## §5 Architecture

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Single package with two layers; architecture test forbids `Core` importing `Illuminate\*` | DONE | `tests/Arch/ArchTest.php` (`Illuminate`, `Laravel`, `Orchestra`, `Carbon`). |
| `Core/Transport`: Transport, Request, Response, TransportEvidence, CurlTransport, CurlCapabilities, FakeTransport | DONE | Files present under `src/Core/Transport`. |
| `Core/Transport/Curl/{ConnectionPool, PoolKey, PoolRegistry, Lane, HeaderParser}`, `RecordingTransport` | DONE, DIFFERENTLY | Names differ: `CurlPool`, `CurlPoolRegistry`, `ResponseHeaderParser`, `Driver/PoolKey`; no `Lane` class (window logic inside `CurlTransport`); no `RecordingTransport` (`FakeTransport::recorded()` records). **UNDOCUMENTED** as a deviation. |
| `Core/Auth`: Signer, OpenSslEs256Signer, OpenSslRs256Signer, DerSignature, PrivateKey, Secret, SecretResolver, TokenStore, RefreshPolicy | DONE, DIFFERENTLY | All present except `RefreshPolicy` (refresh rules live in `ApnsTokenProvider` / `TokenSlot`). **UNDOCUMENTED**. |
| `Core/Outcome`: SendStatus, Reason, ReasonCode, SendResult, SendReport, OutcomeClassifier, Decision | DONE | Files present. |
| `Core/Identity`: Scope, AppBinding, BindingRef, Destination types, DestinationRef | DONE, DIFFERENTLY | No `AppBinding` class: `BindingRef` + `Laravel/Sending/ResolvedConnection`. **UNDOCUMENTED**. |
| `Core/Retry`: RetryPlanner, RetryBudget, Backoff, RateLimiter, ThrottleGate, Breaker{Auth,Quota,Destination} | DONE, DIFFERENTLY | Limiter, gate and breakers are in `Core/Guard`; no quota breaker (documented in `docs/contracts/retry.md`). The move to `Core/Guard` is written in `docs/contracts/retry.md` §37. |
| `Core/Ledger`: EffectStore, DeliveryStore, InMemoryLedger, PayloadFingerprint | DONE | Files present. |
| `Core/Policy`: ExecutionMode, EndpointCatalog, EligibilityGuard, CapabilityMatrix | DONE, DIFFERENTLY | No `EligibilityGuard`: eligibility is a `Driver/DestinationGate` implemented in Laravel (`Sending/Gates/DeviceEligibilityGate.php`, `ConsentGate.php`). **UNDOCUMENTED** as a deviation. |
| `Core/Message`: PushMessage, MessageCompiler, PayloadLimits, Truncator, JsonEncoder, Expiry | DONE, DIFFERENTLY | `ApnsPayloadLimits` / `FcmPayloadLimits` per driver; no `Expiry` class (`PushMessage::resolveExpiry()`). **UNDOCUMENTED**. |
| `Core/Driver`: Driver, Sender, capability interfaces `SupportsLiveActivities`, `ManagesChannels`, `ManagesTopicSubscriptions` | DONE, DIFFERENTLY | `Driver`, `Sender` present; no capability interfaces (channel management is `Apns/Channels/ChannelClient`, topics `Fcm/Topics/TopicSubscriptionClient`). **UNDOCUMENTED**. |
| `Core/Apns`: ApnsDriver, ApnsConfig, ApnsTokenProvider, CertificateAuth, TopicResolver, PushType, Priority, Message/…, LiveActivity/{LiveActivityMessage, LiveActivityType, BroadcastPublisher}, Channels/… | DONE, DIFFERENTLY | All present except `CertificateAuth` (certificate auth in `ApnsCredential`) and `BroadcastPublisher` (broadcast route inside `ApnsDriver`). **UNDOCUMENTED**. |
| `Core/Fcm`: FcmDriver, FcmConfig, Auth/{ServiceAccountTokenProvider, GoogleAuthLibraryProvider}, Message/{FcmMessage, AndroidConfig, AndroidConfigV2, ApnsOverride}, Topics/TopicSubscriptionClient | DONE, DIFFERENTLY | `ApnsOverride` is `Message/FcmApnsConfig.php`; the rest present. **UNDOCUMENTED** (naming). |
| `Core/Privacy`: Redactor, TokenIndex (HMAC), TokenCipher | DONE | Files present (plus `KeyRing`, `TokenMasker`). |
| `Laravel`: ServiceProvider, PushManager, PendingSend, Facades, helpers.php, Config/ConfigSchema | DONE, DIFFERENTLY | `ConfigSchema` is in `Laravel/Integration/`, manager and pending send in `Laravel/Sending/`. **UNDOCUMENTED** (layout). |
| `Laravel` dirs Ledger, Cache, Notifications, Jobs, Events, Testing, Devices, LiveActivities, Campaigns, Receipts, Logging, Health, Pulse, Telescope, Console | DONE, DIFFERENTLY | All present except `Jobs/` and `Events/` as top-level dirs: jobs are in `Queue/`, `Campaigns/`, `Integration/`; events in `Sending/Events`, `Campaigns/Events`, `Receipts/Events`, `Queue/Events`. **UNDOCUMENTED** (layout). |
| `config/mobile-push.php`, `database/migrations/*.stub`, `docs/`, `benchmarks/` | DONE, DIFFERENTLY | Migrations are `.php` files published by tag (`ModuleMigrations::wire()`, AGENTS.md "Laravel layer: modules"), not `.stub`. The publish rule is documented (AGENTS.md, `docs/upgrading.md` not verified by this audit); the file extension change is not called a deviation anywhere. |

## §6.1 Transport and pool

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Persistent `CurlMultiHandle` per pool key = effective TLS/auth identity (team + key id, or SHA-256 of the mTLS certificate, or Google principal), endpoint/role, environment, proxy/TLS profile, generation | DONE | `Core/Driver/PoolKey::derive()` (all inputs listed in its docblock); `tests/Unit/Core/Driver/PoolKeyTest.php`, `tests/Scenario/Send/PoolIsolationTest.php`. |
| Per-process registry with global limit, idle expiry (LRU), controlled close, reset on PID change | DONE | `Curl/CurlPoolRegistry.php` (`maxPools`, LRU, `maxIdle`, `getmypid()`); `transport.max_pools`; `tests/Scenario/Runtime/ForkSafetyTest.php`, `tests/Scenario/Transport/ConnectionPoolTest.php`. |
| Handle with a single owner, also under Octane | DONE | In-use pools never evicted; `tests/Scenario/Runtime/OverlappingSendsTest.php`, `tests/Scenario/Laravel/Runtime/OctaneSandboxTest.php`; Octane jobs in `backends.yml` and `tests.yml` (`runtimes`). |
| In-flight limit distinct from connections and peer-granted streams | DONE | `ConnectionProfile::$window` vs `maxConnections`, `CURLMOPT_MAX_CONCURRENT_STREAMS`; AIMD window in `CurlTransport`. |
| Prudent concurrency defaults, raised only after M1 measurements | DONE | `transport.window` 100, `max_connections` 1; measurements in `benchmarks/results/window-sweep.json`, `connections-sweep.json`, `docs/transport.md`. |
| Check effective capabilities (HTTP/2, ALPN, mTLS blob): if missing, error with explicit remedy | DONE | `Curl/CurlCapabilities::assertSupports()` (each throw carries "Remedy:"); `tests/Unit/Core/Transport/CurlCapabilitiesTest.php`. |
| If a runtime fails the reuse tests, reuse is switched off for that runtime | DONE | `transport.reuse = auto \| on \| off`, `Curl/ConnectionReuse.php`, `CoroutineCurlHook.php`; `tests/Unit/Laravel/Sending/ReuseSettingTest.php`, `swoole-coroutine-hooks` job in `backends.yml`. |
| Never an exception mid-batch; `CURLOPT_HEADERFUNCTION`; separate connect and request timeouts; proxy supported | DONE | `CurlTransport` (`CURLOPT_HEADERFUNCTION`, `CURLOPT_CONNECTTIMEOUT_MS`, `CURLOPT_TIMEOUT_MS`, `CURLOPT_PROXY`); `tests/Scenario/Transport/MixedBatchTest.php`, `ProtocolNegotiationTest.php` (proxy). |

## §6.2 Authentication

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| `Signer` (`sign()`, `algorithm()`, `keyId()`), internal OpenSSL implementations | DONE | `Core/Auth/Signer.php`, `OpenSslEs256Signer.php`, `OpenSslRs256Signer.php`. |
| A non-exportable KMS integrates by implementing `Signer` (emulated) | DONE | `connections.*.signer` (ConfigSchema line for `signer`); `tests/Scenario/Laravel/Sending/ExternalSignerTest.php`, `KmsSignerTest.php` (AWS KMS API emulator), `Console/ExternalSignerConsoleTest.php`. |
| ... against a real KMS/HSM | BLOCKED | Needs an AWS (or other KMS) account. |
| `SecretResolver` for exportable secrets | DONE | `Core/Auth/SecretResolver.php`. |
| APNs ES256 on P-256, JWS `R\|\|S` 64 bytes from OpenSSL DER | DONE | `Auth/DerSignature.php`; `tests/Unit/Core/Auth/DerSignatureTest.php` (leading-zero cases), `SignersTest.php` (RFC 7515 A.3, cross-check with `firebase/php-jwt`). |
| Google: RS256 service-account assertion exchanged for an OAuth access token (native provider) | DONE | `Fcm/Auth/ServiceAccountTokenProvider.php`; `tests/Scenario/Auth/GoogleTokenExchangeTest.php`, `GoogleServiceAccountRulesTest.php`. |
| ADC and metadata server through `google/auth` (emulated) | DONE | `Fcm/Auth/GoogleAuthLibraryProvider.php`; `tests/Scenario/Laravel/Sending/AdcOnGoogleCloudTest.php` (metadata server emulated), `GoogleAuthLibraryProviderTest.php`. |
| Impersonation through `google/auth` | DONE | `tests/Scenario/Auth/GoogleAuthLibraryRulesTest.php` ("impersonated service account", identity read offline). The token exchange of impersonation is not exercised; not verified by this audit beyond that test name. |
| Workload Identity Federation through `google/auth` (emulated) | NOT DONE, ATTEMPTABLE | No test uses an `external_account` credential (grep for `external_account`/`workload` in `tests/` finds nothing); delegated to `google/auth` untested. |
| ADC / WIF on Google Cloud itself | BLOCKED | Needs a GCP project (integration-todo). |
| Token cache key = hash of (scope, provider, identity, OAuth scope, delegation context), a name, not crypto material | DONE | `Auth/TokenCacheKey.php` ("It is a name, not cryptographic material"). |
| Cache value protected with authenticated encryption (**Laravel Encrypter**) and a rotatable, optionally dedicated key | DONE, DIFFERENTLY | `Laravel/Cache/CacheTokenStore.php` uses `Privacy/TokenCipher` (AES-256-GCM, dedicated key ring, bound to the cache key), not Laravel's Encrypter. Stated as the implementation in `docs/security-and-privacy.md` (Cache row); not listed among the decided deviations. |
| Concurrent refreshes reuse the valid token (non-blocking lock + compare-and-swap) | DONE | `ApnsTokenProvider` (single lock winner), `CacheTokenStore::compareAndSwap`; `tests/Unit/Core/Auth/TokenStoreRulesTest.php`, `ApnsProviderTokenLifecycleTest.php` (fifty workers, one token). |
| Cache unavailable → local reuse within Apple's limits, or `Deferred`; never one signature per message | DONE | In-process memo first (`ApnsTokenProvider`); `tests/Scenario/Laravel/Release/RetriesWithCacheDownTest.php`, `tests/Unit/Laravel/Cache/CacheTokenStoreBoundariesTest.php`. |
| Secondary credential with the same team/project/environment identity, verified at boot | DONE | `ConnectionFactory` (`sameIdentity()` check on `secondary`); `tests/Unit/Laravel/Sending/ConnectionFactoryTest.php`. |
| Switch decided once by the auth breaker, not per recipient | DONE | `Guard/AuthBreaker.php` ("switches to the secondary instead of opening, once"); `tests/Scenario/Laravel/Integration/KeyRevokedTest.php`, `tests/Unit/Core/Guard/AuthBreakerTest.php`. |
| Rotation or revocation drains pools and cache | DONE, DIFFERENTLY | Pools closed (`CredentialRotationTest.php`); the old cached token is left to expire, unreachable. Written in integration-todo "Deviations kept on purpose" (§6.2). |

## §6.3 Messages, limits and capabilities

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Small unified `PushMessage` + native builders; overlays `->apns(fn)` / `->fcm(fn)` run immediately and serialisable | DONE | `Core/Message/PushMessage.php` (`apns()`, `fcm()`), `ApnsMessage::overlay()`, `FcmMessage::overlay()`; `tests/Unit/Core/Apns/ApnsMessageOverlayTest.php`. |
| Limits per driver, target and type, applied after overlay, encoding and receipt marker: APNs 4096 (VoIP and broadcast 5120); FCM 4096, 2048 for topic/condition, measured on the FCM payload | DONE | `Apns/ApnsPayloadLimits.php` (4096/5120/5120), `Fcm/FcmPayloadLimits.php` (4096/2048); `tests/Scenario/Apns/Compile/ByteLimitTest.php`, `tests/Scenario/Fcm/Compile/PayloadLimitScenarioTest.php`, `tests/Unit/Core/Fcm/Message/PayloadSizeRuleTest.php`, marker in `CompileRulesTest.php`. |
| Reject by default; optional truncation only of title/subtitle/body, never ids, URLs, data or ActivityKit state | DONE | `PushMessage::truncate()`; `docs/messages.md` "Size limits and truncation"; `CompileRulesTest.php` ("shortens a title when the title is the only text"). |
| Graphemes with `ext-intl` if present, else PCRE `\X`; both paths tested at exact limits and +1 byte | DONE | `Message/Support/Truncator.php` (`MODE_INTL`, PCRE); `tests/Unit/Core/Message/TruncatorTest.php` ("max - 1, max and max + 1", both modes). |
| Validation of UTF-8, reserved keys, string values, conflicts between `silent`, sound, alert and priority | DONE | `ApnsCompiler`, `FcmCompiler`; `tests/Scenario/Apns/Compile/CompileRulesTest.php`, `tests/Scenario/Fcm/Compile/CompileRulesScenarioTest.php`, `SilentToIosScenarioTest.php`. |
| Logical expiry `->expiresAt()` / `->ttl()`: APNs epoch and FCM remaining TTL recomputed each attempt, never extended | DONE | `PushMessage::expiresAt()`, `ttl()`, `resolveExpiry()`; `ExpiryAndResumeTest.php`, `ExpiryAndValidateOnlyScenarioTest.php`. |
| Capability matrix published and applied at runtime ("native / app code / unavailable"); missing capability = validation error; `->lenient()` drops it with a warning in the report | DONE, DIFFERENTLY | `Policy/CapabilityMatrix.php`, table in `docs/messages.md`; enforcement is in the compilers, not read from the matrix ("The matrix is a reference: the compilers enforce the unavailable cells themselves", `docs/messages.md`). Lenient warnings: `tests/Scenario/Laravel/Sending/LenientWarningsTest.php`. No test ties each "unavailable" cell to a compiler refusal. |
| `->actionCategory()` distinct from `->policy('marketing')` | DONE | `PushMessage::actionCategory()`, `policy()`; `docs/contracts/messages.md`. |

## §6.4 FCM

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| One target per request among `fid`, `token`, `topic`, `condition`; `token` deprecated but supported; origin type kept, no heuristic conversion | DONE | `FcmCompiler`; `tests/Scenario/Fcm/Compile/TargetsScenarioTest.php`. |
| An installation with FID and token receives one send per effect (`fcm.prefer = fid \| token`) | DONE, DIFFERENTLY | Key is `devices.fcm_prefer` (integration-todo "Closed", W6-A); `tests/Scenario/Laravel/Devices/FcmPreferenceStoriesTest.php`. Rename recorded there. |
| `android` and `androidV2` mutually exclusive, builder refuses both | DONE | `Fcm/Message/FcmMessage.php` (`InvalidMessageException` both ways); `tests/Unit/Core/Fcm/Message/FcmMessageTest.php`. |
| Topic publish separate from subscription management (`topicSubscriptions` create/get/list/patch/delete per installation); no bulk promised | DONE | `Fcm/Topics/TopicSubscriptionClient.php` (`subscribe`, `get`, `list`, `patch`, `unsubscribe`; `*Many()` multiplex single calls); `tests/Scenario/Fcm/Topics/TopicSubscriptionTest.php`, `tests/Unit/Core/Fcm/Topics/TopicDiscoveryContractTest.php`. |
| ... against the real FCM | BLOCKED | Needs a Firebase service account and a registration token. |
| No use of the legacy IID API | DONE | No `iid` endpoint in `EndpointCatalog`; `docs/topics.md`. |
| Discovery snapshot in tests with revision and fingerprint | DONE | `tests/Fixtures/fcm/discovery-v1.json`, `discovery-v1.meta.json` (rev `20261112`, sha256); `tests/Unit/Core/Fcm/Message/DiscoverySchemaContractTest.php`. |
| A scheduled workflow compares upstream and opens an issue with the diff | DONE | `.github/workflows/providers.yml` job `fcm-discovery` (weekly cron, `gh issue create`/`comment`). |

## §6.5 Live Activities

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Disjoint operations and tokens: `start` → push-to-start (iOS 17.2+); `update`/`end` → activity token or broadcast channel (iOS 18+); broadcast never starts | DONE | `ApnsLiveActivityToken`, `ApnsPushToStartToken`, `ApnsBroadcastChannel`; `ChannelHandle::start()` throws; `tests/Scenario/Apns/Compile/LiveActivityCompileTest.php`, `CompileRulesTest.php` ("names the destination and the event"). |
| Explicit mapping to the Swift type: `LiveActivityType` with `swiftName()` and `version()` | DONE | `Apns/LiveActivity/LiveActivityType.php` (an interface, as shown in `docs/live-activities.md`), `Laravel/LiveActivities/TypeRegistry.php`. |
| Fixture shared with the iOS test app (emulated part: an app that decodes the fixtures) | NOT DONE, ATTEMPTABLE | `tests/Fixtures/apns/la-*.json` exist; no iOS app, no Swift code in the repository (`docs/live-activities.md`: "The Swift snippets were not compiled"). |
| ... on a real device | BLOCKED | Needs a physical iPhone and the owner's APNs key. |
| Tokens with generation and rotation; serialised transitions per activity/channel; `end` terminal, an old update never reopens it | DONE | `Transition/TransitionRules.php` (ticket order, terminal states), `TokenChange`; `tests/Scenario/Laravel/LiveActivities/TokenRotationTest.php`, `ConcurrentWorkersTest.php`, `DeliveryStoryTest.php` ("a stale update job sends nothing"). |
| `timestamp` = current Unix second; two updates in the same second coalesced (last wins) or deferred; no invented future second | DONE | `TransitionRules` rule 3; `DeliveryStoryTest.php` ("two updates in the same second"). |
| Server limits high-priority attempts (`high_priority.max_per_hour`); does not measure the device budget | DONE | `live_activities.high_priority.max_per_hour` (20), `Transition/HourlyLimiter.php`; `HighPriorityBudgetTest.php`. |
| "Automatic close" distinguishes local expiry, accepted `end`, state seen by the client | DONE | `LiveActivities/CloseReason.php`, `ClientActivityState.php`, local expiry in `TransitionRules` rule 1. |
| Broadcast: channel management and publication separate profiles per operation and environment; one acceptance = one publication | DONE | `EndpointRole::ApnsChannelManagement` / `ApnsBroadcastPublish`; `docs/live-activities.md` ("one publication, not N deliveries"); `MatchDayBroadcastTest.php`. |
| Channel reconciliation when the creation answer is lost (`channels:sync`), limits and cleanup | DONE | `Apns/Channels/ChannelReconciliation.php`, `ChannelLimitReachedException`, `ChannelsSyncCommand`; `tests/Scenario/Apns/Channels/ChannelManagementTest.php`, `LiveActivities/Console/BroadcastChannelsTest.php`. |
| ... against the real broadcast API | BLOCKED | Needs the owner's APNs key with broadcast enabled. |

## §6.6 Queues

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| `doctor` verifies the chain I/O timeout < chunk deadline < job `timeout` < Horizon/worker timeout < `retry_after` (or SQS visibility), each with margin | DONE | `Queue/TimeoutChain.php`, `QueueTimeouts.php`, `Console/Support/Doctor.php`; `tests/Scenario/Laravel/Queue/TimeoutChainTest.php`, `tests/Unit/Laravel/Queue/QueueTimeoutsTest.php`, `Install/StockQueueDefaultsTest.php`. |
| Overall chunk deadline; chunks small in bytes too | DONE | `queue.chunk_deadline`, `queue.chunk_bytes`; `Queue/ChunkDeadline.php`, `DestinationChunker.php`; `LightChunkDeadlineTest.php`, `tests/Unit/Laravel/Queue/ChunkDeadlineTest.php`. |
| Durable: jobs carry id and checkpoint; light: small token lists in encrypted jobs (`ShouldBeEncrypted`) | DONE | `RelayDeliveriesJob` (ids only; the checkpoint is the ledger rows); `SendPushJob implements ShouldBeEncrypted`. |
| One owner of queueing: a `ShouldQueue` Notification sends inside that job, otherwise the package job (`SendChunkJob`) | DONE, DIFFERENTLY | Behaviour in `tests/Scenario/Laravel/Notifications/QueueOwnershipTest.php`; the job is named `SendPushJob`. Name **UNDOCUMENTED** as a deviation. |
| Parity declared only for drivers verified in CI (`sync`, `redis`, `database`, SQS via emulator) | DONE | `tests.yml` (sync in the suite, `runtimes` on Redis, `fresh-app` on MySQL database queue), `backends.yml` (ElasticMQ, beanstalkd); `docs/queues-and-horizon.md`. |
| Where the backend cannot be inspected `doctor` says "not verifiable" | DONE | `ChainStatus::NotVerifiable` → `DoctorCheck::notVerifiable` in `Doctor.php`. |
| Horizon tags and supervisor block printed by `install` | DONE | `tags()` on every job; `Console/Support/HorizonBlock.php`; `tests.yml` `runtimes` job runs Horizon with the printed supervisors. |
| The queue method is `->onQueueConnection()`, distinct from the push connection | DONE | `PendingSend::onQueueConnection()`. |

## §7 Public API

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| `MobilePush::to($user)->send(PushMessage::make(...)->data()->expiresAt()->policy())` returns `SendReport` | DONE | `PushManager::to()`, `PendingSend::send(): SendReport`; README "A unified message to a user" (marked example, run by `DocExamplesTest`). |
| `MobilePush::connection('ios-consumer')->to(ApnsDeviceToken::of($hex))->send($msg)` | DONE | `PushManager::connection()`, `PendingSend::to()`. |
| `MobilePush::connection('android')->to(FcmTopic::of('news'))->validateOnly()->send($msg)` | DONE | `PendingSend::validateOnly()`; `docs/topics.md`. |
| `MobilePush::to($users)->queue($msg)->onQueueConnection('redis')->onQueue('push')` | DONE, DIFFERENTLY | Options before `queue()`, which returns the effect id. Written in integration-todo "Deviations kept on purpose" (§7). |
| `mobile_push($user)->send($msg)` | DONE | `src/Laravel/helpers.php` (`function_exists` guard). |
| `mobile_push_to_token(Platform::Ios, $token, 'Titolo', 'Testo')`, platform mandatory | DONE | `helpers.php`. |
| `$report->counts(); results(); each(fn (SendResult $r) => ...)` | DONE | `SendReport`. |
| `$result->status; reason->code; reason->rawReason; reason->hint` | DONE | `SendResult`, `Reason`. |
| `LiveActivity::start(Type::class, attributes:, state:)->alert()->to($user)->send()` | DONE | `LiveActivities/LiveActivity::start()`, `PendingStart::alert()`, `to()`, `send()`. |
| `LiveActivity::for($activity)->update([...])->send()` | DONE | `LiveActivity::for()`, `ActivityHandle::update()`, `PendingTransition::send()`. |
| `LiveActivity::broadcast($channel)->update([...])->send()` | DONE | `LiveActivity::broadcast()`, `ChannelHandle::update()`. |
| `MobilePush::fake(); MobilePush::assertSent(fn (SentPush $p) => ...)` | DONE, DIFFERENTLY | `MobilePush::fake()` returns a `PushFake` and assertions are called on it (`$fake->assertSent()`); the facade accessor is `PushManager`, which has no `assertSent()`, so the static form of the plan does not work. Docs show the instance form (`docs/testing.md`), but the deviation is **UNDOCUMENTED**. |
| `MobilePush::fakeTransport()` verifies bytes and protocol | DONE | `Facades/MobilePush::fakeTransport()`, `Testing/FakeTransportBuilder.php`; `tests/Scenario/Laravel/Testing/WireTest.php`. |
| `mobile-push:send` with `--platform` / `--connection`, `--stdin`, `--file`, positional tokens; interactive proposes the platform and asks for confirmation | DONE | `Console/Commands/SendCommand.php` signature, `Support/PlatformProposal.php`; `tests/Scenario/Laravel/Console/SendTest.php` ("proposes the platform ... confirms", "refuses to guess ... when it cannot ask"). |
| Documented distinct exit codes for validation, partial results, simulated, unknown outcomes | DONE | `Support/SendExit.php` (0, 1 partial, 2 invalid, 3 simulated, 4 unknown); `docs/commands.md`; `SendTest.php` (exit 3, exit 4). |

## §8 Modules and functions

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| All modules disabled by default | DONE, DIFFERENTLY | Devices, receipts, Live Activities, frequency cap, campaigns, Pulse, Prometheus, alerts, log sinks are off. On by default: `metrics.enabled` (true), `badge.enabled` (true, acts only with `incrementBadge()`), `fallback.enabled` (true, acts only for notifications with `pushFallback()`), `telescope.enabled` (`auto`), health. Written as the defaults in README "Features at a glance" and `docs/observability.md`; not listed as a deviation from the plan. |
| Devices: polymorphic owner, scope, binding, platform, declared environment, token kind, generation, state `active/quarantined/invalidated`, encrypted token + HMAC index | DONE | `database/migrations/2026_11_20_000001_create_mobile_push_devices_table.php`; `Devices/PushDevice.php`, `DeviceState.php`; `tests/Scenario/Laravel/Devices/DeviceRegistryStoriesTest.php`. |
| Devices: environment probe (§4.4) | DONE | See §4.4 rows. |
| Eligibility called right before the network: owner unchanged, device active, current generation, host consent (`MobilePush::eligibleUsing()`); covers revocation, logout, phone given to another user | DONE | `Sending/Gates/DeviceEligibilityGate.php`, `ConsentGate.php`, `PushManager::eligibleUsing()`; `tests/Scenario/Laravel/Sending/DeviceStoriesTest.php` ("phone that changed hands"), `DurableRelayTest.php`. |
| Live Activities module (§6.5) | DONE | See §6.5 rows. |
| Log sinks `log`, `database`, `otel` in any combination | DONE | `Logging/LogChannelSink.php`, `DatabaseSink.php`, `OpenTelemetrySink.php`, `log.sinks`; `tests/Scenario/Laravel/Logging/ChooseSinksTest.php`. |
| A failing sink never makes an accepted send retry | DONE | `SinkManager` isolation; `tests/Scenario/Laravel/Logging/DatabaseDownTest.php`, `Integration/BrokenMetricsStoreTest.php`. |
| Metrics: queue age, unknowns, deferred/expired, buffer drops, open circuits | NOT DONE, ATTEMPTABLE | Unknowns, deferred/expired (`mobile_push_results_total` by status, `ledger_unknown`, `ledger_deferred`), drops (`mobile_push_log_records_dropped_total`), circuits (`mobile_push_circuit_open`) exist. Queue age exists only as `mobile_push_ledger_oldest_due_age_seconds` (durable profile); nothing measures the age of the light profile's queued jobs, and that limit is not stated. |
| Pulse and Telescope | DONE | `Laravel/Pulse/*`, `Laravel/Telescope/PushWatcher.php`; `tests/Scenario/Laravel/Pulse/DashboardScenariosTest.php`, `Telescope/FailedPushScenariosTest.php`. Browser check made once by hand (`docs/observability.md`), not in a workflow. |
| Health: liveness, readiness and live probe separate | DONE | `Health/HealthInspector.php` (liveness/readiness), `mobile-push:health`, live probe = `doctor --live`; `HealthScenariosTest.php`. |
| Check for `spatie/laravel-health` | DONE | `Health/MobilePushHealthCheck.php`; `SpatieCheckTest.php`. |
| Prometheus endpoint not public by default, no user/device/campaign id as label | DONE | `metrics.prometheus.enabled` false, `RequireMetricsToken` middleware; `PrometheusExporter` bounded labels; `tests/Unit/Laravel/Health/PrometheusFormatTest.php`, `Logging/BoundedMetricsTest.php`. |
| Credentials: primary + secondary (§6.2), `mobile-push:credentials:check`, mail/Slack alerts with cooldown | DONE | `Console/Ops/CredentialsCheckCommand.php`, `Health/CredentialMonitor.php`, `OpsAlerter.php`, `SlackWebhookChannel.php`; `tests/Unit/Laravel/Health/OpsAlerterCooldownTest.php`, `Console/Ops/HealthAndCredentialsTest.php`. |
| Frequency cap and badge decided once per logical effect and owner, not per device or retry | DONE | `Policy/Frequency/*`, `Policy/Badge/BadgeResolver.php`; `tests/Scenario/Laravel/Policies/PoliciesScenarioTest.php`, `Integration/BadgeGatesTest.php`, `Runtime/PolicyCoordinationTest.php`. |
| `bypassFrequencyCap()` does not bypass consent | DONE | `PushMessage::bypassFrequencyCap()`; `docs/policies.md` "Bypass versus consent"; `tests/Scenario/Laravel/Sending/DeviceStoriesTest.php` ("stops a push to a user who opted out ... even with bypassFrequencyCap"). |
| Cap and badge not applicable to topics and broadcast | DONE | `tests/Scenario/Laravel/Policies/BadgeBroadcastTest.php`; config comment "Never applied to topics, broadcasts". |
| Fallback only with all outcomes terminal, no `Accepted` nor unknown, fresh preference check for that channel, own id; `Skipped` for opt-out does not trigger; not for topic/broadcast | DONE | `Notifications/FallbackRules.php` (rules 1-8), `FallbackPolicy`, `FallbackEvaluator` (clone with its own deterministic id); `tests/Unit/Laravel/Notifications/FallbackRulesTest.php`, `FallbackStoriesTest.php`, `DurableFallbackTest.php`. |
| Receipts need the durable profile | DONE | `docs/receipts.md`; `ReceiptsModule`. |
| Receipts: distinct `provider_accepted`, `client_reported_received`, `client_reported_opened` | DONE | Deliveries `accepted_at`, `client_received_at`, `client_opened_at`; `Receipts/ReceiptRecorder.php`; `ReceiptsEndpointTest.php`. |
| Marker per event and destination with expiry and key version; HMAC key stays on the server | DONE, DIFFERENTLY | One marker per delivery with the event type in the callback (`Core/Receipts/ReceiptMarker.php`, `receipts.marker_ttl`, `receipts.keys` versions). Written in integration-todo "Deviations kept on purpose" (§8 receipts). |
| Uniqueness `(delivery_id, event_type)`; duplicates and out-of-order tolerated; missing callback = "not observed" | DONE | One column per event on the delivery row; `tests/Scenario/Receipts/ReceiptStoriesTest.php`; `docs/receipts.md` ("not observed", never "not delivered"). |
| Funnel denominator and coverage visible | DONE | `Ledger/ReceiptFunnel.php` (`markerCoverage()`); `docs/receipts.md` example `receipts-funnel-denominators`. |
| No individual receipt from topic/broadcast | DONE | Marker only for individual installations (`ReceiptMarkerFlagTest.php` "does not flag a topic"). |
| Campaigns need the durable profile | DONE | `LifecycleTest.php` ("light profile: dispatching a campaign throws"). |
| Campaigns: stable upper bound fixed at planning + final eligibility check | DONE | `CampaignPlanner` (never past the upper bound); `LifecycleTest.php` ("the upper bound is fixed at the start"), `SendingRulesTest.php`. |
| Campaign + destination unique in the ledger | DONE | One effect per campaign, one delivery per logical destination (`CampaignPlanner` step 4); `WorkersKilledTest.php` ("writes its slice once"). |
| `pause` stops new acquisitions, `cancel` does not withdraw in-flight, `resume` does not recompute from zero | DONE | `tests/Scenario/Laravel/Campaigns/ControlsTest.php` (pause/resume from the cursor, cancel keeps the accepted). |
| Confidentiality: authenticated encryption with key version | DONE | `Privacy/TokenCipher.php` (`v<version>:` envelope), `privacy.encryption_keys`; `tests/Unit/Core/Privacy/TokenCipherTest.php`. |
| HMAC index with a separate, rotatable secret | DONE | `Privacy/TokenIndex.php`, `privacy.index_keys`; `tests/Scenario/Privacy/IndexKeyRotationScenarioTest.php`. |
| `Redactor` before every sink (URL, headers, provider errors, OTel attributes) | DONE | `Privacy/Redactor.php`; `tests/Unit/Core/Privacy/RedactorTest.php`, `tests/Scenario/Laravel/Release/ConfidentialityCanariesTest.php` (log, table, span, metric, Pulse, Telescope). |
| `preview` never shows bearer, token or markers | DONE | `tests/Scenario/Laravel/Console/PreviewTest.php`, `Console/Ops/CanaryTest.php`. |
| Payload `full` off | DONE | `log.payload` default `none`. |
| `forget` and `export` scoped, with tombstones against reappearance from pending jobs | DONE | `Privacy/Erasure/OwnerEraser.php`, `OwnerExporter.php`, `cancelled` tombstone state; `ForgetAndExportTest.php` ("the relay never contacts the provider for them", "light profile ... never reaches the provider"). |
| Coverage and external retention documented; no GDPR promise from the command alone | DONE | `docs/security-and-privacy.md` (opening notice, "External collectors", retention rows); `ForgetAndExportTest.php` ("does not make the app compliant"). |
| DX: `explain`, `preview`, `simulator` (checks the rendering, not the APNs infrastructure) | DONE | `ExplainCommand` + `ExplainCatalog`, `PreviewCommand`, `SimulatorCommand` + `XcrunRunner`; tests per command; `simulator.yml` ran `xcrun simctl push` once on macOS. |
| Helpers for rich content according to the capability matrix | DONE | `PushMessage::image()`, `deepLink()`, `actionCategory()`, `mutableContent()`; `docs/messages.md`. |

## §9 Artisan commands

Every command below exists as `mobile-push:<name>` (signatures read from `src/Laravel`). The `fresh-app` job of
`tests.yml` asserts 38 `mobile-push:*` commands on a fresh application with every module enabled.

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| `install` | DONE | `Console/Commands/InstallCommand.php`; `tests/Scenario/Laravel/Console/InstallTest.php`, `Install/*`. |
| `doctor {--live}` (local checks and emulated live probe) | DONE | `DoctorCommand.php` (`--live`); `tests/Scenario/Laravel/Console/DoctorTest.php`. |
| `doctor --live` against the real providers | BLOCKED | `live-probes.yml` exists but has never run: needs the owner's APNs key and Firebase service account as environment secrets. |
| `send` | DONE | `SendCommand.php`; `SendTest.php`. |
| `preview` | DONE | `PreviewCommand.php`; `PreviewTest.php`. |
| `explain` | DONE | `ExplainCommand.php`; `ExplainTest.php`. |
| `simulator` | DONE | `SimulatorCommand.php`; `SimulatorTest.php`; `simulator.yml` (macOS runner, ran once). |
| `connections` | DONE | `ConnectionsCommand.php`; `ConnectionsTest.php`. |
| `circuit` | DONE | `CircuitCommand.php`; `CircuitTest.php`. |
| `relay` (durable profile) | DONE | `Console/Ops/RelayCommand.php`; `RelayAndSweepTest.php`. Extra command `sweep` also exists. |
| `deliveries:prune` | DONE | `PruneDeliveriesCommand.php`; `Console/Ops/PruneTest.php`. |
| `devices:prune` | DONE | `PruneDevicesCommand.php`; `PruneTest.php`. |
| `devices:probe-environment` | DONE | `ProbeEnvironmentCommand.php`; `ProbeEnvironmentTest.php`, `ProbeCooldownTest.php`. |
| `logs:prune` | DONE | `PruneLogsCommand.php`; `PruneTest.php`, `Logging/RetentionTest.php`. |
| `live-activity:start` | DONE | `LiveActivities/Console/StartCommand.php`; `StartFromTerminalTest.php`. |
| `live-activity:update` | DONE | `UpdateCommand.php`; `UpdateAndEndTest.php`. |
| `live-activity:end` | DONE | `EndCommand.php`; `UpdateAndEndTest.php`. |
| `live-activity:prune` | DONE | `PruneCommand.php`; `ListAndPruneTest.php`. Extras `live-activity:list`, `live-activity:flush` also exist. |
| `channels:list` | DONE | `ChannelsListCommand.php`; `BroadcastChannelsTest.php`. |
| `channels:create` | DONE | `ChannelsCreateCommand.php`; `BroadcastChannelsTest.php`. |
| `channels:delete` | DONE | `ChannelsDeleteCommand.php`; `BroadcastChannelsTest.php`. |
| `channels:sync` | DONE | `ChannelsSyncCommand.php`; `BroadcastChannelsTest.php`. Extra `channels:publish` also exists. |
| `fcm:topic-subscription` | DONE | `Console/Ops/FcmTopicSubscriptionCommand.php`; `Console/Ops/FcmTopicSubscriptionTest.php`. |
| `campaign:status` | DONE | `Campaigns/Console/StatusCommand.php`; `Campaigns/Console/WatchingTest.php`. |
| `campaign:pause` | DONE | `PauseCommand.php`; `Campaigns/Console/ControlsTest.php`, `RefusalsTest.php`. |
| `campaign:resume` | DONE | `ResumeCommand.php`; same tests. |
| `campaign:cancel` | DONE | `CancelCommand.php`; same tests. Extras `campaign:list`, `prune`, `reconcile`, `sweep` also exist. |
| `credentials:check` | DONE | `CredentialsCheckCommand.php`; `HealthAndCredentialsTest.php`, `CredentialVerdictsAgreeTest.php`. |
| `health` | DONE | `HealthCommand.php`; `HealthAndCredentialsTest.php`. |
| `forget` | DONE | `ForgetCommand.php`; `ForgetAndExportTest.php`. |
| `export` | DONE | `ExportCommand.php`; `ForgetAndExportTest.php`. |
| Every command documented with options and exit codes | DONE | `docs/commands.md` names all 38 commands. |

## §10 Gated roadmap

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| M0: §4 documents in `docs/contracts/` | DONE | `docs/contracts/{outcomes,identity,ledger,classification,retry,modes,messages}.md` + `README.md`. |
| M0: target/capability table | DONE | `CapabilityMatrix`, `docs/messages.md`, `docs/contracts/messages.md`. |
| M0: skeleton, CI, architecture test | DONE | `.github/workflows/tests.yml`, `tests/Arch/ArchTest.php`. |
| Contract gate: one declared public behaviour for "response lost", "token renewed", "tenant reassigned" | DONE | `docs/contracts/README.md` (the three paragraphs). |
| M1 transport + mock | DONE | `src/Core/Transport`, `tests/Support/H2Mock/server.mjs`; `tests/Scenario/Transport/*`. |
| M2 APNs | DONE | `src/Core/Apns`; `tests/Scenario/Apns/*`, `tests/Scenario/Outcome/Apns*`. |
| M3 FCM | DONE | `src/Core/Fcm`; `tests/Scenario/Fcm/*`, `FcmQuotaAndBatchTest.php`. |
| M4 Laravel layer, light profile: manager, modes, report, fake, helpers, CLI | DONE | `src/Laravel/{Sending,Testing,Console}`, `helpers.php`. |
| Alpha gate: the mock records the request and closes before the response → one unknown outcome, no target missing from the report | DONE | `tests/Scenario/Transport/ResponseLostTest.php`, `tests/Scenario/Send/ResponseLostTest.php`, `tests/Scenario/Send/EndToEndHttp2Test.php` (mock counts). |
| Alpha gate: independent fixtures and per-platform limits | DONE | `tests/Fixtures/apns/*` (Apple examples), `tests/Fixtures/fcm/*`; `AppleFixturesTest.php`, `ByteLimitTest.php`, `PayloadLimitScenarioTest.php`. |
| Alpha gate: test on controlled devices, distinct from `validate_only` | BLOCKED | Needs physical devices and the owner's credentials. (integration-todo: the owner reported a production test; nothing of it is in the repository or public docs.) |
| M5: ledger/outbox/relay, queues, retry budget, limits, breakers, devices, quarantine/invalidation, eligibility, confidentiality | DONE | See §4.3-§4.5, §6.6, §8 rows. |
| M5: tests on long-lived runtimes | DONE | `tests/Scenario/Runtime/LongLivedWorkerTest.php`, `ForkSafetyTest.php`, `tests/Scenario/Laravel/Runtime/*`; Octane RoadRunner/Swoole/OpenSwoole/FrankenPHP and PHP-FPM jobs in `tests.yml` / `backends.yml`. |
| Beta gate: crash before dispatch, mid-chunk, after the response but before saving | DONE | `tests/Scenario/Laravel/Queue/DurableCrashTest.php` (all three), `DurableOutboxTest.php`. |
| Beta gate: two tenants on the same worker | DONE | `TenantBindingStoriesTest.php`, `tests/Scenario/Laravel/Runtime/ManyTenantsRuntimeTest.php`. |
| Beta gate: credential rotation | DONE | `CredentialRotationTest.php`. |
| Beta gate: quota shared between two aliases | DONE | `SharedRateBudgetTest.php`. |
| Beta gate: `forget` with pending jobs | DONE | `ForgetAndExportTest.php`, `ConfidentialityCanariesTest.php`. |
| Beta gate: same key with a different payload refused | DONE | `IdempotencyConflictException` in `tests/Scenario/Ledger/LedgerContract.php`, `DurableOutboxTest.php`. |
| M6: typed tokens, `start/update/end`, channels and broadcast | DONE | See §6.5 rows. |
| M6: versioned client/server documentation | DONE | `docs/live-activities.md` (iOS versions stated, Swift sketch), `src/Laravel/Receipts/CLIENT_CONTRACT.md`. |
| RC1 gate (emulated): two simultaneous updates, rotated token, `end` followed by an old job | DONE | `ConcurrentWorkersTest.php`, `TokenRotationTest.php`, `DeliveryStoryTest.php`. |
| RC1 gate: iOS test app and isolated channel: create → update → end → delete | BLOCKED | Needs an iPhone, the owner's APNs key with broadcast, an Apple developer account. |
| M7 observability, health, credentials | DONE | See §8 rows. |
| M8 campaigns, cap, badge, fallback, receipts | DONE | See §8 rows. |
| RC2 gate: each module passes its case of the release table (§11) | DONE | See §11 release table rows. |
| M9 reproducible benchmarks | DONE | `benchmarks/run.php`, `run-all.sh`, `results/*.json`, `RESULTS.md` (measured at commit `d35d0d2` on a loaded shared machine, stated in `RESULTS.md`). |
| M9 README and complete documentation | DONE | `README.md`, `docs/*.md` (see §11 documentation rows). |
| 1.0 gate: every gate passed, no module in preview | DONE, DIFFERENTLY | Code at 1.0 on `main` (pre-release notice removed by the owner's decision, integration-todo "Release 1.0.0"), with the real-device gates above still BLOCKED; the owner chose to release without them. |
| 1.0 release: tag `v1.0.0` | BLOCKED | The session's GitHub proxy refuses tag pushes (integration-todo). |
| 1.0 release: public repository | BLOCKED | Repository settings write refused (owner). |
| 1.0 release: Packagist | BLOCKED | Package not registered (404); needs the owner's Packagist account and a public repository. |
| M10: docmd site in `docs-site/` modelled on laravel-flow | DONE | `docs-site/docmd.config.json`, `package.json`, `scripts/*`. |
| M10: `npm run build` builds without errors | DONE | `.github/workflows/docs-site.yml` (`npm run build`, `npm run verify`). |
| M10: pages generated from `README.md` and `docs/*.md`, a check fails when out of sync | DONE | `docs-site/scripts/sync-docs.mjs --check --strict` in `docs-site.yml`; `npm run check`. |
| M10: package banner at the top of README and site; PNG + SVG source in the repository | DONE | `resources/laravel-mobile-push-banner.png`, `art/banner.svg`, `art/render.mjs`; README line 6, `docs-site/docs/index.md`. |
| M10: published on Cloudflare Pages at `doc.laravel-mobile-push.padosoft.com` | BLOCKED | No Cloudflare credential in the session; DNS not configured (owner). Settings in `docs-site/README.md`. |

## §11 Quality, testing and documentation

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Pest + Testbench | DONE | `composer.json` require-dev (`pestphp/pest`, `orchestra/testbench`). |
| PHPStan max with Larastan | DONE | `phpstan.neon.dist` (`level: max`, Larastan extension), `phpstan.php82.neon.dist`; `tests.yml` job `static`. |
| Pint | DONE | `pint.json`; `tests.yml` `static` (`pint --test`). |
| Mutation testing on Auth, Outcome, Retry, Ledger, Truncator, compiler and parser | DONE | `.github/workflows/mutation.yml` groups `auth`, `outcome`, `retry`, `ledger-rules`, `ledger-core`, `ledger-database`, `message-support` (Truncator), `apns-compile`, `fcm-compile`, `transport-parsers` (+ others), each with `--min`; weekly cron. Scores in integration-todo. |
| HTTP/2 mock server (Node `http2`, zero dependencies) distinguishing processed request from delivered response; drives GOAWAY, reset, delays; counts external requests | DONE | `tests/Support/H2Mock/server.mjs` (Node built-ins only; actions `respond`, `drop`, `refuse`, `goaway`, `hang`, `close-mid-body`; `/stats`), `MockServer.php`. |
| The number of requests is read from the mock, not from the sender | DONE | `$server->stats()` in `EndToEndHttp2Test.php`, `RetriesWithCacheDownTest.php`, `OctaneSandboxTest.php`. |
| `PushFake` checks intent, `FakeTransport` bytes; the first is not an oracle for the second | DONE | `Testing/PushFake.php`, `FakeTransportBuilder.php`; `docs/testing.md`; `IntentFakeTest.php`, `WireTest.php`. |
| Migrations: upgrade tests | DONE | `tests/Scenario/Laravel/Install/UpgradeMigrationsTest.php` (run on server engines in `databases` jobs). |
| Migrations: every combination with modules off | DONE | `tests/Scenario/Laravel/Install/ModuleCombinationsTest.php` (64 applications). |
| CI: PHP 8.3-8.5 × Laravel 12-13 × lowest/stable | DONE | `tests.yml` matrix (plus PHP 8.2 with Laravel 12, owner's request, integration-todo "Release 1.0.0"). |
| Untrusted PRs use only mocks and ephemeral keys | DONE | `tests.yml` on `pull_request` references no secret (only `live-probes.yml` uses `secrets.*`); keys generated with `openssl_pkey_new()` in tests. |
| Live probes separate, on a protected ref, with dedicated project/app and allowed destinations, zero secrets in logs (workflow) | DONE | `.github/workflows/live-probes.yml` (manual, `refs/heads/main` only, environment `live-probes`, confirmation input, output scanned for secrets). |
| Live probes actually run; environment `live-probes` protected with a reviewer | BLOCKED | Never run: needs the owner's credentials as environment secrets and the environment protection in repository settings. |
| Benchmarks in `benchmarks/`: same payloads, batch, concurrency, versions and hardware declared; warm/cold separate; throughput, p95/p99, memory, errors, connections | DONE | `benchmarks/results/*.json` (`env` block with CPU/PHP/libcurl/commit; `transport-warm-*` vs `transport-cold-*`; `rps`, `latency_ms.p95/p99`, memory, `transport_errors`, `fds`, `server.connections_opened`). |
| No superiority claim without these numbers | DONE | `docs/comparison.md` ("makes no performance claim and quotes no number"); README "Performance" links `benchmarks/RESULTS.md`. |
| Release table: mixed batch with two definitive outcomes and one uncertain: one current outcome per destination, coherent counters, no exception erases other results | DONE | `tests/Scenario/Send/MixedBatchTest.php`, `tests/Scenario/Transport/MixedBatchTest.php`. |
| Release table: concurrent retries with the cache unavailable: requests counted by the mock, one definitive transition per effect | DONE | `tests/Scenario/Laravel/Release/RetriesWithCacheDownTest.php`. |
| Release table: registry and consent change during a backlog: scopes do not cross, revoked targets do not go, late results do not invalidate current registrations, order queued for A with phone moved to B: nothing reaches B | DONE | `DurableRelayTest.php` ("the phone changed hands while queued ... zero requests"), `DeviceStoriesTest.php`, `DeviceOutcomeApplierTest.php`, `TenantsTest.php`. |
| Release table: large campaign with workers stopped: invariant on unique destinations checked against the ledger at different checkpoints | DONE | `tests/Scenario/Laravel/Campaigns/WorkersKilledTest.php` (`invariant` closure checked during the drain), `BlackFridayTest.php`. |
| Release table: load on many tenants and runtimes, hardware/dataset/thresholds fixed beforehand; RSS, file descriptors and connections after warm-up and after drain | DONE | `ManyTenantsRuntimeTest.php`, `QueueWorkerRuntimeTest.php`, `tests/Scenario/Runtime/RuntimeProbe.php` (RSS, `/proc/self/fd`), runtime jobs in CI. |
| Release table: confidentiality canaries on accepted, failed and retried sends absent from log, Pulse, Telescope, OTel; after `forget` the old job neither recreates the record nor contacts the provider | DONE | `ConfidentialityCanariesTest.php`, `ForgetAndExportTest.php`, `tests/Scenario/Privacy/CanaryMarkersScenarioTest.php`. |
| Release table: safe mode: a sandbox command with production configuration opens no connection | DONE | `SafeModeScenarioTest.php` ("Sandbox command, production configuration: blocked before any connection", guard decision), `SendTest.php` ("opens no connection": fake transport count 0). The oracle is the fake transport's count, not sockets counted by the mock server. |
| README and `docs/` describe every possibility and every configuration | DONE | `docs/configuration.md` (generated, every key), 31 public pages under `docs/` (PLAN excluded), `docs/commands.md` (38 commands). Completeness of prose against every feature was not verified exhaustively by this audit. |
| `docs/configuration.md` generated from `ConfigSchema` (key, env, default, allowed values); CI fails if stale | DONE | `Integration/ConfigSchema.php`, `ConfigReference.php`; `tests/Scenario/Laravel/Integration/ConfigReferenceTest.php` (`isCurrent()`, part of the `tests.yml` suite). |
| README and docs examples extracted and run in a clean app (`DocExamplesTest`) | DONE | `tests/Scenario/Laravel/Docs/DocExamplesTest.php` runs every `<!-- example: -->` block (84 markers: 9 in README, 75 in 22 docs pages) in a Testbench application with the fake. |
| README: why choose it (comparisons anchored to commits and benchmarks) | DONE | README "Why this package", "How it compares"; `docs/comparison.md` (tag, commit, file per library). |
| README: features | DONE | README "Features at a glance". |
| README: junior-proof step-by-step setup (`.p8`, Firebase service account, `composer require`, `install`, `.env`, `doctor`, simulated first send, then real) | DONE | README "Quick start" steps 1-9; `docs/getting-started.md`. |
| README: light vs durable with declared guarantees | DONE | README "Queueing"; `docs/delivery-profiles.md`. |
| README: Live Activities guide, server and app | DONE | README "Live Activities in five lines"; `docs/live-activities.md` (Swift not compiled, stated). |
| README: capability matrix | DONE | README features table links `docs/messages.md#the-capability-matrix`. |
| README: testing with the fakes | DONE | README "Testing"; `docs/testing.md`. |
| README: troubleshooting by error reason | DONE | `docs/troubleshooting.md` (tables per raw reason and reason code). |
| README: CLI exit codes | DONE | README "Commands" → `docs/commands.md` ("Exit codes are part of the public contract"). |
| README: migration guides from pushok and the apn channel | DONE | `docs/migrating-from-pushok.md`, `docs/migrating-from-laravel-notification-channels-apn.md`. |

## §12 Execution mode

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Parallel sub-agents, model chosen by difficulty (Opus / Sonnet / Haiku table) | DONE | Process: `CLAUDE.md`, the task ids in the `progress.md` log. Not verifiable from the code; not verified by this audit beyond those files. |
| M0 executed sequentially by the orchestrator: skeleton, dependencies, shared contracts, `docs/contracts/` | DONE | Frozen contracts listed in `AGENTS.md`; `docs/contracts/*`. |
| From M1, sub-agents on disjoint folders; none touches `composer.json`, contracts or other folders | DONE | Rule in `AGENTS.md` "Hard rules"; compliance per wave not verified by this audit. |
| After each wave: integrate, full suite, PHPStan, Pint | DONE | `CLAUDE.md`; `progress.md` log; CI green on `18138b7` (stated, not re-run). |
| Tests: priority to complete cross-layer scenarios; each release-table case becomes a test | DONE | `tests/Scenario/**` (one test per §11 case, see above). |
| Targeted unit tests only where logic is dense: signatures and DER, truncation, byte limits, classifier, retry planner, parser | DONE | `tests/Unit/Core/{Auth,Message,Apns/Outcome,Fcm/Outcome,Retry,Transport}`. |
| No tests that restate the implementation or only check their own mocks | DONE | Rule in `AGENTS.md` "Testing style"; not audited test by test. |
| `progress.md`: status per milestone and task, last wave, next step, verification commands | DONE | `progress.md` (status table, "Prossimo passo", "Verifica rapida", log). |
| `lesson.md`: discoveries and mistakes with date, context and rule | DONE | `lesson.md` (695 lines, dated entries with Contesto/Fatto/Regola). |
| `AGENTS.md` and `CLAUDE.md` with the operating instructions, passed into every sub-agent brief | DONE | Both files present; `CLAUDE.md` asks to paste the sections into every brief. |
| `docs/PLAN.md`: copy of plan v2 | DONE | `docs/PLAN.md` (336 lines). |
| Persistence: a GitHub repository attached to the session | DONE | `origin` = `https://github.com/padosoft/laravel-mobile-push` (private). |

## §13 Points to verify on the sources

| Plan item | Status | Evidence or what is missing |
|---|---|---|
| Apple: exact text of the 5xx advice (15 minutes) | DONE | `lesson.md` "APNs: testo esatto sui 5xx"; quote in `ApnsOutcomeClassifier.php` and `docs/retries-and-limits.md`. |
| Apple: scope of `TooManyProviderTokenUpdates` (per key, team, connection?) | NOT DONE, ATTEMPTABLE | The code and docs state Apple's "at most once every 20 minutes" rule and the reaction, but no entry in `lesson.md` or `docs/` settles what the limit is counted on. |
| ... measured against the real APNs | BLOCKED | Needs the owner's `.p8` key. |
| Apple: connection rules for team keys and topic keys (documentation) | DONE | `docs/credentials-apns.md` (team-scoped vs topic-specific keys, environment restriction, limits). |
| Apple: error returned for a topic outside a topic-specific key | BLOCKED | `docs/credentials-apns.md`: "has not been verified"; needs a real topic-specific key. |
| Apple: which of the two broadcast publish hosts is operational (integration check) | BLOCKED | Stays a `standard`/`api-broadcast` choice (`lesson.md` line 50, README "Project status"); needs the owner's key with broadcast. |
| Apple: strings `BadEnvironmentKeyIdInToken` / `BadEnvironmentKeyInToken` | DONE | Both accepted (`ApnsReasonCatalog` header comment; `lesson.md` 5xx entry: only the first is in Apple's table). |
| Google: cessation date of each IID function (for the migration guide only) | NOT DONE, ATTEMPTABLE | No date is recorded anywhere. No migration guide from an FCM library exists (both guides are APNs libraries), so the point has no consumer today; `lesson.md` notes `firebase.google.com` was unreachable from the session. |
| Google: exact measuring rule of the 4096/2048 bytes (reading Google's documentation) | NOT DONE, ATTEMPTABLE | `FcmPayloadLimits.php` and `tests/Fixtures/fcm/payload-size-rule.json` say "UNVERIFIED against Google"; a conservative own rule is applied and stated in README "Project status". Reading Google's pages needs `firebase.google.com` reachable from the session. |
| ... measured against the real FCM | BLOCKED | Needs a Firebase service account. |
| Google: shape of `Retry-After` | DONE | `lesson.md` (firebase-admin-python: delta-seconds or HTTP-date on 413/429/503); `Retry/RetryAfter.php` parses both. Verified on SDK sources, not on Google's pages (stated in `lesson.md`). |
| curl/PHP: what the binding exposes on `REFUSED_STREAM` and `GOAWAY` | DONE | `lesson.md` (entries of 2026-11-20), `docs/transport.md`, `StreamRefusalTest.php`. |
| curl/PHP: default of `CURLOPT_MAXAGE_CONN` | DONE | `docs/transport.md` (118 s, `url.c`, measured), `lesson.md` "MAXAGE_CONN". |
| curl/PHP: libcurl internal automatic retries | DONE | `lesson.md` (`Curl_retry_request()`, `REQUEST_SIZE` cumulative), `docs/transport.md`, `LibcurlBehaviourTest.php`. |
| curl/PHP: behaviour with Swoole | DONE | `lesson.md` "Swoole con hook di coroutine"; `backends.yml` `swoole-coroutine-hooks` and `octane` jobs; integration-todo deviation (unsupported with coroutine hooks). |
| curl/PHP: mTLS blob support | DONE | `lesson.md` (`CURLOPT_SSLCERT_BLOB` available), `CurlCapabilities`, `tests/Scenario/Transport/MutualTlsTest.php`. |
| curl/PHP: decoding of Apple certificate topic/environment extensions on a real certificate | BLOCKED | `Credentials/ApnsCertificateInspector.php` tested on generated certificates only (README "Project status"); needs a real Apple push certificate. |
| Laravel: Pulse API on Laravel 13 | DONE | `lesson.md` "Pulse e Telescope: contratti reali" (Pulse 1.11); the Pulse tests run in the Laravel 13 matrix of `tests.yml`. |
| Laravel: custom entries in Telescope | DONE | Same `lesson.md` entry; `Telescope/PushWatcher.php`; `FailedPushScenariosTest.php`. |
| Laravel: stability of the Notification UUID across retries | DONE | `lesson.md` (id assigned once per notifiable and serialised in `SendQueuedNotifications`); `QueueOwnershipTest.php` ("a retry reuses the same effect and apns-id"). |

## Not done, attemptable

Every row above with this status, with the smallest work that would close it.

1. **§4.5 limits per topic/device "when signalled" (FCM).** `src/Core/Fcm/Outcome/FcmReasonCatalog.php` maps every
   `QUOTA_EXCEEDED` to a project-wide throttle. Smallest work: either read the `QuotaFailure` / FCM error detail and
   defer only the destination (device) or the topic, as APNs `TooManyRequests` already does, with a unit test in
   `tests/Unit/Core/Fcm/Outcome/FcmOutcomeClassifierTest.php`; or record "FCM quota is always paused per project" as a
   decided deviation in `docs/internal/integration-todo.md` and in `docs/retries-and-limits.md`.
2. **§6.2 Workload Identity Federation (emulated).** No test exercises an `external_account` credential through
   `GoogleAuthLibraryProvider`. Smallest work: a scenario next to `tests/Scenario/Laravel/Sending/AdcOnGoogleCloudTest.php`
   with an `external_account` JSON (file-sourced subject token) and `google/auth`'s HTTP handler answering the STS
   exchange locally, asserting the FCM request carries the exchanged bearer.
3. **§6.5 fixture shared with the iOS test app (emulated part).** No Swift code exists. Smallest work: a small Xcode
   test target (attributes and `ContentState` structs) run by `simulator.yml` on the macOS runner, decoding
   `tests/Fixtures/apns/la-*.json` with `JSONDecoder`; whether `simctl push` can also start a Live Activity on the
   simulator was not verified by this audit.
4. **§8 metric "queue age" for the light profile.** Only `mobile_push_ledger_oldest_due_age_seconds` (durable) exists.
   Smallest work: state in `docs/observability.md` that queue age is measured only from the ledger (the light profile's
   jobs live in the Laravel queue backend), or add a gauge from the job's creation time recorded in `SendPushJob` when it
   starts.
5. **§13 scope of `TooManyProviderTokenUpdates`.** Smallest work: read Apple's "Establishing a token-based connection"
   page (Apple's JSON pages were reachable, `lesson.md` line 49), record what the limit is counted on in `lesson.md` and
   `docs/credentials-apns.md`, or write that Apple does not say.
6. **§13 IID cessation dates.** Smallest work: record the dates in `lesson.md` when `firebase.google.com` is reachable,
   or mark the point moot in `docs/internal/integration-todo.md` (no FCM migration guide exists).
7. **§13 FCM 4096/2048 measuring rule (documentation reading).** Smallest work: read Google's FCM message-size pages
   (needs `firebase.google.com` reachable from the session) and either confirm `tests/Fixtures/fcm/payload-size-rule.json`
   or adjust `FcmPayloadLimits`.

## Blocked

Grouped by what blocks them.

**The owner's APNs key (`.p8`), Apple developer account and/or a physical device**
- §4.4 environment probe against the real APNs.
- §4.5 `ExpiredProviderToken` refresh against the real APNs.
- §6.5 shared fixture on a real device; broadcast channel API against the real APNs.
- §9 / §11 `doctor --live` and the `live-probes` workflow (APNs half).
- §10 alpha gate "test on controlled devices"; RC1 gate "iOS test app and isolated channel: create → update → end → delete".
- §13 `TooManyProviderTokenUpdates` measured; error for a topic outside a topic-specific key; operational broadcast host;
  decoding of a real Apple push certificate.

**The owner's Firebase service account / Firebase project**
- §6.4 topic subscriptions against the real FCM.
- §9 / §11 `doctor --live` (FCM half).
- §13 FCM size rule measured against the real FCM.

**An AWS or Google Cloud account**
- §6.2 a real KMS/HSM signer (AWS); ADC and Workload Identity Federation on Google Cloud itself (GCP).
- (Not a plan row, listed in integration-todo: Vapor/Lambda, real SQS and DynamoDB.)

**Repository settings, tags and the owner's external accounts**
- §10 tag `v1.0.0` (proxy refuses tag pushes).
- §10 public repository (settings write refused).
- §10 Packagist registration (404, needs the owner's Packagist account and a public repository).
- §10 M10 Cloudflare Pages publication and DNS of `doc.laravel-mobile-push.padosoft.com`.
- §11 protection of the `live-probes` environment with a reviewer (repository settings) and its secrets.

## Undocumented deviations (findings)

Implemented differently from the plan and written down nowhere as a deviation:

1. §7 `MobilePush::assertSent(...)` as a static facade call does not exist; assertions are on the `PushFake` returned
   by `MobilePush::fake()` (`src/Laravel/Facades/MobilePush.php`, accessor `PushManager`).
2. §4.3 `queueMany()` does not exist; many targets go through `to($users)->queue()`.
3. §4.6 config key `broadcast_publish_host` is `connections.*.broadcast_host`.
4. §4.5 config key `rate.fcm_per_minute` is `guards.rate.fcm`.
5. §6.6 `SendChunkJob` is `SendPushJob`.
6. §4.5 the citation of Apple's 15-minute advice is in code and docs, not in a test fixture.
7. §5 class names and layout: `CurlPool`/`CurlPoolRegistry`/`ResponseHeaderParser` for
   `ConnectionPool`/`PoolRegistry`/`HeaderParser`; no `Lane`, `RecordingTransport`, `RefreshPolicy`, `AppBinding`,
   `EligibilityGuard` (Core), `PayloadLimits` (shared), `Expiry`, `SupportsLiveActivities`/`ManagesChannels`/
   `ManagesTopicSubscriptions`, `CertificateAuth`, `BroadcastPublisher`; `ApnsOverride` is `FcmApnsConfig`;
   `ConfigSchema` in `Laravel/Integration`; no `Laravel/Jobs` and `Laravel/Events` directories; migrations are `.php`,
   not `.stub`.

Deviations written down only outside the "decided deviations" list (in contracts or public docs): the extra `Failed`
status (`docs/contracts/outcomes.md`), the two-level ledger uniqueness (`docs/contracts/ledger.md`), `send()` never
writing the ledger (`docs/contracts/ledger.md`), no quota breaker class and guards in `Core/Guard`
(`docs/contracts/retry.md`), token cache encrypted with `TokenCipher` instead of Laravel's Encrypter
(`docs/security-and-privacy.md`), the capability matrix as a reference enforced by the compilers (`docs/messages.md`),
modules on by default (metrics, badge, fallback, Telescope `auto`; README "Features at a glance").

## Not classified

None. Every commitment of §4-§13 has a row. Rows that are processes rather than code (§12) are classified from the files
that record them and say so.
