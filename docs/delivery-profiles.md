# Delivery profiles: light and durable

The package has two delivery profiles. They differ in **what is written down before and after a push leaves**, and
therefore in what can be lost or duplicated when something crashes. Neither profile is "exactly once": no system that
talks to APNs and FCM over a network can promise that. This page says what each one guarantees, lists the windows in
which a duplicate or an omission is **still possible**, and explains how to pick and switch.

Related pages: [outcomes](outcomes.md), [retries and limits](retries-and-limits.md),
[queues and Horizon](queues-and-horizon.md), [receipts](receipts.md), [campaigns](campaigns.md),
[sending](sending.md), [commands](commands.md).

## At a glance

| | **Light** (default) | **Durable** |
|---|---|---|
| Setting | `delivery.profile = light` (`MOBILE_PUSH_DELIVERY_PROFILE`) | `delivery.profile = durable` |
| Tables | None | `mobile_push_effects`, `mobile_push_deliveries` |
| What `queue()` does | Cuts the destinations into small **encrypted queue jobs** | Writes the intent to the database, then queues a **relay job** that reads it back |
| What `send()` does | Sends now, returns the report | **The same** (it never writes the ledger) |
| Survives a worker crash | **No**: best effort | Mostly: the unfinished work is found again (see the windows below) |
| Deduplication | Only inside one `queue()` call (identical destinations merged) | By `effect_key` across calls and processes, within `ledger.dedupe_seconds` |
| Outbox (intent commits with your business data) | No | Yes, when you are inside a transaction on the ledger's database connection |
| Needed by | Nothing | [Receipts](receipts.md), [campaigns](campaigns.md) |
| Recommended for | Getting started, low-value notifications | Production, when a lost or doubled push matters |

> **"Only `queue()` uses the ledger."** `PendingSend::send()` is the synchronous path in **both** profiles: it sends
> now and returns the report; it writes no effect, no delivery and no outbox row and never carries a receipt marker.
> If you want durability, call `queue()` (the `sync` queue connection gives a synchronous run through the same
> protocol).

## Light profile: best effort

### What it stores

Nothing in the database. A queued send becomes one or more `SendPushJob`s on the queue. A job carries the binding
reference (never a secret), the serialized message, the destinations (typed, **with the raw tokens**), the traffic
class, the effect key and the absolute expiry, and for a continuation each destination's retry budget. Because it holds
tokens the job is `ShouldBeEncrypted` (encrypted with `APP_KEY` by Laravel). Jobs are cut by count (`queue.chunk`, 500)
and by estimated bytes (`queue.chunk_bytes`, 98304 before encryption).

