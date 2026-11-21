---
title: "Retry, budgets and breakers"
description: "Defines how many times and until when one destination may be attempted, who decides that, how waiting is handled without blocking a worker, how rate limits are counted, and which breakers exist."
---

# Retry, budgets and breakers

**Purpose.** Defines how many times and until when one destination may be attempted, who decides that, how waiting is handled without blocking a worker, how rate limits are counted, and which breakers exist. Sources: `docs/PLAN.md` §4.5, §6.2 and §6.6. The retry, limiter and breaker classes are being written by a parallel task and are not described here; this document states the contract from the plan. The code today provides the inputs: `Decision` (`retryable`, `minDelaySeconds`, `throttleProvider`) and the reason codes `RetryBudgetExhausted`, `CircuitOpen` and `Expired`.

## Rules

1. **One budget per destination.** The absolute deadline is `min(logical expiry, retry.max_elapsed)`. The budget also holds `max_attempts` and `next_attempt_at`.
2. **One planner.** `RetryPlanner` is the only component that plans attempts. Transport, sender, job and failover all consume the same counter (plan §4.5). The `Decision` says whether a retry is allowed at all; the planner decides when.
3. **No long sleep.** Library code may not sleep for more than a few milliseconds (`AGENTS.md`). A wait longer than a short threshold becomes `Deferred` with `nextAttemptAt`.
4. **Durable profile**: `next_attempt_at` is persisted. **Light profile**: a new job carries the remaining list of destinations. `release()` is not used, because it would replay a rewritten payload.
5. A deferred attempt whose time is past the deadline does not touch the network. It becomes `Expired`.
6. **Rate limits count messages**, not HTTP requests. The key is the Firebase project or the Apple team, never the connection alias. Per topic or per device is added only when the provider signals it. Transactional traffic has a reserved quota.
7. `rate.fcm_per_minute` is an application ceiling, not Google's quota (plan §4.5).
8. **Three breakers, kept separate:**
   - Authentication: per credential. Decides failover to the secondary credential once, for everyone.
   - Quota: per project.
   - Destination quality: per binding, with a minimum sample and a controlled probe before it reopens.
9. Time budget chain (plan §6.6), checked by `doctor`: I/O timeout < chunk deadline < job timeout < worker timeout < `retry_after` (or the SQS visibility timeout), each with margin.

## Provider signals

| Signal | Decision (plan §4.5) |
|---|---|
| APNs `ExpiredProviderToken` | One coordinated refresh of the relevant generation, then one attempt |
| APNs `TooManyProviderTokenUpdates` | Slow down renewals; do not switch to the secondary credential |
| APNs 5xx on a device send | `Deferred` per Apple's guidance (15 minutes, to be quoted from a fixture); `Expired` if that passes the deadline |
| FCM `QUOTA_EXCEEDED` or `UNAVAILABLE` | `Deferred`, minimum 1 minute, `Retry-After` if present; jitter never goes below the minimum |
| Generic HTTP/2 error, timeout after send, unparseable response | `OutcomeUnknown` with evidence kept (see `outcomes.md`) |

## Types

- Names from plan §5: `Retry\RetryPlanner`, `RetryBudget`, `Backoff`, `RateLimiter`, `ThrottleGate`, `BreakerAuth`, `BreakerQuota`, `BreakerDestination`. At the time of writing, `RetryPlanner` and the breakers have no file; `RateLimiter` and `ThrottleGate` and some `Retry\` classes are in progress elsewhere.
- In code now: `Outcome\Decision` (`retryable`, `minDelaySeconds`, `throttleProvider`), `Outcome\SendStatus::Deferred` and `Expired`, `Outcome\ReasonCode::RetryBudgetExhausted`, `CircuitOpen`, `Expired`, `Outcome\CredentialAction`

## Worked cases

**A. Expired provider token.**
- Situation: APNs answers `ExpiredProviderToken` while several workers send.
- The package: one worker refreshes the token of the relevant generation; the others reuse the new token (compare-and-swap, see `classification.md`). Then one more attempt.
- The caller sees: the normal result of that attempt. A rejected token never produces a second refresh loop.

**B. FCM quota exceeded.**
- Situation: FCM answers `QUOTA_EXCEEDED` with `Retry-After: 30`.
- The package: `Deferred`, with the wait at least the initial minimum of one minute (plan §4.5). How a `Retry-After` shorter than that combines with the minimum is not stated (see Open points).
- The caller sees: `SendStatus::Deferred` with `nextAttemptAt`. The provider is not called before that time.

**C. Backlog past its expiry.**
- Situation: a worker picks up a deferred delivery after the logical expiry.
- The package: `Expired`, decided without a request.
- The caller sees: `SendStatus::Expired`. The mock server counts zero requests for that destination.

**D. Two aliases on one Firebase project.**
- Situation: two connections point at the same project; the quota is per project.
- The package: both aliases share one quota counter.
- The caller sees: the quota error from one alias also slows the other (plan §13 lists this as a case to test).

## Plan vs code

- Plan §4.5 says a deferred attempt beyond the deadline becomes `Expired`. `SendStatus::Expired` has the same meaning in code.
- Plan §4.5 gives no numbers for `max_attempts`, `max_elapsed`, the short-wait threshold, the jitter or the breaker thresholds.

## Open points

- Default values of `max_attempts`, `retry.max_elapsed` and the short-wait threshold (plan gives none).
- Jitter formula, beyond "never below the minimum".
- Minimum sample size and probe rules for the destination-quality breaker (plan §4.5 names them, gives no values).
- Format of `Retry-After` (plan §13), and how a `Retry-After` shorter than the one-minute FCM minimum combines with it (plan §4.5 gives both and no combining rule).
- Exact scope of `TooManyProviderTokenUpdates` (plan §13).
- Whether a failed post-refresh attempt is `Failed` or `Deferred` (plan says "poi un tentativo" only).

---

*This page is generated from [`docs/contracts/retry.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/retry.md). To change it, edit that file in the repository, not this site.*
