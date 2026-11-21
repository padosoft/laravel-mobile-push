---
title: "Retries, limits and circuit breakers"
description: "This page explains what the package does between \"the request failed\" and \"this is the final status\": how many times it tries again, how long it waits, how it protects the provider account from being flooded, and when…"
---

# Retries, limits and circuit breakers

This page explains what the package does between "the request failed" and "this is the final status": how many times it tries again, how long it waits, how it protects the provider account from being flooded, and when it stops sending altogether. Every number below is a configuration key with the default shown; the full key list is in [configuration](/get-started/configuration).

Related pages: [outcomes](/reliability/outcomes) (what the final statuses mean), [queues and Horizon](/reliability/queues-and-horizon) (where the waiting happens), [troubleshooting](/operations/troubleshooting).

## One budget per destination

Every destination of every send has **one retry budget**. Nobody else decides about retries: the transport does not retry, the queue job does not retry on its own, the credential failover consumes the same counter. A single component (`RetryPlanner`) turns every classified answer into either a final status or `Deferred`.

The budget holds:

| Part | Where it comes from | Default |
|---|---|---|
| Attempts | `retry.max_attempts`: total attempts per destination, **the first one included** | 5 |
| Elapsed deadline | `retry.max_elapsed`: seconds from the first attempt to the last planned one | 3600 |
| Logical expiry | The message's `expiresAt()` / `ttl()` (or `PendingSend::expiresAt()`), pinned to an absolute instant when the send is created | none |
| Next attempt | Chosen by the planner on every retry | - |
| Unknown-outcome flag | Set once an attempt ended with an unknown outcome (see [outcomes](/reliability/outcomes#outcomes-what-a-result-means-and-what-to-do-about-it-the-unknown-outcome-rule-an-unknown-outcome-contaminates-the-budget)) | false |

The **deadline** is the earlier of the logical expiry and `first attempt + max_elapsed`. No attempt is ever planned at or after it. The rules that follow from that:

- A retry that would fall after the logical expiry is not made: the final status is `Expired` (reason code `expired`).
- A retry that would fall after `max_elapsed`, or when the attempts ran out, ends as `Failed` (reason code `retry_budget_exhausted`).
- A destination whose expiry has already passed when its turn comes (a long queue backlog, a delayed job) is **not** sent: `Expired`, decided without a request.
- A relative `ttl()` is converted to an absolute time **when the send is created** (queue time), not when a worker picks it up, so waiting in a queue counts against the message's life. A resumed destination keeps its original expiry.
- If any earlier attempt of the destination had an unknown outcome, the final state is `OutcomeUnknown` instead of `Failed` or `Expired`.

### "Now or never": `ttl(0)`

`PushMessage::ttl(0)` means exactly one attempt: the provider is told not to store the message (APNs `apns-expiration: 0`, FCM ttl `0s`), the package never retries it, and a retryable failure of that one attempt ends as `Expired` (or `OutcomeUnknown` if the attempt's outcome was unknown). Use it for things that are useless a second later.

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// A flash sale that is useless after ten minutes: never retried past that.
$sale = PushMessage::make('Flash sale', 'Ends in 10 minutes')->ttl(600);

// "Open the door now": one attempt, never stored by the provider, never retried.
$now = PushMessage::make('Your driver is outside')->ttl(0);

// Campaign-style traffic cannot eat the share reserved for one-to-one messages.
MobilePush::toToken(Platform::Ios, $iosToken)->bulk()->send($sale)->each(fn () => null);
MobilePush::toToken(Platform::Ios, $iosToken)->send($now)->each(fn () => null);
```

### Waiting never blocks a worker

The package does not sleep for seconds inside a request. `retry.inline_threshold_ms` (default `0`) is the longest wait done in-process. With the default only an **immediate** retry (the one right after a provider token refresh) runs inline; every other retry comes back as `Deferred` with `nextAttemptAt` and the waiting is somebody else's job:

| Where the send runs | Who handles `Deferred` |
|---|---|
| Synchronous `send()` | **Nobody.** The result is returned to you with `nextAttemptAt`. The package does not remember it. Queue the send again if you need the retry. |
| Light profile job (`queue()`) | The job finishes and dispatches a **new** job that carries only the deferred destinations with their budgets, delayed to their next attempt. It never `release()`s the job (a released job is the same payload again). Attempts that fall within `queue.follow_up_bucket` seconds (default 60) share one continuation job. |
| Durable profile (`queue()`) | The delivery row stores `next_attempt_at` and the budget; one follow-up relay job per effect is scheduled for the earliest one, and the scheduled sweeper catches anything that was lost. |

```php
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// In a test, script what APNs would do: "unavailable, try again later".
$fake->failFor(ApnsDeviceToken::of($iosToken), ReasonCode::ProviderUnavailable, SendStatus::Deferred);

$when = null;
MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi'))
    ->each(function (SendResult $result) use (&$when): void {
        if ($result->status === SendStatus::Deferred) {
            $when = $result->nextAttemptAt;   // a DateTimeImmutable: the package will NOT send it again by itself here
        }
    });
```

## Backoff

For the n-th retry (1 is the first retry) the base delay is `min(backoff_max_ms, backoff_base_ms * 2^(n-1))`, spread by jitter. With the defaults: 1 s, 2 s, 4 s, 8 s ... capped at 300 s.

| Key | Env | Default |
|---|---|---|
| `retry.backoff_base_ms` | `MOBILE_PUSH_RETRY_BACKOFF_BASE_MS` | 1000 |
| `retry.backoff_max_ms` | `MOBILE_PUSH_RETRY_BACKOFF_MAX_MS` | 300000 |
| `retry.jitter` | `MOBILE_PUSH_RETRY_JITTER` | 0.2 (0 to 1) |
| `retry.max_attempts` | `MOBILE_PUSH_RETRY_MAX_ATTEMPTS` | 5 |
| `retry.max_elapsed` | `MOBILE_PUSH_RETRY_MAX_ELAPSED` | 3600 |
| `retry.inline_threshold_ms` | `MOBILE_PUSH_RETRY_INLINE_THRESHOLD_MS` | 0 |

**A provider minimum always wins.** When the provider asks for a minimum delay (below), the wait is never shorter than it. If the minimum is longer than the exponential delay, the delay is spread **upward** from the minimum (up to `jitter` more), so thousands of destinations throttled at the same moment do not all come back at the same instant. If the exponential delay is longer, it is spread downward but never below the minimum.

### Provider minimum delays

| Signal | Minimum | Key (default) |
|---|---|---|
| APNs 5xx on a device or broadcast send (`InternalServerError`, `ServiceUnavailable`, `Shutdown`, an unknown 5xx) | 15 minutes | `apns.server_error_retry_delay` (900 s) |
| APNs `TooManyRequests` (too many consecutive pushes to one device token) | 60 s (Apple gives no figure: "retry with a delay") | `apns.device_rate_limit_delay` (60 s) |
| FCM `QUOTA_EXCEEDED`, `RESOURCE_EXHAUSTED` (429), `UNAVAILABLE` (503) | the larger of 60 s and the `Retry-After` header | `fcm.throttled_min_delay` (60 s) |
| FCM `INTERNAL` (500), unknown FCM 5xx | the `Retry-After` header when present, none otherwise (plain backoff) | - |

Apple's wording, recorded in the classifier from the JSON form of the page "Handling notification responses from APNs" (fetched 2026-11-20): *"After 15 minutes, you can retry JSON payloads that receive response status codes that begin with 5XX. While retrying, you may employ a back-off technique."* With the defaults (`max_elapsed` 3600 s) at most three such retries fit before the budget ends, so an APNs outage longer than about 45 minutes ends as `Failed`.

`Retry-After` is read as delta-seconds or an HTTP-date, clamped to 24 hours, and only used where the table says so. The FCM codes and their delays are verified against the official Firebase Admin SDK sources, **not** against Google's error-code page (it was not reachable when they were written).

## Provider signal to package behaviour

| Provider signal | What the package does |
|---|---|
| APNs 2xx / FCM 2xx | `Accepted` (or `Validated` for FCM `validate_only`). |
| APNs `ExpiredProviderToken` (403), FCM `UNAUTHENTICATED` (401) | One coordinated refresh of that credential's token (workers reuse the new one), then **one immediate retry**. A second expiry in the same budget uses normal backoff. If the server clock is wrong this repeats: fix the clock. |
| APNs `TooManyProviderTokenUpdates` (429) | Slows token renewals. Retried with normal backoff. **Never** switches to the secondary credential. |
| APNs `InvalidProviderToken`, `Forbidden`, `MissingProviderToken`, bad certificate, `BadEnvironmentKey*InToken`; FCM `PERMISSION_DENIED` | No retry. Feeds the authentication breaker. |
| APNs 5xx | `Deferred` after at least 15 minutes. `ServiceUnavailable` (503) also **pauses the whole Apple team** (throttle gate) for that long. Past the deadline: `Failed` / `Expired`. |
| APNs `TooManyRequests` | `Deferred` after at least 60 s. No account-wide pause. |
| APNs `IdleTimeout` | The connection was idle and closed before the request was processed: re-sent on a fresh connection. |
| FCM `QUOTA_EXCEEDED`, `UNAVAILABLE` | `Deferred` after at least 60 s (more with `Retry-After`); the Firebase project is paused (throttle gate). |
| FCM `INTERNAL` | `Deferred` with backoff honouring `Retry-After`. |
| FCM `DEADLINE_EXCEEDED` (504) | `OutcomeUnknown`: retried only if the message is replaceable. |
| APNs `BadDeviceToken`, `DeviceTokenNotForTopic`; FCM `SENDER_ID_MISMATCH` | `Rejected`, no retry, destination quarantined. |
| APNs 410, FCM `UNREGISTERED` | `Rejected`, no retry, destination invalidated. |
| Payload / request errors (400, `PayloadTooLarge`, FCM `INVALID_ARGUMENT`) | `Rejected`, no retry. |
| DNS, connect or TLS failure before the request was written | Known not sent: retried with backoff; `Failed` at the end. |
| Timeout, reset, GOAWAY or an unreadable answer after the request was written | `OutcomeUnknown`; retried only if replaceable (`unknown_outcome.retry`). |
| An error string this version does not know | By HTTP class: 429 and 5xx retried, other 4xx `Rejected`, reason code `unknown`. |

The per-reason detail is in the [reason catalog](/operations/troubleshooting#troubleshooting-reason-catalog).

## Rate limit: messages, per provider account

The limiter is a **token bucket counted in messages** (not HTTP requests, not jobs), keyed by the **provider account**: the Firebase project id for FCM, the Apple team id for APNs. It is never keyed by the connection alias or the scope: two connections (or two tenants) that use the same Firebase project share one bucket, because the quota they would exhaust is the project's.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `guards.rate.fcm` | `MOBILE_PUSH_RATE_FCM` | 500000 | Messages per minute for an FCM project. `null` = unlimited. |
| `guards.rate.apns` | `MOBILE_PUSH_RATE_APNS` | `null` (unlimited) | Messages per minute for an Apple team. |
| `guards.rate.burst` | `MOBILE_PUSH_RATE_BURST` | `null` (one minute of traffic) | Bucket capacity in messages. |
| `guards.rate.reserved_share` | `MOBILE_PUSH_RATE_RESERVED_SHARE` | 0.2 | Fraction of the capacity that **bulk** traffic can never take. |

These are **application ceilings, not the provider's quota**. Setting one protects you and your neighbours; it does not tell you what Google or Apple would allow.

How it behaves:

- The bucket refills continuously (not on the clock minute). Over any window of T seconds at most `burst + rate * T / 60` messages are granted. "Per minute" is therefore a rate, with a burst allowance.
- **Reserved share.** Traffic is either `transactional` (default: one-to-one messages the user is waiting for) or `bulk` (`PendingSend::bulk()`, campaigns). Bulk can never use the last `reserved_share` of the bucket, so a campaign that drains the bucket still leaves room for a login code.
- When the bucket is empty the destinations that did not get a permit are **not sent** and come back `Deferred` with reason code `quota_exceeded` and a `nextAttemptAt` computed from the refill rate. A window can be partly granted: some destinations go, the rest are `Deferred`.
- The bucket state lives in the shared cache (`cache.store`); with more than one worker, use a lock-capable store (see [queues and Horizon](/reliability/queues-and-horizon#queues-workers-horizon-and-the-scheduler-what-to-run-the-cache-store)).

### When the cache behind the guards is down

The rate limiter, the throttle gate and the circuit breakers keep their state in the shared cache store. That state is a protection, not a prerequisite, so the package never lets a cache problem stop or break a send. Two different situations are told apart:

- **The cache store is unreachable** (the server is down, the connection is refused, the store cannot take locks). The guards **fail open**: sending continues, the limits and breakers are **not enforced across processes** (each process decides only on what it can see, normally nothing), and nothing throws. The package logs a warning ("mobile-push cache unavailable: behaving as an empty store.") **at most once a minute per process**, and leaves the backend alone for **5 seconds** after a failure before trying it again, so a dead cache server does not cost a connection timeout on every send. While it is down you have no shared rate limit: a burst can exceed the provider's limits, and a failing credential cannot trip its breaker for the other workers.
- **The cache store is alive but too contended** (many workers keep winning the same compare-and-set, so a guard cannot update its state). The guard gives up and **grants nothing**: the destinations come back `Deferred` with reason code `quota_exceeded`, a hint that says the shared state was too contended, no request is made and the retry planner picks them up again later. This is a deliberate refusal to send without a limit, not an error.

Neither case throws out of a send. Both are covered by `GuardStoreOutageTest` against in-memory stores that imitate an unreachable and a contended backend; the real adapter (`CacheGuardStore`) was exercised on the array driver and has **not been tried against a real Redis, Memcached or database cache** going down under load.

## The throttle gate

The throttle gate is a shared "paused until" instant per provider account. It is set when a final classification says the provider as a whole is slowing down (`throttleProvider`): APNs `ServiceUnavailable`, FCM `QUOTA_EXCEEDED`, `RESOURCE_EXHAUSTED` and `UNAVAILABLE`. The pause lasts `max(60 s, the provider's minimum delay)` (so 15 minutes after an APNs 503) and a later pause never shortens an earlier one. While paused, every destination for that account is `Deferred` with reason code `quota_exceeded`, **without any request**, until `nextAttemptAt`.

> A `Deferred` with `quota_exceeded` does not always mean a real quota. It can be the throttle gate that an APNs 503 opened, or your own rate limit. The `hint` says "The provider account is throttled or the send rate limit is reached". `php artisan mobile-push:circuit` shows the pause ([commands](/operations/commands#artisan-commands-mobile-pushcircuit)).

## The three circuit breakers

Three separate mechanisms stop sending, each with its own scope. (The plan calls them three breakers: authentication, quota and destination quality. In the code the quota one is the throttle gate above plus the rate limiter, so there are two `Breaker` classes and the gate.)

| | Scope | Opens when | Effect | Closes when |
|---|---|---|---|---|
| **Authentication breaker** | One credential (primary and secondary together) | `guards.auth.threshold` consecutive credential failures (default 5) | With a secondary credential configured: **switches to it once**, for everyone, instead of opening. Without one (or after the switch): opens. Destinations are `Skipped` with `circuit_open`. | After `guards.auth.cooldown` seconds (default 60) exactly **one** probe request is let through; success closes it, failure reopens it. |
| **Throttle gate** (quota) | One provider account | A throttling answer (see above) | Destinations `Deferred` with `quota_exceeded`, no requests | The pause ends. |
| **Destination-quality breaker** | One binding (scope, provider, app, environment) | At least `guards.destination_quality.min_sample` outcomes (default 200) in a 10-minute window, of which more than `guards.destination_quality.threshold` (default 0.5) were routing suspicions (quarantine: `BadDeviceToken`, `DeviceTokenNotForTopic`, `SENDER_ID_MISMATCH`) | **Bulk traffic only** is `Skipped` with `circuit_open`; transactional traffic is never gated by it. It protects the provider account from a campaign aimed at the wrong environment or app. | After `guards.destination_quality.cooldown` seconds (default 300), a probe batch of 20 is let through; the breaker closes once 20 probe results are in and no more than half were bad, and reopens otherwise. |

Details worth knowing:

- Only real authentication failures feed the authentication breaker. A stale provider token (`refresh_token`) and token renewal throttling (`slow_token_refresh`) never do. A failure reported for a credential generation that is no longer active is ignored, which is how the failover stays single.
- Invalidations (410, `UNREGISTERED`) and anything that says nothing about the destination (auth, throttling, unknown, 5xx) are not part of the quality sample. `Accepted`, `Validated` and `Rejected` count as good.
- The sample window length (600 s), halving size (2000) and probe size (20) are fixed values, not configuration keys.
- State changes are events: `CircuitOpened`, `CircuitClosed`, `CredentialFailedOver`, `ProviderThrottled`, `AuthTokenRefreshed` (namespace `Padosoft\MobilePush\Laravel\Sending\Events`). Alerts and metrics build on them; see [observability](/modules/observability).
- `php artisan mobile-push:circuit` shows the state and `--reset` closes the two breakers (after confirmation). Reset only after fixing the cause: a breaker that opened for a real reason just opens again.

| Key | Env | Default |
|---|---|---|
| `guards.auth.threshold` | `MOBILE_PUSH_AUTH_BREAKER_THRESHOLD` | 5 |
| `guards.auth.cooldown` | `MOBILE_PUSH_AUTH_BREAKER_COOLDOWN` | 60 |
| `guards.destination_quality.min_sample` | `MOBILE_PUSH_QUALITY_MIN_SAMPLE` | 200 |
| `guards.destination_quality.threshold` | `MOBILE_PUSH_QUALITY_THRESHOLD` | 0.5 (between 0 and 1, exclusive) |
| `guards.destination_quality.cooldown` | `MOBILE_PUSH_QUALITY_COOLDOWN` | 300 |
| `unknown_outcome.retry` | `MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY` | `replaceable` (`never`, `replaceable`, `always`) |

## The order of checks before a request

For every destination, before any external effect, in this order: logical expiry, execution mode ([safe mode](/reliability/safe-mode)), eligibility and consent gates (device registry, your `eligibleUsing()` callback, frequency cap), authentication breaker, throttle gate, destination-quality breaker (bulk only), rate limit. Only then is the message compiled, the provider token fetched and the request sent. A denial at any step is a result for that destination (`Skipped` or `Deferred`), never an exception.

## What is not verified

- The shared state (breakers, gate, rate limit) is exercised in tests with an in-memory store and the Laravel cache adapter on the array driver. It has **not** been tried against a real Redis, Memcached or database cache under concurrent workers. The compare-and-set uses cache locks, which need a lock-capable store.
- The FCM minimum delays and the Apple 15-minute figure follow the sources named above; nothing here was measured against the live APNs or FCM services.
- The numbers behind `max_attempts`, `max_elapsed`, the jitter and the breaker thresholds are the package's own defaults, not provider recommendations.

---

*This page is generated from [`docs/retries-and-limits.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/retries-and-limits.md). To change it, edit that file in the repository, not this site.*