A job runs once (`tries = 1`) and never `release()`s itself. Destinations that came back `Deferred` are re-queued as a
**new** job with their budgets, delayed to their next attempt. A job also stops starting new windows at its
[chunk deadline](queues-and-horizon.md#the-chunk-deadline) (`queue.chunk_deadline`): the destinations it has not
attempted yet go, with their budgets, to **one** new job dispatched at once. The window in flight at that moment is
never handed over: its destinations get whatever the transport reports, unknown included.

### What it guarantees

- A destination is attempted at most as often as its retry budget allows, and every attempt follows the rules of
  [retries and limits](retries-and-limits.md) and [safe mode](safe-mode.md).
- The unknown-outcome rule applies: an unknown outcome is never turned into a failure or a fallback.
- Deduplication of identical destinations inside **one** `queue()` call (its memory grows with the number of distinct
  destinations).

### What can be lost or duplicated

| Situation | What happens |
|---|---|
| The worker dies while a job is sending | The job is **not retried** (`tries = 1`): destinations not reached yet are **omitted**. Destinations already sent are not re-sent by the package. |
| The queue redelivers a job whose worker died (for example `retry_after` / the SQS visibility timeout passed while the job was still sending, or a backend that ignores `tries`) | Destinations already sent are sent **again**: duplicates. The [queue timeout chain](queues-and-horizon.md#the-timeout-chain) exists to make this impossible by configuration; `mobile-push:doctor` checks it. |
| A job fails with an exception | `SendPushJob::failed()` records every destination of the job as `OutcomeUnknown` in the send log (when a log sink is enabled; the log carries only masked values) or writes one warning without any destination. It does not retry. |
| The process dies between the database commit and the queue push | The job is lost. There is no recovery in this profile. |
| The queue backend loses jobs (a flushed Redis) | The pushes are lost. |
| Continuation job lost | The deferred destinations, and the ones handed over at the chunk deadline, are lost with it. |
| The same `queue()` call is made twice by your code | Two sends. There is no cross-call deduplication. |
| The binding no longer resolves when the job runs (connection removed or repointed) | Nothing is sent. The job dispatches `BindingMismatchDetected` once, records `Skipped` / `binding_mismatch` with 0 attempts for each destination, passes those results to the log sinks when they are enabled, and ends cleanly (the same as the durable relay and a direct `send()`). |
| A synchronous `send()` crashes mid-batch | Nothing is remembered. Re-running it re-sends what was already sent. |

Use the light profile when an occasional omission or duplicate is acceptable and the extra tables are not wanted.

## Durable profile: the ledger

### What it stores

Two tables (names and connection are configurable, see below):

**`mobile_push_effects`**: one row per `queue()` call (a logical thing to send).

| Column | Holds |
|---|---|
| `id` | A ULID generated **before** anything is dispatched. |
| `scope`, `binding`, `binding_identity` | The tenant scope and the binding the effect was created for (no secret). |
| `effect_key`, `dedupe_key` | Your idempotency key and `sha256` of it (unique per scope + binding identity). |
| `fingerprint` | A keyed HMAC of the message (see below). |
| `message` | The message **snapshot, encrypted** (AES-256-GCM, bound to the scope and effect id). `NULL` after `forget`. |
| `operation`, `replaceable`, `expires_at`, `policy`, `traffic_class` | What it is, whether a later copy replaces it, the logical expiry. |
| `origin_type`, `origin_id`, `notification_class`, `campaign_id` | Who it was for (a notifiable), which notification, which campaign. |
| `dedupe_until` | End of the deduplication window. |

**`mobile_push_deliveries`**: one row per logical destination of an effect.

| Column | Holds |
|---|---|
| `id`, `effect_id`, `scope` | ULID, the effect, the scope. |
| `kind`, `logical_key` | The destination kind and a stable key that makes `(effect, destination)` unique: an HMAC index for a raw destination, `dev:<kind>:<device id>` for a registry device (**no generation in it**). |
| `destination` | The raw destination, **encrypted**; `NULL` for registry devices (the registry stays the only place that holds the token). |
| `device_id`, `device_generation`, `owner_type`, `owner_id` | Registry reference and owner **as planned**; compared with the registry right before the network. |
| `state`, `attempts`, `budget`, `unknown_seen` | State, attempts made, the stored retry budget, the unknown-outcome flag. |
| `next_attempt_at`, `lease_owner`, `lease_expires_at`, `attempt_started_at`, `version`, `cancel_requested` | Scheduling, lease and optimistic-lock version. |
| `message_id`, `provider_message_id`, `receipt_marker` | The id assigned at insert, the provider's id, whether a receipt marker was issued. |
| `reason_code`, `raw_reason`, `hint`, `http_status`, `destination_action`, `invalidated_at_ms`, `accepted_at` | The outcome. |
| `client_received_at`, `client_opened_at` | Filled by the [receipts](receipts.md) module. |

The `(effect_id, logical_key)` pair is unique: the same destination cannot be inserted twice for the same effect, and the
`(scope, binding_identity, dedupe_key)` triple is unique for effects.

### The life of a delivery

States: `pending` → `leased` → `sending` → one terminal state, or `deferred` (waiting for its next attempt) and again.

| State | Meaning |
|---|---|
| `pending` | Inserted, never attempted, due at `next_attempt_at`. |
| `deferred` | A further attempt is planned at `next_attempt_at`. |
| `leased` | A worker holds it under a lease; **no request has left yet**. |
| `sending` | The worker said the request is about to leave. From here an expired lease means the outcome is **unknown**, never "not sent". |
| `accepted`, `rejected`, `failed`, `outcome_unknown`, `expired`, `skipped`, `simulated`, `validated` | Terminal, one per `SendStatus` (except `Deferred`). |
| `cancelled` | Terminal tombstone written by a cancel or by `forget`. Reads back as `Skipped` with reason `cancelled`. |

The protocol, in the order it happens:

1. **`queue()`** writes the effect and one delivery per unique destination. If you are inside a transaction on the
   ledger's database connection, the rows are part of **your** transaction: they commit or roll back with your
   business data. Then one `RelayDeliveriesJob` (ids only, no tokens) is dispatched **after that transaction commits**
   (nothing is dispatched on rollback).
2. A **relay worker** claims due rows (`claimDue`): pending or deferred, due now, effect not expired. A claim is an
   `UPDATE ... WHERE version = ?` that sets a lease (`ledger.lease_seconds`, default 120). Two workers never get the
   same row.
3. For each row the relay re-checks the world: a missing effect or erased snapshot is `Skipped` (`cancelled`); a
   connection that no longer resolves is `Skipped` (`binding_mismatch`); a registry device that is no longer eligible
   is `Skipped` (`policy_ineligible`, raw reason `owner_changed` or `generation_changed` when the phone changed owner or
   was registered again after the delivery was written).
4. **Right before each transport window leaves** the relay marks its attempts started (one statement per window, through
   `Core\Driver\TransmitObserver`, called immediately before the window is handed to the transport); a destination whose
   lease was lost is **not sent**. **No SQL transaction is open while the network call is in flight.**
5. The sender produces the outcome. The relay writes it (`record`) with the final budget **before the job is
   acknowledged**. If the write fails it is retried at the end of the chunk and, if it still fails, the job fails (so it
   is not acknowledged as if the outcome were stored).
6. A `Deferred` result is stored with `next_attempt_at`; **one follow-up relay per effect** is scheduled for the
   earliest one. A cache marker keeps that to a single pending job; losing it is harmless because of the sweeper. When
   many effects of one scope are deferred at once (a provider throttle answered to thousands of small effects), the
   follow-ups are folded, see "Follow-up relays are folded" below.
7. After every relay run the package dispatches `Padosoft\MobilePush\Laravel\Queue\Events\RelayRunFinished`. The
   [campaigns](campaigns.md) module completes a campaign on it, and the integration module announces `EffectSettled`
   for an ordinary effect whose deliveries are all final.

### Follow-up relays are folded

At most **20** precise follow-up relay jobs are queued per scope, queue and time bucket (`queue.follow_up_bucket`
seconds, default 60, aligned to the clock). Every further effect deferred into the same bucket shares **one scope-wide
relay** dispatched for the end of the bucket. A delay is never shorter than the provider asked for and at most one bucket
longer. 10,000 effects deferred to the same second therefore queue 21 relay jobs, not 10,000 (a scenario with the fake
transport; not run on a real queue backend). `queue.follow_up_bucket` = 0 turns the folding off: one job per effect.

### The sweeper

`SweepLedgerJob` is scheduled **every minute** (when the profile is durable and `queue.sweeper.schedule` is true) by the
package; it needs the Laravel scheduler to be running (`php artisan schedule:run` every minute, see
[queues and Horizon](queues-and-horizon.md#the-scheduler)). For each scope with work it:

- applies **lease expiry**: a `leased` row whose worker vanished goes back to `pending` untouched (nothing left); a
  `sending` row means the request may have left, so the row becomes `outcome_unknown`, or is made due again when the
  effect is replaceable, the unknown-outcome policy allows it and attempts and deadline remain;
- ends overdue deliveries as `expired`;
- dispatches a scope-wide relay when deliveries are due. This is also what recovers an intent whose relay job never
  reached the queue (a crash between the commit and the dispatch).

`php artisan mobile-push:sweep` runs one sweep by hand; `php artisan mobile-push:relay` runs the relay inline (for a
cron-driven setup without a queue worker, and for debugging).

### Deduplication window

An effect key is deduplicated for `ledger.dedupe_seconds` (default 86,400 s; an effect may override it, a campaign uses
`campaigns.retention_days`) counted from the moment the effect is created.

- **Inside the window**, a call with the same key and the same message returns the existing effect
  (`IntentResult::$created` is `false`); a different message throws `IdempotencyConflictException`.
- **After the window** the key is free again, on purpose (a daily topic key must be reusable). The next call creates a
  **new effect and pushes again**. This is explicit: the result has `created === true` **and**
  `IntentResult::$previousEffectId` set to the effect that held the key, and `IntentResult::reusedExpiredKey()` returns
  `true`. A first use of a key has `previousEffectId === null`. The earlier effect is untouched and its deliveries keep going.
- **Pruning respects the window.** `mobile-push:deliveries:prune` deletes a delivery only when it is final, older than
  `ledger.retention_days` **and** its effect is past its window; an effect is deleted only when it is past its window
  and has no delivery left. An effect is never pruned while its key is still deduplicated, whatever the two settings; a
  retention shorter than the window simply waits for the window.
- A campaign planner that finds its key taken by another effect (its window ended while it was paused) cancels that new
  effect and fails the campaign with an explicit error; it never plans into a second effect.

### Idempotency keys and the payload fingerprint

The **effect key** names a logical effect: "the shipping notification of order 42". Within the [deduplication window](#deduplication-window)
(`ledger.dedupe_seconds`, default 86400 s = 24 h) the same scope, binding identity and key means the same effect:

- same key, **same message**: idempotent. The existing effect is returned; destinations that are not yet present are
  added; existing rows (including cancelled tombstones) are left alone; no second relay is dispatched unless something was
  added.
- same key, **different message**: `IdempotencyConflictException` (a `ValidationException`). Nothing is written and
  nothing is queued; the first effect is untouched.

How the "same message" decision is made: the effect stores `v2:k<key version>:<hex>`, an HMAC-SHA256 of the canonical
message, keyed with a key derived (HKDF) from the encryption key ring and bound to the scope. A very short message (a
code) cannot be guessed offline from a database dump. A fingerprint is verified with the key version it names, so a
key rotation does not turn an idempotent retry into a conflict **while the old key version is still in the ring**; once
a version is removed, a replay of an effect that used it is a conflict. Older rows hold the unkeyed `v1:<sha256>`, which
still matches.

Where the key comes from:

| How you send | Effect key |
|---|---|
| `->effectKey('order-42-shipped')` on the `PendingSend` | Yours. |
| A Laravel notification through the `push` channel | `n:<notification id>:<hash of the notifiable>`, stable across the retries of a queued notification (the id is assigned once and serialized with the job). A `pushEffectKey()` method on the notification overrides it. |
| `queue()` with no key | **None**: every call is a new effect. There is no deduplication, and the ledger still protects the rows themselves. |

<!-- example: profiles-effect-key -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// Safe to call twice (a retried controller, a re-run job): in the durable profile the second call
// finds the same effect instead of creating a second push. Pass the same key and the same message.
$effectId = MobilePush::to($user)
    ->effectKey('order-42-shipped')
    ->queue(PushMessage::make('Order shipped', 'Order #42 is on its way')->ttl(3600));
```

In a test, the fake records what was queued, so you can check the key without a database:

<!-- example: profiles-queued-assertion -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;

MobilePush::to($user)->effectKey('order-42-shipped')->queue(PushMessage::make('Order shipped'));

$fake->assertQueued(fn (SentPush $push): bool => $push->message instanceof PushMessage);
```

In **both** profiles the effect key also makes the provider message ids deterministic (a UUIDv5 of the binding, the key
and the destination) so retries present the same `apns-id`. The package does **not** claim that APNs or FCM deduplicate
on that id; that was not verified. Do not rely on it.

### Message ids

The ledger gives every delivery a random UUIDv4 `message_id` **when the row is inserted**, so it exists before the first
attempt. The same id is sent as the APNs `apns-id` on every attempt (FCM assigns its own message name, stored in
`provider_message_id`) and is what a receipt marker refers to, even when the response was lost. It never changes.

### Leases and timing

The lease (`ledger.lease_seconds`, default 120) must be longer than the chunk deadline (`queue.chunk_deadline`, default
40), which must be longer than the transport timeout. `mobile-push:doctor` checks the chain with a margin of
`max(5 s, 10 %)` per link ([queues](queues-and-horizon.md#the-timeout-chain)). A write from a stale lease is **ignored**:
if a worker outlives its lease while a request is in flight, the real outcome it later reports is dropped and the
sweeper's rule (`outcome_unknown`) stands.

### What the durable profile guarantees

- Once `queue()` returned inside a committed transaction, the intent exists. A crash after the commit loses at most the
  relay **job**, never the intent: the sweeper dispatches the relay.
- Each destination is attempted under a lease; the outcome is **recorded before the relay job is acknowledged**.
- An attempt that may have left the machine is never treated as "not sent".
- A terminal row is never overwritten (`accepted` stays `accepted`); a cancelled row is never resurrected.
- The same effect key with the same message does not create a second effect.
- Eligibility (device still active, owner unchanged, your consent callback) is checked again immediately before the
  network, so a push queued for user A never reaches a phone that was handed to user B.

### What it does not guarantee: the remaining windows

Be precise about these; they are the reason the word "exactly once" does not appear here.

| Window | Consequence |
|---|---|
| The provider processed the request, then the worker died or the connection dropped **before the outcome was written**. | The row becomes `outcome_unknown` (alerts and other non-replaceable messages are **not** re-sent: you may have an omission or a delivered push, never a duplicate by the package). A **replaceable** message may be re-sent by policy (`unknown_outcome.retry`): a duplicate, harmless by definition. With `unknown_outcome.retry = always`, duplicates are possible for any message. |
| The worker dies **while a transport window is being prepared** (before the window is handed to the transport). | Nothing was marked: those rows are simply due again after the lease. No attempt is counted and no outcome is unknown. |
| The worker dies **after a window was handed to the transport** (default window 100). Attempts are marked per window right before that, so the whole window is `sending` and its requests may or may not have left. | The whole window follows the unknown-outcome rule: `outcome_unknown` for an alert (an **omission** or a delivered push, never a duplicate by the package), planned again for a replaceable message by policy. The rows of a window that never reached the wire are reported unknown too: pessimistic but honest. |
| The record write fails (database down) after the provider accepted. | The relay retries the write; if it cannot, the job fails and the lease later expires: the same `outcome_unknown` rule as above. |
| Your business data and the ledger are on **different database connections**. | The intent does not join your transaction: your data can roll back while the effect commits, or the reverse. Put the ledger on the same connection (`ledger.connection`) if you need the outbox guarantee. |
| The scheduler (sweeper) is not running. | A relay job lost between commit and dispatch is not recovered, and deferred rows rely on the follow-up job alone. |
| The effect key is reused **after** `ledger.dedupe_seconds`. | A new effect is created: a second push. Deduplication is a window, not forever (see "Deduplication window"). |
| No effect key was given. | No deduplication across calls. |
| A cancel (`forget`, a campaign cancel) arrives while a request is in flight. | The request cannot be recalled; its real outcome is still recorded. Only further attempts are prevented. |
| `send()` (synchronous). | Not durable at all; see the light-profile table. |

### Measured on MySQL and PostgreSQL

The `scaling` workflow runs the slow scaling guards of the durable profile and of campaigns on GitHub-hosted runners,
with a fake transport (no network). The figures below are from the run of 2026-11-21; hosted runners are noisy, so
read them as orders of magnitude. The assertions are the ratios, not the absolute times.

| Guard | PostgreSQL 17 | MySQL 8.4, 2 GB buffer pool | MySQL 8.4, 128 MB (image default) |
|---|---|---|---|
| Campaign, 25,000 then 200,000 devices (linear = 8 times) | 33.6 s, 293.7 s (8.8 times) | 47.1 s, 430.0 s (9.1 times) | 38.8 s, 630.5 s (16.2 times, **guard failed**) |
| One effect: seconds per 1,000 destinations at 25,000, then at 200,000 | 1.13, 1.25 (ratio 1.11) | 1.61, 1.91 (ratio 1.18) | 1.43, 2.98 (ratio 2.08, **guard failed**) |
| One-destination effects: ms per effect for the first 2,000, then for 2,000 after 48,000 | 17.8, 16.4 | 18.0, 19.9 | 17.6, 24.4 |
| 10,000 effects deferred at the same instant: follow-up relay jobs | 21 | 21 | 21 |
| `health()`, `scopesWithWork()`, counts, funnel, open-deliveries probe next to 1.2 million final rows | 0.5 to 1.7 ms | 0.6 to 2.1 ms | 0.5 to 1.6 ms |
| A claim with its reclaim and expiry sweeps next to 1.2 million final rows | 2.2 ms | 3.5 ms | 2.6 ms |

With the 2 GB buffer pool MySQL made about a thousand disk reads for 300 million page requests. With 128 MB the
deliveries table and its indexes no longer fit in memory at 200,000 rows, and the per-destination cost doubles: size
`innodb_buffer_pool_size` for the ledger. Query plans on these engines are covered by `ServerQueryPlansTest` (see
below).

### Not verified

- **MySQL and PostgreSQL under load.** What was run: the database-backed scenarios (ledger contract in both claim
  strategies, relay, crash recovery, campaigns, devices, Live Activities, receipts, install) pass on **MySQL 8.4 and
  PostgreSQL 17 in CI** (and, since 2026-11-21, on **MariaDB 11.4**, concurrency and query-plan scenarios included;
  the scaling guards and mutation testing were not run there), and the whole suite passed once on a local PostgreSQL 16. One scenario uses two real
  connections: a second worker claims while the first one is inside its `FOR UPDATE SKIP LOCKED` transaction, and every
  delivery is claimed exactly once. On MySQL that scenario first failed with a lock wait timeout: at InnoDB's default
  REPEATABLE READ a locking read also locks index gaps, and the other claimer's `UPDATE` waited for them; the claim
  transaction now runs at **READ COMMITTED** on MySQL/MariaDB (`SET TRANSACTION ISOLATION LEVEL`, for that transaction
  only; it is skipped when the claim is called inside a transaction you opened). A second finding came from the
  scenarios where two connections write at once: when two calls record the same effect key at the same moment, the
  one that loses the unique index reads the winner's effect back and replays it; at REPEATABLE READ a plain read
  answered from the transaction's snapshot and did not see it, so that read is now a locking read. The same holds
  when **your** code calls the package inside its own transaction: registering a device or a Live Activity token, a
  client report on an activity and a campaign control (start, pause, resume, cancel) are each run in CI inside an
  open transaction while a second connection commits a change to the same row, on both engines; a re-read after a
  lost write is a locking read, and a write a unique index may refuse runs in a savepoint (on PostgreSQL it would
  otherwise abort your whole transaction). What was **not**
  run: many worker processes at production volume, and MySQL with `binlog_format=STATEMENT` (which does not
  allow READ COMMITTED writes on InnoDB).
- **Scaling on the server engines** was measured on GitHub-hosted runners (the `scaling` workflow, weekly; noisy
  machines, so read orders of magnitude). One effect of 200,000 destinations against one of 25,000: PostgreSQL 17 went
  from 1.13 to 1.25 seconds per 1,000 destinations (ratio 1.11), MySQL 8.4 from 1.61 to 1.91 (ratio 1.18). Reads next
  to 1.2 million final rows (health, scopes with work, counts, the funnel, a claim with its sweeps) stayed between 0.5
  and 3.5 ms on both. **On MySQL this needs a buffer pool that holds the working set**: with the image default of
  128 MB (`innodb_buffer_pool_size`) the same run cost 1.43 and then 2.98 seconds per 1,000 destinations (ratio 2.08)
  and the guard failed; with 2 GB it passed, with about a thousand disk reads for 300 million page requests. Size the
  buffer pool for the deliveries table and its indexes before judging throughput.
- Query plans: every hot query (claim, start, record, sweeps, audience, device lookup) is asserted on SQLite
  (`ScalingQueryPlansTest`). On PostgreSQL 17 and MySQL 8.4, in CI (`ServerQueryPlansTest`, with a few thousand due
  rows and fresh statistics), the **claim** plan is asserted: it is served by a claim index, in index order, without
  reading the whole table, and the lease update never walks a state index. For the other hot queries (sweeps, health
  and settlement reads, start and record, audience slice, device lookup) the test asserts that an index **can** serve
  each statement (PostgreSQL is asked with sequential scans disabled, MySQL through `possible_keys`), not which plan
  the planner picks: on a table of a few thousand rows a planner may still choose to read it whole, so the chosen plan
  at production size was not observed on those engines.
- Behaviour with a database that has a very high write latency, or read replicas for the ledger connection.
- The fingerprint hashing cost on very large messages.

## How to choose

| Choose | When |
|---|---|
| **Light** | Development, prototypes, notifications where "sometimes missing or doubled" is acceptable, apps without a queue worker. |
| **Durable** | Production sends that matter (orders, security codes you do not want doubled, anything under a regulator or a client), campaigns, receipts, any time you need an audit of per-device outcomes. |

Durable costs: two tables to maintain and prune, a scheduler entry, database writes per delivery state change
(the relay claims, marks and records a whole transport window per statement; a campaign of 500,000 destinations ran end
to end in 457 s on SQLite with a fake transport in the development container, a local measurement and not a promise: a
real database and real provider latency change it), and message snapshots stored encrypted until pruned.

## Switching

### Light to durable

1. Set `MOBILE_PUSH_DELIVERY_PROFILE=durable`.
2. Create the tables (see "Migrations" below).
3. Make sure the scheduler runs every minute and queue workers listen on the push queues
   ([queues](queues-and-horizon.md)).
4. Run `php artisan mobile-push:doctor`: it checks that the tables exist and that the timeout chain includes the lease.
5. Sends already waiting in the queue as light jobs finish as light jobs; new `queue()` calls use the ledger.

### Durable to light

`queue()` stops writing the ledger, but **rows already in it are no longer swept** (the sweeper is only scheduled while
the profile is durable) and `mobile-push:relay` / `mobile-push:sweep` refuse to run on the light profile. **Drain first**:
stop producing, wait until `php artisan mobile-push:health` shows no due backlog, then switch. The tables and their
history stay in place.

### Migrations

One rule for every module with tables (ledger, devices, Live Activities, database log sink, campaigns): the migrations
are **published, not auto-loaded**.

1. `php artisan mobile-push:install` copies the migrations of the modules and profile you chose into
   `database/migrations` (the ledger group has four files: the two tables, the receipt columns, the scaling indexes
   `2026_11_21_000013_add_mobile_push_ledger_scaling_indexes.php`; the devices group adds
   `2026_11_21_000002_add_mobile_push_devices_audience_index.php`).
   Without the installer: `php artisan vendor:publish --tag=mobile-push-migrations-ledger` (tags: `-devices`,
   `-live-activities`, `-logs`, `-campaigns`).
2. `php artisan migrate` runs the copies. The modules register **nothing** with the migrator by default, so a table can
   never be created by two files.
3. Teams that prefer never to publish set `MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true` (`migrations.autoload`). Then the
   enabled modules hand their package files to `migrate` (the ledger while the profile is durable), **no publish tag
   exists**, and `mobile-push:install` publishes nothing and says why. A published copy that already exists **wins**:
   the package file is then not registered, so switching a project from published to autoload (or back, after deleting
   the copies) never makes `migrate` run both.
4. As a second line of defence every migration is **idempotent**: `create table` is skipped when the table exists,
   column and index additions check what is there (`hasColumn`, `getIndexes`), and `down()` is symmetric and tolerates
   a missing table, column or index. If both sets are ever registered by hand, the second set changes nothing and
   `migrate:rollback` still drops everything. This is covered by `tests/Scenario/Laravel/Install`, which passes on SQLite and, in
   CI, on MySQL 8.4 and PostgreSQL 17.

An older build registered the package files **and** published copies, which failed with `table
"mobile_push_effects" already exists`; see [troubleshooting](troubleshooting.md).

Table names and the database connection: `ledger.tables.effects` (`MOBILE_PUSH_LEDGER_EFFECTS_TABLE`),
`ledger.tables.deliveries` (`MOBILE_PUSH_LEDGER_DELIVERIES_TABLE`), `ledger.connection`
(`MOBILE_PUSH_LEDGER_CONNECTION`, null = the default connection). Times are stored as UTC epoch milliseconds in
`BIGINT` columns (identical on SQLite, MySQL and PostgreSQL).

## Configuration reference (ledger)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `delivery.profile` | `MOBILE_PUSH_DELIVERY_PROFILE` | `light` | `light` or `durable`. |
| `ledger.connection` | `MOBILE_PUSH_LEDGER_CONNECTION` | `null` | Database connection of both tables. Use the same one as your business data for the outbox. |
| `ledger.tables.effects` / `.deliveries` | `MOBILE_PUSH_LEDGER_EFFECTS_TABLE` / `MOBILE_PUSH_LEDGER_DELIVERIES_TABLE` | `mobile_push_effects` / `mobile_push_deliveries` | Table names. |
| `ledger.lease_seconds` | `MOBILE_PUSH_LEDGER_LEASE_SECONDS` | 120 | How long a claim holds a delivery. Must exceed `queue.chunk_deadline`. |
| `ledger.dedupe_seconds` | `MOBILE_PUSH_LEDGER_DEDUPE_SECONDS` | 86400 | The deduplication window of an effect key. |
| `ledger.chunk_size` | `MOBILE_PUSH_LEDGER_CHUNK_SIZE` | 500 | Rows per insert and per claim (1 to 5000). |
| `ledger.claim_strategy` | `MOBILE_PUSH_LEDGER_CLAIM_STRATEGY` | `auto` | `auto` picks `skip_locked` on PostgreSQL and on MySQL 8.0.1+ / MariaDB 10.6+, `optimistic` elsewhere. Both are correct; `skip_locked` wastes fewer reads under contention. |
| `ledger.retention_days` | `MOBILE_PUSH_LEDGER_RETENTION_DAYS` | 30 | Used by `mobile-push:deliveries:prune`. |
| `queue.sweeper.schedule` | `MOBILE_PUSH_QUEUE_SWEEPER` | `true` | Whether the package schedules the sweeper. |

## Retention and pruning

`php artisan mobile-push:deliveries:prune` deletes **terminal** deliveries whose last
change is older than the retention **and** whose effect is past its deduplication window, in chunks, then the effects
left without deliveries. Rows that are not finished are never deleted. On the durable profile the package schedules it once a day itself
(`maintenance.schedule`, see [commands](commands.md#what-the-package-schedules-by-itself)); the Laravel scheduler must be
running. Until it runs, the **encrypted message snapshot** and the
masked outcome data stay in the table. `--pretend` counts without deleting.

For one person's data use `mobile-push:forget` ([security and privacy](security-and-privacy.md#forget-and-export)):
it cancels pending deliveries, erases destinations, owners and message snapshots and leaves non-personal tombstones that
stop a re-submitted job from re-creating the rows.
