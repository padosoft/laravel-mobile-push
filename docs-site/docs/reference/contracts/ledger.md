---
title: "Ledger (delivery records)"
description: "Defines what the two delivery profiles guarantee, what the durable ledger records, how duplicates are detected, and how outcomes are recorded around the network call."
---

# Ledger (delivery records)

**Purpose.** Defines what the two delivery profiles guarantee, what the durable ledger records, how duplicates are detected, and how outcomes are recorded around the network call. Source: `docs/PLAN.md` §4.3 and §4.1, and the code in `src/Core/Ledger` (the contracts and the in-memory ledger) and `src/Laravel/Ledger` (the database ledger). The user-facing guide is `docs/delivery-profiles.md`.

## Rules

1. **Light profile (default).** No tables. Deduplication is in-process only. It is documented as **best effort**, including for `queueMany()`: after a crash, duplicates or omissions are possible.
2. **Durable profile** (`delivery.profile = durable`). Records effects and deliveries in the database before any dispatch. Recommended for production. Receipts and campaigns require it (plan §8).
3. **Effect** (`mobile_push_effects`, one per `queue()` call): the ID is generated before dispatch, plus scope, binding, `effect_key`, payload fingerprint, an encrypted snapshot of the message, type, replaceability, logical expiry and `policy`.
4. **Delivery** (`mobile_push_deliveries`, one per logical destination): status, attempts, `next_attempt_at`, lease (owner and expiry), version, provider ID, `reason_code`, `raw_reason`, timestamps.
5. **Uniqueness**: an effect is unique per `(scope, binding identity, hash of the effect key)` (`mp_effects_key_unique`) and a delivery per `(effect, logical destination)` (`mp_deliveries_destination_unique`). Reusing a key **within the deduplication window** with the same message is an idempotent replay (the existing effect is returned and missing destinations are added); with a different payload fingerprint it throws `IdempotencyConflictException` and writes nothing.
6. **`effect_key` default**: the id of the Laravel notification when the push goes through the Notification channel (`EffectKeys::forPush`), the key given with `->effectKey()`, or a fresh ULID when none is given (which means no deduplication across calls). Keys also cover topics, conditions and channels. **The deduplication window** is `ledger.dedupe_seconds` (`MOBILE_PUSH_LEDGER_DEDUPE_SECONDS`, default 86400 = 24 h; an effect may override it with `EffectIntent::$dedupeSeconds`; a campaign uses its retention). After the window the key is free again: `recordIntent()` creates a **new** effect, reports the earlier one in `IntentResult::$previousEffectId`, and leaves the earlier one untouched. Deduplication is a window, not forever.
7. **Outbox.** If the call happens inside a transaction on the same database connection as the ledger (`ledger.connection`), the intent is committed together with the business data (`DatabaseLedger` writes with plain inserts inside whatever transaction is open there). A recoverable relay (`Queue\RelayDeliveriesJob` over `Queue\DeliveryRelay`, plus the scheduled `Queue\SweepLedgerJob` over `LedgerSweeper`) picks it up later.
8. The worker claims due destinations under a lease (`ledger.lease_seconds`, default 120 s, which must exceed the chunk deadline), marks the attempt as started right before the request leaves (one `markAttemptsStarted()` per transport window, at the moment the window is handed to the transport; see "Worker writes in batches"), and **records the outcome (`record()`) before acknowledging the job.** A write from a lost lease is ignored.
9. **No SQL transaction is open during the network call.**
10. A lease that expires after the send started (state `Sending`) does **not** prove `NotSent`. Apply `outcomes.md`: `reclaimExpiredLeases()` ends the row as `outcome_unknown` (reason `TransportUnknown`) unless the unknown-outcome policy allows another attempt (replaceable messages by default), in which case it is planned again. A lease that expires before the attempt started (state `Leased`) simply returns the row to the queue.

## Profiles

