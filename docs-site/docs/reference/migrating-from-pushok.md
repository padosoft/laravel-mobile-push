---
title: "Migrating from Pushok (edamov/pushok)"
description: "Pushok is a small, solid library: it speaks APNs over HTTP/2 with a token or a certificate and gives you back one response per notification."
---

# Migrating from Pushok (`edamov/pushok`)

Pushok is a small, solid library: it speaks APNs over HTTP/2 with a token or a certificate and gives you back one
response per notification. A lot of Laravel applications built their push on top of it, directly or through a notification
channel. This page is for teams who want to move to `padosoft/laravel-mobile-push` without surprises: what maps to what,
what behaves differently, and a plan that can be rolled back.

**What I read.** The Pushok README and the source of `Payload`, `Notification`, `Payload\Alert`, `Client` and `Response` at
tag **`0.19.1`** (the latest tag I could list with `git ls-remote --tags https://github.com/edamov/pushok`), fetched from
`raw.githubusercontent.com`. I did not run Pushok. Where I describe how Pushok behaves, it is what those files say; where I
am not sure, I say so. If you use another version, check the method names against it.

Related pages: [getting started](/get-started/getting-started), [sending](/sending/sending), [messages](/sending/messages),
[credentials for APNs](/get-started/credentials-apns), [outcomes](/reliability/outcomes), [testing](/sending/testing),
[comparison](/reference/comparison), [upgrading](/operations/upgrading), and the sibling guide
[migrating from laravel-notification-channels/apn](/reference/migrating-from-notification-channels-apn).

## The model is different in three ways

1. **Typed, explicit destinations.** Pushok takes a string token and a client built for one environment. Here a destination
   is a typed object (`ApnsDeviceToken`, `FcmToken`, ...) and the platform is **always explicit**: `toToken(Platform::Ios,
   $token)`. A bare string is refused. The environment (`production` / `sandbox`) belongs to the connection and is
   **declared, never guessed**.
2. **Outcomes instead of exceptions or raw responses.** A send returns a report with one `SendResult` per destination. A
   rejected token is a result, not an exception. Exceptions are only for input that is wrong as a whole (bad configuration,
   a malformed message, an invalid destination), raised before the network.
3. **Safe by default.** Outside production the package **simulates**: nothing is sent unless you opt in.

## Side-by-side: sending

```php
// Pushok 0.19.1
$alert = Alert::create()->setTitle('Hello!')->setBody('First push notification');
$payload = Payload::create()->setAlert($alert)->setSound('default')->setCustomValue('order_id', '1042');

$notifications = [];
foreach ($deviceTokens as $deviceToken) {
    $notifications[] = new Notification($payload, $deviceToken);
}
$client = new Client($authProvider, $production = false);
$client->addNotifications($notifications);
$responses = $client->push();            // one Response per Notification
```

```php
// This package
$report = MobilePush::toTokens(Platform::Ios, $deviceTokens)->send(
    PushMessage::make('Hello!', 'First push notification')
        ->sound('default')
        ->data(['order_id' => '1042']),
);
$report->counts();                       // consume the report: see "The report must be consumed"
```

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toTokens(Platform::Ios, [$iosToken])->send(
    PushMessage::make('Hello!', 'First push notification')
        ->sound('default')
        ->data(['order_id' => '1042']),
);

$counts = $report->counts();   // consuming the report is part of sending

