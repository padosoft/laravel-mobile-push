# Outcomes: what a result means and what to do about it

Every destination of a send ends with exactly one `SendResult`. This page says what each status means, what it
does **not** mean, and what your application should do. Read the table once, then keep the rule at the top in mind:

> `Accepted` means **the provider accepted the request**. It never means delivered, displayed or seen.
> `OutcomeUnknown` means **we do not know**. It is not a failure, and it is never treated as one.

Related pages: [sending](sending.md) (how to send), [retries and limits](retries-and-limits.md) (what happens
before a final status), [delivery profiles](delivery-profiles.md) (what is stored), [receipts](receipts.md) (how an app
can report that a push really arrived), [troubleshooting](troubleshooting.md) (symptom to remedy).

## What a result carries

`Padosoft\MobilePush\Core\Outcome\SendResult` (read-only):

| Field | Meaning |
|---|---|
| `destination` | The typed destination the result is about (it redacts itself when dumped). |
| `status` | A `SendStatus`, see below. |
| `reason` | A `Reason`: `code` (a `ReasonCode`), `rawReason` (the provider's own string, sanitized) and `hint` (a sentence that says what to do). |
| `attempts` | How many attempts were made for this destination. |
| `providerMessageId` | The id the provider returned (APNs `apns-id`, FCM message name), when there is one. |
| `httpStatus` | The HTTP status of the last answer, when there was one. |
| `nextAttemptAt` | Set only for `Deferred`: when the next attempt is planned. |
| `destinationAction` | `none`, `quarantine` or `invalidate`: what the package did (or asked the device registry to do) with the registration. |
| `invalidatedAtMs` | APNs 410 only: the provider's timestamp in Unix milliseconds. |
| `deliveryId` | The ledger delivery id (durable profile, queued sends only). |
| `meta` | Small extra facts such as `apns-unique-id`. Never a token. |

`SendResult::isAccepted()` is true only for `Accepted`.

## The statuses

| Status | It means | It does **not** mean | What your application should do |
|---|---|---|---|
| `Accepted` | APNs or FCM took charge of the request (HTTP 2xx). | Not delivered, not displayed, not seen. The phone may be off; the user may have disabled notifications. | Treat the push as handed over. If you need proof of arrival, use [receipts](receipts.md). |
| `Rejected` | The provider **definitively refused** the request. Sending the same thing again cannot succeed. | Not a temporary error. | Read `reason` and fix the cause (payload, topic, credential). A destination that is gone is already handled for you (see destination actions). A fallback channel is allowed. |
| `Failed` | Known **not accepted**, and no attempt is left: the provider was unavailable or unreachable until the retry budget ended, or the request could not be prepared. | Not unknown: we know it was not accepted. | Retry later at your level if it matters, or fall back to another channel. |
| `Deferred` | Not finished: a new attempt is planned at `nextAttemptAt`. | Not a failure. | In a queued send the package already scheduled it (see below). For a synchronous `send()` **nothing re-sends it for you**: queue it again if you need it. |
| `OutcomeUnknown` | The request may or may not have been processed (connection lost after the request was written, an unreadable answer, a stopped worker). | Not "not sent". Not a failure. Do not assume either way. | Do not retry blindly: a retry can produce a duplicate. No fallback was started and none will be. Alert on it, count it, decide per message type. |
| `Expired` | The logical expiry (`ttl` / `expiresAt`) passed before an attempt could be made. **Nothing was sent.** | Not a provider decision. | Nothing: the message was too old to be useful. Shorten delays or lengthen the expiry if it should have gone out. |
| `Skipped` | A **local rule** stopped the attempt: consent / eligibility, frequency cap, safe mode, an open circuit breaker, a binding that no longer matches, a cancellation. | Not a provider decision. No connection was opened for it. | Read `reason.code`: `policy_ineligible`, `policy_frequency_capped`, `policy_safe_mode`, `circuit_open`, `binding_mismatch`, `cancelled`. |
| `Simulated` | Simulation mode: **no network was touched**. | Not sent. Not validated. | Nothing, in development. If you see it in production, see [safe mode](safe-mode.md). |
| `Validated` | FCM `validate_only`: Google checked the request and did not send it. | Not sent to the device. Not a delivery test. | Treat as "the request is well-formed and the credential works". |

Two helpers on the status: `SendStatus::allowsFallback()` is true only for `Rejected`, `Failed` and `Expired` (the
push is *known* not to have been accepted), and `SendStatus::isTerminal()` is true for everything except `Deferred`.
**Do not use `isTerminal()` to decide whether to retry**: it is also true for `OutcomeUnknown`.

### How a status is reached (the short version)

1. Before any network call: expiry (`Expired`), execution mode (`Simulated` or `Skipped`), your eligibility check and the
   device registry (`Skipped`), circuit breakers (`Skipped`), rate limit and throttle gate (`Deferred`).
2. One request per destination. The answer is classified once, by one classifier, into a `Decision`.
3. The retry planner decides: stop with the classified status, or `Deferred` with a `nextAttemptAt`.
4. When a retry budget ends while the provider keeps failing, the status is `Failed` with the reason code
   `retry_budget_exhausted`, or `Expired` when the next attempt would fall after the logical expiry. **Exception:** if
   any earlier attempt of that destination had an unknown outcome, the final status is `OutcomeUnknown` instead (see
   "An unknown outcome contaminates the budget").

### Reading a report

A send returns a `SendReport`: a cursor with bounded memory (it keeps counters and the first 1000 results). Read it
with `each()` or `results()`, then ask for the counters. The report drives the sending, so an unread report sends only
its first window.

<!-- example: outcomes-branch-on-status -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Your parcel is on its way', 'Arrives today'));

$toRetryLater = [];

$report->each(function (SendResult $result) use (&$toRetryLater): void {
    match ($result->status) {
        SendStatus::Accepted => null,                                   // handed to the provider, nothing more to do
        SendStatus::Deferred => $toRetryLater[] = $result->nextAttemptAt, // queue it again if it still matters
        SendStatus::OutcomeUnknown => logger()->warning('push outcome unknown', ['reason' => $result->reason->code->value]),
        SendStatus::Rejected, SendStatus::Failed, SendStatus::Expired => logger()->info($result->reason->hint ?? $result->reason->code->value),
        SendStatus::Skipped, SendStatus::Simulated, SendStatus::Validated => null,
    };
});

// Only after the cursor was read: counters.
$accepted = $report->accepted();
```

`$report->throw()` is the only method that turns a bad batch into an exception (`SendFailedException`). It throws when
at least one destination is `Rejected`, `Failed`, `Expired` or `OutcomeUnknown`; it never throws on `Skipped`,
`Deferred` or `Simulated`, and only when you call it.

<!-- example: outcomes-throw -->
```php
use Padosoft\MobilePush\Core\Exceptions\SendFailedException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// In a test: script a refusal for one token.
$fake->failFor(ApnsDeviceToken::of($iosToken), ReasonCode::PayloadInvalid);

try {
    MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi'))->throw();
} catch (SendFailedException $e) {
    // "1 of 1 push destinations were not accepted (rejected: 1, failed: 0, unknown outcome: 0, expired: 0)."
}
```

`SendReport` is **single-pass**: `counts()`, `count()`, `accepted()` and `retained()` drain what is left. After that,
iterating gives only the retained results. Iterate first, count afterwards, or ask for counters only.

## Reasons

`SendResult::$reason` is a `Reason` with three parts:

| Part | What it is |
|---|---|
| `code` (`ReasonCode`) | A provider-neutral category such as `destination_rejected`, `quota_exceeded`, `transport_unknown`. A fixed, finite vocabulary: safe to branch on, to count, to put in a metric label. |
| `rawReason` | The provider's own word (`BadDeviceToken`, `UNREGISTERED`), sanitized: bytes outside printable ASCII become `?`, cut to 120 characters, and run through the redactor (a token that a provider echoes is removed). `null` when the provider gave none. |
| `hint` | One sentence about what happened and what to do. Safe to show to an operator; not meant for end users. |

For a retry that ended without success, the `code` is `retry_budget_exhausted` or `expired`, and the original cause is
not lost: `rawReason` holds the cause's provider string (for example `ServiceUnavailable`), or the cause's reason code
when there is none, and `hint` ends with "Last cause: ...".

The complete list of codes, with every APNs reason and every FCM error the package knows, is in
[troubleshooting](troubleshooting.md#reason-catalog), and `php artisan mobile-push:explain <reason>` prints the same
text ([commands](commands.md#mobile-pushexplain)). A reason the package does not know becomes `unknown` with the raw
string in `rawReason`; it is classified by HTTP class (4xx rejected, 5xx failed and retried).

## The unknown-outcome rule

### Why it exists

Imagine the worst moment for a network call. The package writes the request to the connection and the connection
drops before the answer arrives. Did APNs process it? There is no way to know. Two wrong answers are tempting:

- "It failed, send it again": if APNs *did* process it, the user receives the notification twice.
- "It failed, send an email instead": the user may receive both.

So the package says what is true: **the outcome is unknown**. The status is `OutcomeUnknown` and nothing else happens:

- no retry (unless the message is replaceable, see below);
- **no fallback** to another channel;
- **no invalidation** of the device registration.

### When "not sent" may be claimed

The package calls a failure "not sent" (and retries it safely) only with proof: DNS resolution, the TCP connect or the
TLS handshake failed **before any byte of the request was written**. In that case the status is not unknown: the
failure is known, retried inside the budget, and ends as `Failed` if the budget runs out (reason code
`transport_not_sent`). Everything else that goes wrong after the request may have left is `Unknown`:

| What happened | Evidence | Status |
|---|---|---|
| Nothing listening, DNS failure, certificate of the server refused | nothing written | retried, `Failed` at the end |
| Request written, server never answers (timeout) | request written | `OutcomeUnknown` |
| Request processed, connection dropped before the answer | request written | `OutcomeUnknown` |
| Answer cut in the middle of the body | answered partly | `OutcomeUnknown` |
| Answer with a status or a body the package cannot read | answered | `OutcomeUnknown` (`response_unparseable`) |
| TLS 1.3 client certificate missing (the server's alert arrives after the request was written) | request written | `OutcomeUnknown` |
| FCM `DEADLINE_EXCEEDED` (504) | answered | `OutcomeUnknown` |

An HTTP answer is never "not sent": it proves the provider processed the request and tells us what it decided.

### libcurl can send a request twice, silently

This is why the transport streams request bodies. libcurl (measured on 8.5) silently re-sends a request on a new
connection, up to five times, in two situations: the stream was refused (`REFUSED_STREAM`, or a stream above the
`GOAWAY` last-stream-id), or the connection was a reused one and it dropped before any response byte, even when the
server **had already processed the request**. PHP then sees a perfectly normal 200: a duplicate push with no trace. The
transport sends every body through a read callback with no seek callback, so libcurl cannot rewind it, the re-send
fails (`CURLE_SEND_FAIL_REWIND`, errno 65) and the server receives the request exactly once. The full measurements are
in [transport](transport.md#libcurl-re-sends-requests-by-itself).

Two honest limits of that protection:

- Requests **without a body** (GET, DELETE) can still be re-sent by libcurl. The package uses them only for idempotent
  calls (listing and deleting a broadcast channel).
- `REFUSED_STREAM` and the last streams of a `GOAWAY` are protocol-wise "not processed", but PHP's curl binding does not
  expose the frame, so the package cannot tell them from "processed, then dropped". They stay `Unknown`.

### Replaceable messages

A message is **replaceable** when a later copy replaces the earlier one on the device, so a duplicate is harmless. Only
those are retried after an unknown outcome (policy `unknown_outcome.retry = replaceable`, the default):

| Replaceable | Not replaceable (never retried after an unknown outcome unless you opt in) |
|---|---|
| Live Activity `update` and `end` | Live Activity `start` |
| APNs `background`, `widgets` and `controls` pushes | Alerts |
| A `PushMessage` marked `->silent()` (APNs background / FCM data-only) | A `PushMessage` that is not silent and not marked |
| A `PushMessage` marked `->replaceable()` | |

<!-- example: outcomes-replaceable -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// "Your balance is now 120": a later copy replaces this one, so a lost answer may be retried.
$balance = PushMessage::make('Balance', 'Your balance is now 120')
    ->collapse('balance')
    ->replaceable();

// A one-time code is NOT replaceable: it must never be sent twice because of a lost answer.
$otp = PushMessage::make('Your code', '482 913')->ttl(120);

MobilePush::toToken(Platform::Ios, $iosToken)->send($balance)->each(fn () => null);
MobilePush::toToken(Platform::Ios, $iosToken)->send($otp)->each(fn () => null);
```

Setting (`config/mobile-push.php`, key `unknown_outcome.retry`, env `MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY`):
`never` (no retry after an unknown outcome, whatever the message), `replaceable` (default), `always` (you accept the
duplicates). See [configuration](configuration.md).

### An unknown outcome contaminates the budget

Suppose a replaceable message was retried after an unknown outcome, and the retries then fail with 503, or run out of
time. Declaring "failed" or "expired" would be false: the **first** attempt may have been accepted. So the budget
remembers `unknownOutcomeSeen`, and every later final state of that destination (`Failed`, `Expired`, and in the
ledger also `Cancelled`) becomes `OutcomeUnknown`. A `Rejected` answer stays `Rejected` (it is about the request
itself). The durable ledger stores the same flag (`unknown_seen`), so a worker that dies and a worker that takes over
reach the same conclusion.

### Why a fallback never starts from an unknown outcome

A fallback (for example "send an email if the push did not go through") is only sound when the push is **known** not
to have been accepted. `SendStatus::allowsFallback()` is true only for `Rejected`, `Failed` and `Expired`. The rules of
the notification fallback add: nothing is sent if any destination is `Accepted`, `Validated`, `OutcomeUnknown`,
`Deferred` (not decided yet), suppressed on purpose (`Skipped` by consent or frequency cap) or `Simulated`; and never
for a topic, condition or broadcast (the audience is unknown). See
[notifications and fallback](notifications-and-fallback.md).

<!-- example: outcomes-unknown-no-fallback -->
```php
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// In a test: the connection dropped after the request was written.
$fake->unknownFor(ApnsDeviceToken::of($iosToken));

$mayFallBack = null;
$report = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Order shipped'));
$report->each(function (SendResult $result) use (&$mayFallBack): void {
    // OutcomeUnknown is not Failed: the push may well have arrived, so no other channel is started from it.
    $mayFallBack = $result->status->allowsFallback();      // false
});

$hadUnknown = $report->hasUnknown();   // true: ask for counters only after the cursor was read
```

## What happens to the destination: actions

The classifier decides, once, what the registration deserves. These actions only act on the **generation that was
attempted**: a late answer about generation 7 never touches generation 8, even if the raw token is identical.

| Action | When | Effect on the device registry |
|---|---|---|
| `quarantine` | A routing suspicion: APNs `BadDeviceToken` (malformed, or the token belongs to the other environment), APNs `DeviceTokenNotForTopic` (token of another app), FCM `SENDER_ID_MISMATCH` (token of another Firebase project). | The registration is suspended, **not deleted**. The current push is discarded (`Rejected`) and not retried. It is reactivated by a new registration (new generation) or relabelled by the [environment probe](troubleshooting.md#environment-probe). |
| `invalidate` | APNs HTTP **410** with `Unregistered` or `ExpiredToken`, only if the provider's timestamp is later than the registration; FCM `UNREGISTERED`. | The registration is marked invalidated and no longer used. A bare 410 without a reason does not invalidate. A 410 whose timestamp is not later than the registration (an old answer) is ignored. |
| `none` | Everything else, including FCM `INVALID_ARGUMENT` (usually the payload) and FCM `NOT_FOUND` without an FCM detail. | Nothing. |

Actions only apply to individual destinations (device tokens, installations); topics and broadcast channels never get
one. An `OutcomeUnknown` never causes quarantine or invalidation. See [devices](devices.md).

Why quarantine and not delete? `BadDeviceToken` is ambiguous: the token can be malformed, or perfectly good on the
*other* APNs environment (a TestFlight/production mix-up). Deleting would destroy a good registration; the package
suspends it and lets an operator resolve it.

### Credential actions

The same decision may also ask something of the credential:

| Action | When | What the package does |
|---|---|---|
| `refresh_token` | APNs `ExpiredProviderToken`; FCM `UNAUTHENTICATED` | One coordinated refresh of the provider token for that credential generation (other workers reuse the new one), then one more attempt. A second expiry in the same budget falls back to normal backoff, so it cannot loop. |
| `slow_token_refresh` | APNs `TooManyProviderTokenUpdates` | Token renewals slow down. **Never** switches to the secondary credential for this. |
| `report_auth_failure` | A refused credential: `InvalidProviderToken`, `Forbidden`, bad certificate, FCM `PERMISSION_DENIED`, ... | Feeds the authentication circuit breaker. After repeated failures it fails over to the secondary credential once, or opens. See [retries and limits](retries-and-limits.md#the-three-circuit-breakers). |

## Quick checklist for your code

- Branch on `status`, log `reason->code`, show `hint` to operators.
- Never treat `OutcomeUnknown` as `Failed`, and never retry it by hand without thinking about duplicates.
- A synchronous `send()` that returns `Deferred` has **not** been retried; queue it if it matters.
- `Accepted` is not "the user saw it". If you need that, build on [receipts](receipts.md) and read their coverage.
- Use `->effectKey(...)` and the [durable profile](delivery-profiles.md) when a duplicate or a lost push is costly.