| Profile | Stores | Dedup | Guarantee | Does NOT guarantee |
|---|---|---|---|---|
| Light (default) | Nothing | In-process | Best effort | No crash safety; duplicates or omissions possible, including `queueMany()` |
| Durable | Effects, deliveries, outbox | Unique key in the database | Each destination's outcome is recorded before ack; intent committed with business data when inside a transaction | Does not make the provider's processing exactly-once; an unknown outcome stays unknown |

**Only `queue()` uses the ledger** (decision, W4-I). `PendingSend::send()` is the synchronous path in both profiles: it sends now, returns the report, and writes no effect, no delivery and no outbox row; it never carries a receipt marker (a marker is issued only for a destination that has a ledger delivery id). Use `queue()` when the push must survive a crash, be deduplicated by its effect key across processes, or report client receipts. No `durably()` option exists: a durable synchronous send would need the relay's lease protocol in the caller's process, which is what `queue()` on the `sync` queue connection already gives.

**Payload fingerprint** (decision, W4-I). The effect row stores `v2:k<key version>:<hex>`: HMAC-SHA256 of the canonical message, keyed with a key derived (HKDF, own label) from the encryption key ring (`TokenCipher::digest()`) and bound to the scope, so a short message (an OTP) cannot be confirmed offline from a database dump. Same message, same scope, same key version = same value. Rows written before this change hold the unkeyed `v1:<sha256>` and still match. A keyed value is verified with the key version it names; once that version is removed from the ring the value cannot be verified and a replay of that effect key is a conflict.

## Deduplication window (decision, W5-P)

- An effect key is deduplicated for `ledger.dedupe_seconds` (default 86,400 s; campaigns use `campaigns.retention_days`) from the moment the effect is created. Within the window a replay with the same message returns the same effect (`IntentResult::$created === false`); another message throws `IdempotencyConflictException`.
- Once the window has passed the key is released and the next call with that key creates a **new** effect. This is deliberate (keys must be reusable eventually, e.g. a daily topic key), and it is explicit: the result then has `created === true` **and** `previousEffectId` = the id of the effect that held the key (`IntentResult::reusedExpiredKey()` is true). A first use of a key has `previousEffectId === null`. The earlier effect is not touched: its deliveries keep going.
- Retention and window are coherent by construction: `prune()` deletes a delivery only when it is final, older than the retention **and** its effect is past its window, and an effect only when it is past its window and has no delivery left. So an effect is never pruned while its key is still deduplicated, whatever the two settings; a retention shorter than the window simply waits for the window.
- A campaign planner that finds its key re-owned by another effect (its window ended while it was paused) cancels that new effect and fails the campaign with an explicit error; it never plans into a second effect.

## Logical destination key (decision, W5-P)

- `(effect, logical key)` is unique. For a raw destination the key is the HMAC index of the value; for a registry device it is `dev:<kind>:<device id>` — **without** the device generation.
- The generation planned is stored in `device_generation` (with the owner) and compared with the registry right before the network: a phone re-registered after the delivery was written (new owner, logout/login, reactivation) is `Skipped` (`generation_changed` / `owner_changed`), so a message planned for the previous holder never reaches the new one ("phone changed hands"). The new holder does not get a second row in the same effect: a crashed campaign slice that runs again after the re-registration writes nothing new, and `planned` counts each device once.
- Rows written before this change carry `dev:<kind>:<id>:<generation>`. A replay of an existing effect also checks that form (one indexed query per chunk), so an intent replayed across the upgrade does not add a second row for the same device and generation. No data migration is needed.

## Worker writes in batches (W5-P)

`DeliveryBatches` (implemented by both ledgers) is the set-based form of the worker protocol: `markAttemptsStarted(list<Lease>)` marks a whole Sender window started in one statement and returns the ids that may be sent; `recordMany()` records a window's outcomes with one read by primary key and the guarded UPDATEs in one short transaction, between two windows (never during a network call). The guards and transitions are exactly those of `markAttemptStarted()` / `record()`. The relay writes a window's outcomes right before it hands over the next window (or when the report ends): a worker that dies in between loses at most the outcomes of the window it just finished, which the lease-expiry rule then ends as `OutcomeUnknown`, as for a worker that died one statement earlier.