$fake->assertSentTimes(1);
```

### Message API mapping

| Pushok (`Payload`, `Alert`, `Notification`) | This package |
|---|---|
| `Alert::setTitle()`, `setSubtitle()`, `setBody()` | `PushMessage::make($title, $body)` and `->subtitle()`; or `ApnsMessage::alert($title, $body, $subtitle)` |
| `setTitleLocKey()`/`setTitleLocArgs()`, `setLocKey()`/`setLocArgs()`, `setActionLocKey()` | `ApnsMessage::withAlert(Alert::localized(...))`, `Alert::withTitleLocalization()`, `withBodyLocalization()` (see [messages](/sending/messages)). I did not find an equivalent of `action-loc-key` |
| `setLaunchImage()` | `Alert::withLaunchImage()` |
| `Payload::setBadge($n)` | `->badge($n)` |
| `setSound('default')` | `->sound('default')`; critical sound: `ApnsMessage::critical($name, $volume)` or `PushMessage::critical()` |
| `setCategory($c)` | `ApnsMessage::category($c)` (APNs only) or `PushMessage::actionCategory($c)` (cross-platform; not the same as `policy`, see [policies](/modules/policies)) |
| `setThreadId($id)` | `PushMessage::group($id)` or `ApnsMessage::threadId($id)` |
| `setInterruptionLevel()` | `ApnsMessage::interruptionLevel(InterruptionLevel::TimeSensitive)`, or `PushMessage::timeSensitive()` / `critical()` |
| `setContentAvailability(true)` | `ApnsMessage::background()` or `PushMessage::silent()` (a silent push is `content-available` with priority 5) |
| `setMutableContent(true)` | `->mutableContent()` |
| `setCustomValue($k, $v)`, `addCustomValue()` | `PushMessage::data([$k => $v])`; `ApnsMessage::custom($k, $v)` / `data([...])` for non-scalar values |
| `setUrlArgs()` | no equivalent found (Safari web push is out of scope for this version) |
| `setRelevanceScore()`, `setStaleDate()`, `setTimestamp()`, `setContentState()`, `setEvent()`, `setAttributesType()`, `addAttribute()`, `setDismissalDate()` (Live Activity fields on `Payload`) | the Live Activities module, which also keeps the token registry and the rules: see [live activities](/modules/live-activities) |
| `setPushType($type)` | `ApnsMessage::pushType(PushType::...)` or the factories (`alert()`, `background()`, `voip()`, `widgetsReload()`, ...) |
| `Notification::setExpirationAt($dt)` | `->expiresAt($dt)` or `->ttl($seconds)`; `ttl(0)` means exactly one attempt, never stored by APNs |
| `setHighPriority()` / `setLowPriority()` (10 / 5) | `->priority(MessagePriority::High)` / `Normal`; APNs-specific: `ApnsMessage::priority(Priority::...)` |
| `setCollapseId($id)` | `->collapse($key)` (APNs collapse id, FCM collapse key) |
| `setId($uuid)` (the `apns-id`) | not settable per message. Provider message ids are derived deterministically from the effect key: set `->effectKey('...')` and retries reuse the same id |

To reach APNs-only fields from a cross-platform `PushMessage`, use the overlay:
`PushMessage::make(...)->apns(fn (ApnsMessage $m) => $m->category('order')->threadId('orders'))`.

### Responses and results

| Pushok (`Response`) | This package (`SendResult`) |
|---|---|
| `getStatusCode()` (200, 410, 429, ...) | `$result->httpStatus`; and the classified `$result->status` (`SendStatus`: `Accepted`, `Rejected`, `Failed`, `Deferred`, `OutcomeUnknown`, `Expired`, `Skipped`, `Simulated`, `Validated`) |
| `getErrorReason()` (`Unregistered`, `BadDeviceToken`, ...) | `$result->reason->rawReason`; the stable code is `$result->reason->code` (`ReasonCode`), and `$result->reason->hint` says what to do. `php artisan mobile-push:explain Unregistered` prints cause and remedy |
| `getApnsId()` | `$result->providerMessageId` |
| `getDeviceToken()` | the destination is masked in logs; `$result->destination` is the typed object (never print it) |
| `get410Timestamp()` | `$result->invalidatedAtMs`, plus `$result->destinationAction` (`Invalidate` / `Quarantine` / `None`) |
| a transport failure or a dropped connection | not an exception: `Failed` when it is known nothing was accepted, **`OutcomeUnknown`** when the request may have been accepted |

Read `Accepted` as "the provider accepted the request", never as "delivered". See [outcomes](/reliability/outcomes).

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi', 'There'));

$rejected = 0;
foreach ($report as $result) {
    match ($result->status) {
        SendStatus::Accepted, SendStatus::Simulated => null,
        SendStatus::Rejected => $rejected++,        // the provider refused it: see $result->reason
        SendStatus::OutcomeUnknown => null,         // it may have been accepted: NOT a failure, do not resend blindly
        default => null,                            // Failed, Deferred, Expired, Skipped, Validated
    };
}

expect($rejected)->toBe(0);
```

