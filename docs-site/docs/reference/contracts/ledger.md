---
title: "Ledger (delivery records)"
description: "Defines what the two delivery profiles guarantee, what the durable ledger records, how duplicates are detected, and how outcomes are recorded around the network call. Source: docs/PLAN.md §4.3 and §4.1."
---

# Ledger (delivery records)

**Purpose.** Defines what the two delivery profiles guarantee, what the durable ledger records, how duplicates are detected, and how outcomes are recorded around the network call. Source: `docs/PLAN.md` §4.3 and §4.1. **There is no implementation in `src/Core/Ledger` yet.** Everything here is the plan's contract; the names of planned classes are marked as planned.

## Rules

1. **Light profile (default).** No tables. Deduplication is in-process only. It is documented as **best effort**, including for `queueMany()`: after a crash, duplicates or omissions are possible.
2. **Durable profile** (`delivery.profile = durable`). Records effects and deliveries in the database before any dispatch. Recommended for production. Receipts and campaigns require it (plan §8).
3. **Effect** (`mobile_push_effects`, one per send call): the ID is generated before dispatch, plus scope, binding, `effect_key`, payload fingerprint, an encrypted snapshot of the message, type, replaceability, logical expiry and `policy`.
4. **Delivery** (`mobile_push_deliveries`, one per logical destination): status, attempts, `next_attempt_at`, lease (owner and expiry), version, provider ID, `reason_code`, `raw_reason`, timestamps.
5. **Uniqueness**: `(scope, effect_key, logical destination)` is unique. Reusing a key with a different payload fingerprint throws `IdempotencyConflictException`.
6. **`effect_key` default**: the Laravel Notification UUID, or a ULID generated at the call. Keys also cover topics, conditions and channels. The deduplication window is configurable (plan does not name the key or its default).
7. **Outbox.** If the call happens inside a transaction on the same database connection as the ledger, the intent is committed together with the business data. A recoverable relay (`RelayDeliveriesJob` plus a scheduled sweeper) picks it up later.
8. The worker re-reads unfinished destinations and **records outcomes before acknowledging the job.**
9. **No SQL transaction is open during the network call.**
10. A lease that expires after the send started does **not** prove `NotSent`. Apply `outcomes.md`: the result is `OutcomeUnknown` unless the transport proved otherwise.

## Profiles

| Profile | Stores | Dedup | Guarantee | Does NOT guarantee |
|---|---|---|---|---|
| Light (default) | Nothing | In-process | Best effort | No crash safety; duplicates or omissions possible, including `queueMany()` |
| Durable | Effects, deliveries, outbox | Unique key in the database | Each destination's outcome is recorded before ack; intent committed with business data when inside a transaction | Does not make the provider's processing exactly-once; an unknown outcome stays unknown |

**Only `queue()` uses the ledger** (decision, W4-I). `PendingSend::send()` is the synchronous path in both profiles: it sends now, returns the report, and writes no effect, no delivery and no outbox row; it never carries a receipt marker (a marker is issued only for a destination that has a ledger delivery id). Use `queue()` when the push must survive a crash, be deduplicated by its effect key across processes, or report client receipts. No `durably()` option exists: a durable synchronous send would need the relay's lease protocol in the caller's process, which is what `queue()` on the `sync` queue connection already gives.

**Payload fingerprint** (decision, W4-I). The effect row stores `v2:k<key version>:<hex>`: HMAC-SHA256 of the canonical message, keyed with a key derived (HKDF, own label) from the encryption key ring (`TokenCipher::digest()`) and bound to the scope, so a short message (an OTP) cannot be confirmed offline from a database dump. Same message, same scope, same key version = same value. Rows written before this change hold the unkeyed `v1:<sha256>` and still match. A keyed value is verified with the key version it names; once that version is removed from the ring the value cannot be verified and a replay of that effect key is a conflict.

## Delivery states and the write order

| Step | Written | Source |
|---|---|---|
| Call | Effect row with its ID, fingerprint and snapshot; one delivery row per destination (unique) | Plan §4.3 |
| Before network | Lease with owner and expiry (the plan requires the lease fields; the exact moment is implied, not stated) | Plan §4.3, inferred timing |
| After response or proven failure | Status, `reason_code`, `raw_reason`, provider ID, timestamps | Plan §4.3 |
| Before ack | The above, committed | Plan §4.3 ("record outcomes before the ack") |

The plan does not list the full set of delivery statuses. The `SendStatus` values from `outcomes.md` are the public statuses; the mapping to stored rows is a planned detail.

## Types (planned, not yet in code)

- `Ledger\EffectStore`, `Ledger\DeliveryStore` (contracts), `Ledger\InMemoryLedger`, `Ledger\PayloadFingerprint` (plan §5)
- Laravel side (plan §5): `Ledger/` with Eloquent models, outbox and relay; `RelayDeliveriesJob` (plan §4.3). No file exists for these yet.
- Existing in code: `Exceptions\IdempotencyConflictException` (extends `ValidationException`), `Identity\BindingRef`, `Outcome\SendStatus`

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
- Situation: a retried call reuses `effect_key` but the payload differs.
- The package: fingerprint mismatch.
- The caller sees: `IdempotencyConflictException`; the first effect is untouched.

**D. Light profile after a crash.**
- Situation: the process crashes midway through `queueMany()`.
- The package: no records exist, so the process cannot know what was sent.
- The caller sees: possible duplicates or omissions, as the light profile declares.

## Plan vs code

- The ledger is entirely plan-only. The directory `src/Core/Ledger` does not exist.
- Plan §5 places `Ledger/` in `Core` with `EffectStore` and `DeliveryStore` as contracts, while the Laravel ledger is in `src/Laravel`. Plan §4.3 does not say which of these names are final.

## Open points

- Exact column list and types (plan lists fields, not types or indexes).
- Name and default of the config keys for the deduplication window.
- Delivery status values in storage, and how they map to `SendStatus`.
- Lease duration and renewal (plan does not give numbers).
- How `DeliveryStore` connects to `SendReport` in the Core layer.

---

*This page is generated from [`docs/contracts/ledger.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/ledger.md). To change it, edit that file in the repository, not this site.*
