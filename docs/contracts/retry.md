# Retry, budgets and breakers

**Purpose.** Defines how many times and until when one destination may be attempted, who decides that, how waiting is handled without blocking a worker, how rate limits are counted, and which breakers exist. Sources: `docs/PLAN.md` §4.5, §6.2 and §6.6, and the code in `src/Core/Retry` and `src/Core/Guard`. The inputs come from `Decision` (`retryable`, `minDelaySeconds`, `throttleProvider`); the reason codes are `RetryBudgetExhausted`, `CircuitOpen` and `Expired`.

## Rules

1. **One budget per destination.** The absolute deadline is `min(logical expiry, retry.max_elapsed)`. The budget also holds `max_attempts` and `next_attempt_at`.
2. **One planner.** `RetryPlanner` is the only component that plans attempts. Transport, sender, job and failover all consume the same counter (plan §4.5). The `Decision` says whether a retry is allowed at all; the planner decides when.
3. **No long sleep.** Library code may not sleep for more than a few milliseconds (`AGENTS.md`). A wait longer than `retry.inline_threshold_ms` (default `0`: only immediate retries, such as the one right after a token refresh, run inline) becomes `Deferred` with `nextAttemptAt`.
4. **Durable profile**: `next_attempt_at` is persisted. **Light profile**: a new job carries the remaining list of destinations. `release()` is not used, because it would replay a rewritten payload.
5. A deferred attempt whose time is past the deadline does not touch the network. It becomes `Expired`.
6. **Rate limits count messages**, not HTTP requests. The key is the Firebase project or the Apple team, never the connection alias. Per topic or per device is added only when the provider signals it. Transactional traffic has a reserved quota.
7. `rate.fcm_per_minute` is an application ceiling, not Google's quota (plan §4.5).
8. **Three breakers, kept separate:**
   - Authentication: per credential. Decides failover to the secondary credential once, for everyone.
   - Quota: per project.
   - Destination quality: per binding, with a minimum sample and a controlled probe before it reopens.
9. Time budget chain (plan §6.6), checked by `doctor` (`QueueTimeouts`): transport timeout < chunk deadline < job timeout < worker timeout < `retry_after` (or the SQS visibility timeout), and in the durable profile chunk deadline < ledger lease, each link with a margin of `max(5 s, 10 %)`. The shipped defaults (30 < 40 < 50 < 55 < 90, lease 120) fit a stock Laravel application; see `docs/queues-and-horizon.md`.
10. **Defaults** (`RetryPolicy`, config `retry.*`): `max_attempts` 5 (first attempt included), `max_elapsed` 3600 s, backoff base 1000 ms doubled per retry up to 300000 ms, `jitter` 0.2, unknown-outcome policy `replaceable`.
11. **Jitter** (`Backoff`): for retry n the exponential part is `min(max, base * 2^(n-1))`. Above the provider minimum the delay is spread **down** by at most `jitter` but never below the minimum (`[max(min, exp * (1 - jitter)), exp]`); otherwise the minimum dominates and the delay is spread **up** from it (`[min, min * (1 + jitter)]`), so destinations throttled together do not return together.
12. **Plans that give up** end as `Failed` with `RetryBudgetExhausted` (attempts or `max_elapsed` ran out) or `Expired` with `Expired` (the next attempt would fall at or after the logical expiry); the raw cause is kept in `Reason::$rawReason`. If any attempt of the budget ended unknown the status stays `OutcomeUnknown`, because the provider may already have accepted the message.

## Provider signals

| Signal | Decision (plan §4.5) |
|---|---|
| APNs `ExpiredProviderToken` | One coordinated refresh of the relevant generation, then one attempt |
| APNs `TooManyProviderTokenUpdates` | Slow down renewals; do not switch to the secondary credential |
| APNs 5xx on a device send | `Deferred`, minimum `apns.server_error_retry_delay` (default 900 s, Apple's 15 minutes); `Expired` if that passes the deadline |
| APNs `TooManyRequests` (per device) | `Deferred`, minimum `apns.device_rate_limit_delay` (default 60 s) |
| FCM `QUOTA_EXCEEDED` or `UNAVAILABLE` | `Deferred`, minimum `fcm.throttled_min_delay` (default 60 s), `Retry-After` if present; jitter never goes below the minimum |
| Generic HTTP/2 error, timeout after send, unparseable response | `OutcomeUnknown` with evidence kept (see `outcomes.md`) |

## Types

- `Retry\RetryPlanner` (the only planner), `RetryBudget`, `RetryPlan`, `RetryPolicy`, `Backoff`, `RetryAfter` (parser of the `Retry-After` header: delta-seconds or an HTTP date, clamped to one day), `UnknownOutcomeRetry`
- `Guard\RateLimiter`, `ThrottleGate`, `AuthBreaker` (authentication), `DestinationQualityBreaker`, `Guards` (facade over them), `GuardStore` (`InMemoryGuardStore`; the Laravel layer backs it with the shared cache), `AccountKey`, `TrafficClass`. The quota signal is the provider-wide throttle (`ThrottleGate`, fed by `Decision::$throttleProvider`); there is no separate "quota breaker" class.
- `Outcome\Decision`, `SendStatus::Deferred` and `Expired`, `ReasonCode::RetryBudgetExhausted`, `CircuitOpen`, `Expired`, `CredentialAction`
- Laravel: `Queue\QueueTimeouts` / `TimeoutChain` (the chain above), config blocks `retry`, `unknown_outcome`, `apns`, `fcm`, `guards`

## Worked cases

**A. Expired provider token.**
- Situation: APNs answers `ExpiredProviderToken` while several workers send.
- The package: one worker refreshes the token of the relevant generation; the others reuse the new token (compare-and-swap, see `classification.md`). Then one more attempt.
- The caller sees: the normal result of that attempt. A rejected token never produces a second refresh loop.

**B. FCM quota exceeded.**
- Situation: FCM answers `QUOTA_EXCEEDED` with `Retry-After: 30`.
- The package: `Deferred`, with the wait at least the configured minimum (`fcm.throttled_min_delay`, 60 s). A `Retry-After` shorter than the minimum does not lower it; a longer one raises it (`Backoff` never schedules before the provider minimum).
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
- The numbers the plan left open are now set in `RetryPolicy` and `config/mobile-push.php` (see rule 10). Breaker defaults: authentication opens after 5 consecutive credential failures with a 60 s cooldown; destination quality needs a sample of 200 results and opens at 50 % bad destinations with a 300 s cooldown before a controlled probe. Rate ceilings: `guards.rate.fcm` 500000 messages per minute per project, `guards.rate.apns` unlimited, 20 % reserved for transactional traffic.
- `Retry-After` shorter than the provider minimum: the minimum wins (`Backoff` never schedules earlier than `Decision::$minDelaySeconds`).
- `TooManyProviderTokenUpdates` is `ReasonCode::AuthTokenThrottled` with `CredentialAction::SlowTokenRefresh`: renewals slow down and the secondary credential is never used for it.

## Open points

- Whether the provider-wide throttle for APNs should honour more than the documented signals: only the ones in the catalogs are used. Not verified against real traffic.
- The destination-quality thresholds are engineering defaults, not provider values; tune them against real traffic.
- Rate ceilings and the inline threshold were exercised against fake and mock transports only, never against Apple or Google.