## Configuration mapping

Pushok options (an array passed to `AuthProvider\Token::create()` or `Certificate::create()`) become one entry of
`mobile-push.connections`. Secrets are *sources* (`path`, `inline`, `base64` or `resolver`), never closures; see
[credentials for APNs](/get-started/credentials-apns) and [configuration](/get-started/configuration).

| Pushok | `config/mobile-push.php` | Notes |
|---|---|---|
| `key_id` | `key_id` | |
| `team_id` | `team_id` | required here for **both** token and certificate auth (Pushok needs it for the token only) |
| `app_bundle_id` | `bundle_id` | the APNs topic |
| `private_key_path` | `key => ['path' => ...]` | also `inline`, `base64` or `resolver` |
| `private_key_secret` | no equivalent | the token-auth `.p8` is read as Apple delivers it (an unencrypted PEM). I found no passphrase option for it. For a certificate the option is `passphrase` |
| `AuthProvider\Certificate` + `certificate_path` | `auth => 'certificate'`, `certificate => ['path' => ...]` (+ `certificate_key` if separate) | |
| `certificate_secret` | `passphrase` | |
| `new Client($auth, $production)` | `environment => 'production'` or `'sandbox'` | **explicit and per connection**; it is not derived from `APP_ENV` and not guessed from the token |
| `setNbConcurrentRequests(n)` | `window` (default 100 requests multiplexed per window) | transport settings can also be set globally in `mobile-push.transport` |
| `setMaxConcurrentConnections(n)` | `max_connections` (default 1) | |
| curl options such as `CURLOPT_SSL_VERIFYPEER => false` | `ca_bundle`, `proxy` | do not disable certificate verification; point the package at the right CA bundle |

```php
// config/mobile-push.php
'connections' => [
    'ios' => [
        'driver' => 'apns',
        'auth' => 'token',
        'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
        'key_id' => env('MOBILE_PUSH_APNS_KEY_ID'),
        'key' => ['path' => env('MOBILE_PUSH_APNS_KEY_PATH')],
        'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),
        'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),
    ],
],
```

Pushok's README warns that its JWT goes stale after an hour and that you must regenerate it on long-running tasks. Here the
provider token is cached, refreshed ahead of expiry and shared through the package cache; you do not manage it.

## Behavioural differences a migrating team must know

1. **Simulation by default outside production.** `mobile-push.mode` is `auto`: live only when `APP_ENV=production`,
   otherwise **every result is `simulated` and nothing touches the network**. A staging server that "worked" with Pushok
   will appear to send and deliver nothing until you opt in: `MOBILE_PUSH_MODE=live`, `live.allow = true` on the
   connection and the test device tokens in `live.only_to`; every other destination is `Skipped`. See [safe mode](/reliability/safe-mode).
2. **Explicit platform and environment.** `toToken(Platform::Ios, ...)`, never a bare string; the environment is part of the
   connection. A sandbox token on a production connection gets `BadDeviceToken` and, with the device registry, the device is
   *quarantined*, not retried on the other endpoint ([devices](/modules/devices)).
3. **Statuses instead of exceptions.** One destination failing never throws. Look at `$result->status`. Only an invalid
   configuration, message or destination throws, before anything is sent.
4. **The report must be consumed.** A send returns a cursor over results. The first window of requests starts inside
   `send()`, but the rest is pulled as you read: call `counts()`, `each()`, `results()` or iterate it. Ignoring the report
   means ignoring the outcome (and, for a large stream, leaving work undone). Do not discard it.