## Indexes and query shape (W5-P)

Every hot query reads only the rows it returns, however many final rows the table holds (asserted on SQLite query plans by `tests/Scenario/Laravel/Ledger/ScalingQueryPlansTest.php`):

| Query | Index |
|---|---|
| claim of a scope / of one effect: one query per claimable state, in `(next_attempt_at, id)` order, stopped at the limit | `mp_deliveries_claim_idx (scope, state, next_attempt_at, id)`, `mp_deliveries_effect_claim_idx (effect_id, scope, state, next_attempt_at, id)` |
| claim, start, record, read-back: by primary key only, guarded by `(id, version[, lease_owner])` — every state change bumps the version except leased → sending | primary key |
| `scopesWithWork()`, scope-less `health()` | `mp_deliveries_work_idx (state, next_attempt_at, scope)` |
| `SettlementProbe::hasOpenDeliveries()` / `campaignHasOpenDeliveries()` (existence of a pending, deferred, leased or sending row) | `mp_deliveries_effect_claim_idx`, `mp_deliveries_campaign_state_idx` |
| campaign device audience (keyset) | `mp_devices_audience_idx (scope, binding_identity, state, id)` |

Migrations: `2026_11_21_000013_add_mobile_push_ledger_scaling_indexes.php` (adds the three ledger indexes, drops `mp_deliveries_due_idx` and `mp_deliveries_effect_state_idx`, which are prefixes of the new ones) and `2026_11_21_000002_add_mobile_push_devices_audience_index.php`. Both are idempotent and published with their module's tag. The plans are verified on SQLite only; MySQL and PostgreSQL choose their own plans (the shape — equality prefix plus ordered range, primary-key IN lists without extra indexed conditions — is the one their planners serve with the same indexes, but this is not yet measured there).

## Delivery states and the write order

Stored states (`Ledger\DeliveryState`): non-terminal `pending`, `deferred`, `leased` (a worker holds it, no request has left), `sending` (the request is about to leave: from here an expired lease means the outcome is unknown); terminal `accepted`, `rejected`, `failed`, `outcome_unknown`, `expired`, `skipped`, `simulated`, `validated`, plus `cancelled` (a tombstone written by cancel and forget). A row still waiting or in flight reads as `Deferred` in the public `SendStatus`; a cancelled row reads as `Skipped` with `ReasonCode::Cancelled`. A terminal row is never overwritten (`DeliveryStateMachine`).

| Step | Written | Where |
|---|---|---|
| Call | Effect row with its id, fingerprint and encrypted snapshot (`MessageVault`); one delivery row per destination, each with a sender-generated UUIDv4 `message_id` | `EffectStore::recordIntent()` |
| Claim | Lease with owner and expiry, state `leased` | `DeliveryStore::claimDue()` (`SKIP LOCKED` on MySQL/PostgreSQL, optimistic on SQLite, `ledger.claim_strategy`) |
| Right before the network | State `sending` (the point after which an expired lease is `outcome_unknown`) | `markAttemptStarted()` |
| After response or proven failure | State, `reason_code`, `raw_reason`, provider id, retry budget, timestamps | `record()` |
| Before ack | The above, committed | the relay records before the job is acknowledged |

All times are UTC epoch milliseconds in `BIGINT` columns. No clear token is ever stored: destinations are kept encrypted with a keyed index.

## Types