5. **`Accepted` is not delivered, and `OutcomeUnknown` is not a failure.** When the connection drops after the request was
   written, the package cannot know whether APNs accepted it. It does not retry that blindly (only for replaceable messages, by
   default). Treat `outcome_unknown` as its own bucket in your dashboards.
6. **The package retries within a budget.** Transient errors (5xx, throttling, expired provider token) are retried with
   backoff and jitter under a per-destination budget (`mobile-push.retry.*`, [retries and limits](/reliability/retries-and-limits)); a
   result is either final or `Deferred` with a next attempt time. Remove your own retry loop around the call, or you will
   multiply attempts.
7. **Dead tokens.** Pushok hands you the 410 and its timestamp and you delete the token. Here, with the registry enabled,
   `Unregistered`/`ExpiredToken` invalidate the row and routing mistakes quarantine it, automatically, comparing the 410
   timestamp with the registration time. Without the registry you act on `$result->destinationAction` yourself.
8. **Queues.** Sending inside a request or a command is synchronous (`->send()`); queue it with `->queue($message)` (light
   profile: encrypted jobs; durable profile: a ledger with idempotent retries). The package owns that queueing; do not wrap it
   in your own job that also retries.
9. **Nothing is logged by default.** Add a send log sink if you want one ([observability](/modules/observability)); tokens are
   never written in clear, only a keyed index and a masked form.

## A step-by-step plan, with a way back

1. **Inventory** every place that builds a Pushok `Client`/`Notification` (and the notification channels that wrap it).
   Note the connection each uses and what happens to the responses today.
2. **Install and configure side by side.** `composer require padosoft/laravel-mobile-push`; add the connection as above using
   the **same** `.p8` key (it is just a key: nothing about it changes). Pushok stays installed.
3. **Check the setup.** `php artisan mobile-push:doctor` (add `--live` to send one real probe per connection), then
   `php artisan mobile-push:connections`.
4. **Compare payloads without sending.** `php artisan mobile-push:preview --connection=ios --title=... --body=...` prints the
   headers and JSON the message compiles to; compare with what Pushok sent for the same notification.
5. **Send to one test device for real**, with the allowlist: set `MOBILE_PUSH_MODE=live`, `live.allow` and `live.only_to`
   for that token, then `php artisan mobile-push:send --platform=ios --connection=ios --stdin` (exit codes: 0 ok, 1 partial, 2
   invalid input, 3 simulated, 4 unknown outcome).
6. **Switch one low-risk call site** behind a flag (config value or feature flag), keeping the Pushok code path next to it.
   Consume the report: at minimum log the counts and every non-`Accepted` result's `reason->code`.
7. **Move the rest**, one notification at a time. Replace your retry and token-deletion code with the package's (registry or
   `destinationAction`). Decide whether you want the registry ([devices](/modules/devices)) or keep your own token table and pass
   typed destinations.
8. **Watch it.** Enable a log sink and, if you use them, Pulse or Prometheus ([observability](/modules/observability)). Look at the
   share of `outcome_unknown` and `rejected` by reason.
9. **Remove Pushok** (and its configuration) when nothing references it.

**Rollback.** While both libraries are installed, rolling back is flipping the flag of step 6 back: the key and the app are
untouched. What is *not* undone by a rollback: rows already written (registry devices, durable-profile ledger rows, log
rows), and the tokens the package invalidated or quarantined, which Pushok would have simply tried again. Keep a copy of the
token table you had before step 7. Anything sent through the package was really sent; there is no "undo send".

## Not verified

Nothing in this package has run against real APNs or a real device; it is tested against a local HTTP/2 mock and a fake
transport. The behaviour of Pushok described above comes from reading its source at `0.19.1`, not from running it.

---

*This page is generated from [`docs/migrating-from-pushok.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/migrating-from-pushok.md). To change it, edit that file in the repository, not this site.*