- Core (`src/Core/Ledger`): contracts `Ledger` (= `EffectStore` + `DeliveryStore`), `ClientReceiptStore`; `InMemoryLedger` (the reference implementation the shared contract tests run against); `LedgerRules` and `DeliveryStateMachine` (the row rules both implementations share), `LedgerSettings`, `PayloadFingerprint`, `MessageVault`, `Effect`, `Delivery`, `EffectIntent`, `IntentResult`, `Lease`, `ClaimedDelivery`, `Recording`, `ReclaimReport`, `DeliveryCounts`, `LedgerHealth`, `Ulid`, `MessageId`.
- Laravel (`src/Laravel/Ledger`): `DatabaseLedger` (query builder, two claim strategies), `LedgerModule`, `ClaimStrategy`; queue side in `src/Laravel/Queue`: `DurableQueuedSender`, `DeliveryRelay`, `RelayDeliveriesJob`, `LedgerSweeper`, `SweepLedgerJob`. The ledger is `Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger` bound under `Ledger`, `EffectStore`, `DeliveryStore` and `ClientReceiptStore`.
- Migrations (`database/migrations`): effects, deliveries, the receipt columns (`message_id`, `receipt_marker`) and the scaling indexes; see `docs/delivery-profiles.md#migrations` for how they are published.
- Exceptions: `Exceptions\IdempotencyConflictException` (extends `ValidationException`), `Identity\BindingRef`, `Outcome\SendStatus`.

## Worked cases

**A. Crash inside a transaction.**
- Situation: the app writes an order and calls `send` inside a database transaction on the ledger's connection. The transaction rolls back.
- The package: the intent was written with the business data, so it is rolled back too.
- The caller sees: no send is recorded and no delivery is attempted. (Plan §4.3 says the intent is committed with the business data; the rollback case follows from that.)

**B. Worker dies after the request was written.**
- Situation: the worker sent the request, then crashed before recording the response. The lease expires.
- The package: the relay re-reads the unfinished delivery. Because the send had started, the expired lease proves nothing about `NotSent`.
- The caller sees: the destination ends as `OutcomeUnknown`; a replaceable message may be retried by policy, an alert is not.

**C. Same key, different payload.**
- Situation: a retried call reuses `effect_key` inside the deduplication window but the payload differs.
- The package: fingerprint mismatch (the fingerprint is an HMAC keyed from the encryption key ring and bound to the scope).
- The caller sees: `IdempotencyConflictException`; the first effect is untouched.

**D. Light profile after a crash.**
- Situation: the process crashes midway through `queueMany()`.
- The package: no records exist, so the process cannot know what was sent.
- The caller sees: possible duplicates or omissions, as the light profile declares.

## Plan vs code

- The plan placed `Ledger/` in `Core` with `EffectStore` and `DeliveryStore` as contracts and the Laravel ledger in `src/Laravel`; the code does exactly that.
- Column list and types are the migrations in `database/migrations` (the plan lists fields only). Delivery statuses in storage are `DeliveryState` and map to `SendStatus` as described above.
- `DeliveryStore` is connected to `SendReport` through `results()`, a generator of public `SendResult`s read from the ledger, and the Laravel relay records each outcome with `record()`.
- The synchronous `send()` writes no ledger row in either profile (decision W4-I, see the profiles section).

## Open points

- **MySQL and PostgreSQL under load are not proven.** The shared contract tests run on the in-memory ledger and on the database ledger in both claim strategies, on SQLite and, in CI, on MySQL 8.4 and PostgreSQL 17; `ConcurrentClaimTest` runs one worker's claim inside the other worker's `FOR UPDATE SKIP LOCKED` transaction on two real connections and every delivery is claimed exactly once. On MySQL/MariaDB the claim transaction runs at READ COMMITTED (at REPEATABLE READ the gap locks of the locking read made the other claimer's update wait). Many worker processes at production volume and MariaDB have not been run.
- The behaviour with a very high write latency, or read replicas for the ledger connection, has not been measured.
- The cost of the fingerprint on very large messages has not been measured.

---

*This page is generated from [`docs/contracts/ledger.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/ledger.md). To change it, edit that file in the repository, not this site.*
