---
title: "Client receipts"
description: "Receipts let the app tell your server that a push reached it (\"received\") or was opened. They are optional, off by default, and need the durable delivery profile."
---

# Client receipts

Receipts let the app tell your server that a push **reached it** ("received") or was **opened**. They are optional,
**off by default**, and need the **durable delivery profile**.

| | |
|---|---|
| Enable | `mobile-push.receipts.enabled` = `true` (env `MOBILE_PUSH_RECEIPTS_ENABLED=true`) **and** `mobile-push.delivery.profile` = `durable` (env `MOBILE_PUSH_DELIVERY_PROFILE=durable`) |
| Migration tag | the ledger migrations: `php artisan vendor:publish --tag=mobile-push-migrations-ledger` then `php artisan migrate` (the third file adds the receipt columns `message_id`, `receipt_marker` and the client event columns) |
| Route | `POST {prefix}/receipts`, default `POST /mobile-push/receipts` |

With `receipts.enabled = true` but the light profile, **no route is registered** and no marker is issued: receipts write to
the ledger, which the light profile does not have.

Related pages: [outcomes](/reliability/outcomes), [campaigns](/modules/campaigns), [queues and Horizon](/reliability/queues-and-horizon),
[security and privacy](/operations/security-and-privacy), [configuration](/get-started/configuration), [troubleshooting](/operations/troubleshooting).

## What a receipt is, and what it is not

- `Accepted` means the **provider accepted** the push. It is not delivery.
- A receipt proves that the app **possesses the marker** that was in the payload. It does not prove a human read
  anything, and it does not prove the notification was displayed.
- iOS and Android do **not** guarantee that your code runs for every delivered notification. A missing report means
  **"not observed"**, never "not delivered". The package never turns an absence of receipts into a failure.
- Topic, condition and broadcast pushes (and Live Activity pushes) carry **no marker**: one payload reaches many phones, so
  there are no individual receipts for them, by design.

The marker is only issued for sends that go through the ledger (`->queue()` on the durable profile, and campaigns).
The synchronous `->send()` path writes no ledger row and therefore carries no marker, because a marker without a row to
match would be a dead reference.

## The marker

Every individual push sent with receipts on carries one extra custom value, `mp_r`: a top-level key next to `aps` for
APNs, a `data` entry for FCM (so it counts against the FCM payload size). It is an opaque URL-safe string of about 55
characters: an HMAC over the scope, the sender's message id for that delivery and an expiry, with a **dedicated key ring**
(independent of the index and encryption keys; several versions verify, the current one signs).

- `mp_r` is **reserved**: an APNs message whose own custom data uses that key is refused with an `InvalidMessageException` when a marker is added.
  The marker does not switch `mutable-content` on for you.
- It is bound to **one delivery** and expires after `receipts.marker_ttl` (default 604800 seconds, 7 days, minimum 60).
- The scope used to look the delivery up comes from the verified marker, never from the request: tenant A's marker can
  only ever look inside tenant A.
- It is never logged, never echoed back and never put in a cache key (rate limits use a one-way hash). The log sinks are
  given each issued marker as a runtime secret so it cannot appear in a log line.
- The ledger assigns each delivery a random UUIDv4 `message_id` at insert; that id is used as `apns-id` and as the receipt
  reference, so a delivery whose response was lost can still be matched by its marker.

Key configuration: `receipts.keys` (map version 1-255 => base64 key of at least 32 bytes; env
`MOBILE_PUSH_RECEIPTS_KEY` fills version 1) and `receipts.key_current` (`MOBILE_PUSH_RECEIPTS_KEY_VERSION`, `0` = the
highest version). Empty means version 1 is derived from `APP_KEY`.

## The endpoint and its configuration

`POST {prefix}/receipts`, JSON:

```json
{ "marker": "<mp_r value>", "event": "received", "at": "2026-11-20T10:00:03Z" }
```

- `event` is `received` or `opened`. `at` is optional (ISO-8601 with offset); the server clamps it into
  `[delivery creation, now]` because phone clocks lie.
- A small batch is accepted: a JSON array of such objects, or `{"receipts": [...]}`, capped by `receipts.max_batch`
  (default 20).

| Answer | Meaning |
|---|---|
| `204` | recorded, **or already recorded** (idempotent: each event is recorded once per delivery, first write wins; `opened` before `received` is fine) |
| `422` | not accepted. The body is always the same and never says why: malformed, forged, expired, unknown, or the delivery is not in a state that takes a report. One legitimate case: the report outran the server (the push reached the phone before the server saved the send result). Retry once or twice after a few seconds, then drop it |
| `429` | too many reports from this IP or for this marker; `Retry-After` is set |

Only deliveries in state `Accepted` or `OutcomeUnknown` take a report.

Configuration (all under `mobile-push.receipts`):

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `MOBILE_PUSH_RECEIPTS_ENABLED` | `false` | |
| `marker_ttl` | `MOBILE_PUSH_RECEIPTS_TTL` | `604800` | seconds, at least 60 |
| `max_batch` | | `20` | reports per request |
| `route.prefix` | `MOBILE_PUSH_RECEIPTS_PREFIX` | `mobile-push` | |
| `route.domain` | | `null` | serve the route on a dedicated domain |
| `route.middleware` | | `['throttle:mobile-push-receipts']` | list of middleware |
| `rate_limit.per_ip` | | `120` | requests per minute per IP, `0` = no limit |
| `rate_limit.per_marker` | | `10` | reports per minute per marker, `0` = no limit |
| `keys`, `key_current` | `MOBILE_PUSH_RECEIPTS_KEY`, `MOBILE_PUSH_RECEIPTS_KEY_VERSION` | derived from `APP_KEY`, `0` | see above |

The routes are not registered when your application has cached its routes (`route:cache`): clear the cache after enabling
receipts or changing the prefix.

### No authentication by default, and how to add it

The marker is the capability, so the route has **no auth middleware by default**: the app can report from a Notification
Service Extension that has no user session. The package limits abuse with a per-IP limiter (named
`mobile-push-receipts`) and a per-marker limit. If you need a report tied to an authenticated installation, add your own
middleware and send the auth header from the app:

```php
// config/mobile-push.php
'receipts' => [
    'route' => [
        'prefix' => 'mobile-push',
        'middleware' => ['throttle:mobile-push-receipts', 'auth:sanctum'],
    ],
],
```

Then compare `$request->user()` with the owner of the delivery in a listener of `PushReceivedReported` /
`PushOpenedReported`. Those events carry the scope, effect id, delivery id, destination kind and both timestamps, never
the token or the marker. A Notification Service Extension usually cannot reach the app's session: for it, the marker
alone is what you have.

## The funnel: denominators and coverage

```php
use Padosoft\MobilePush\Laravel\Receipts\ReceiptFunnelReader;

$funnel = app(ReceiptFunnelReader::class)->forEffect($scope, $effectId);   // or ->forCampaign($scope, $campaignId)
$funnel->toArray();
```

The funnel is **accepted by the provider -> able to report (carried a marker) -> reported received -> reported opened**,
always with its denominator:

| Field | Meaning |
|---|---|
| `accepted` | deliveries the provider accepted (the denominator) |
| `acceptedWithMarker` | of those, how many carried a marker and could report (**coverage** = `markerCoverage()`) |
| `acceptedWithoutMarker()` | accepted deliveries that could not report at all |
| `reportedReceived`, `reportedOpened` | accepted deliveries with a client-reported event (`opened` is independent of `received`) |
| `receivedNotObserved()` | able to report but no `received` seen: **not** "not delivered" |
| `receivedRate()`, `openedRate()` | reported / able to report: the honest rate, a lower bound of the real one |
| `receivedOfAccepted()`, `openedOfAccepted()` | reported / all accepted (includes those that could not report) |
| `unknownOutcome`, `unknownReportedReceived`, `unknownReportedOpened`, `unknownClientConfirmed` | the unknown-outcome bucket, below |
| `acceptedOrClientConfirmed()` | accepted + unknown outcomes that the phone confirmed |

Always show the coverage next to a rate. A "received rate of 40%" over 50% marker coverage is a very different statement
from the same number over 100%.

```php
use Padosoft\MobilePush\Core\Ledger\ReceiptFunnel;

// Row layout of the deliveries table (only the columns the funnel reads).
$funnel = ReceiptFunnel::fromRows([
    ['state' => 'accepted', 'receipt_marker' => 1, 'client_received_at' => 1_000, 'client_opened_at' => 1_030],
    ['state' => 'accepted', 'receipt_marker' => 1, 'client_received_at' => 1_001, 'client_opened_at' => null],
    ['state' => 'accepted', 'receipt_marker' => 1, 'client_received_at' => null, 'client_opened_at' => null],
    ['state' => 'accepted', 'receipt_marker' => 0, 'client_received_at' => null, 'client_opened_at' => null],  // a topic: no marker
    ['state' => 'outcome_unknown', 'receipt_marker' => 1, 'client_received_at' => 1_002, 'client_opened_at' => null],
]);

expect($funnel->accepted)->toBe(4)
    ->and($funnel->acceptedWithMarker)->toBe(3)
    ->and($funnel->markerCoverage())->toBe(0.75)
    ->and($funnel->receivedNotObserved())->toBe(1)       // not observed, NOT "not delivered"
    ->and($funnel->unknownClientConfirmed)->toBe(1)
    ->and($funnel->acceptedOrClientConfirmed())->toBe(5);
```

### Unknown outcomes confirmed by a receipt

When the response to a send was lost, the delivery ends `OutcomeUnknown`. A receipt for it is evidence that the push did
reach the phone, so the report is recorded and counted in the `unknown*` fields. The provider outcome is **not changed**:
it stays unknown in the ledger and in `SendStatus`; the client confirmation is a separate, third fact. The funnel keeps
the three apart: provider accepted, client-reported received, client-reported opened.

## The app-side contract

Fire and forget, with a short timeout. Never block the notification on it. Do not queue reports for days. The Swift and
Kotlin below come from `src/Laravel/Receipts/CLIENT_CONTRACT.md` and **were not compiled**.

**iOS.** Received: a Notification Service Extension (`UNNotificationServiceExtension`) with `mutable-content: 1` in the
push. Opened: the `UNUserNotificationCenterDelegate`.

```swift
// NotificationService.swift (extension target)
override func didReceive(_ request: UNNotificationRequest,
                         withContentHandler handler: @escaping (UNNotificationContent) -> Void) {
    let content = (request.content.mutableCopy() as? UNMutableNotificationContent) ?? UNMutableNotificationContent()
    guard let marker = request.content.userInfo["mp_r"] as? String else { return handler(content) }
    Receipts.send(marker: marker, event: "received") { handler(content) }   // finish within ~30 s
}

// AppDelegate / delegate (main app)
func userNotificationCenter(_ center: UNUserNotificationCenter,
                            didReceive response: UNNotificationResponse,
                            withCompletionHandler done: @escaping () -> Void) {
    if let marker = response.notification.request.content.userInfo["mp_r"] as? String,
       response.actionIdentifier == UNNotificationDefaultActionIdentifier {
        Receipts.send(marker: marker, event: "opened") { done() }
    } else { done() }
}

enum Receipts {
    static func send(marker: String, event: String, done: @escaping () -> Void) {
        var req = URLRequest(url: URL(string: "https://api.example.com/mobile-push/receipts")!, timeoutInterval: 8)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        let at = ISO8601DateFormatter().string(from: Date())
        req.httpBody = try? JSONSerialization.data(withJSONObject: ["marker": marker, "event": event, "at": at])
        URLSession.shared.dataTask(with: req) { _, _, _ in done() }.resume()
    }
}
```

iOS caveats: the extension runs only for pushes that are visible alerts with `mutable-content: 1`. It does not run for
silent/background pushes, when notifications are turned off for the app, in Focus modes that suppress delivery, or when
the system kills the extension for exceeding its time or memory budget. "Opened" is only seen when the user taps the
notification itself. Remember to ask for `mutable-content` on messages that should be able to report (`->mutableContent()`
on the message).

**Android.** Received: `FirebaseMessagingService.onMessageReceived`. Opened: the intent behind the notification tap.

```kotlin
class PushService : FirebaseMessagingService() {
    override fun onMessageReceived(message: RemoteMessage) {
        message.data["mp_r"]?.let { Receipts.send(applicationContext, it, "received") }
        // ... show your notification, and put the marker in the tap intent:
        // intent.putExtra("mp_r", message.data["mp_r"])
    }
}

// In the launched Activity (onCreate / onNewIntent):
intent.getStringExtra("mp_r")?.let { Receipts.send(applicationContext, it, "opened") }

object Receipts {
    fun send(context: Context, marker: String, event: String) {
        // Prefer WorkManager (network constraint, a few retries) over a bare thread.
        val body = JSONObject(mapOf("marker" to marker, "event" to event,
            "at" to java.time.Instant.now().toString())).toString()
        // POST body as application/json to https://api.example.com/mobile-push/receipts
    }
}
```

Android caveats: with a `notification` message (not `data`) delivered while the app is in the background, FCM shows the
notification itself and `onMessageReceived` is **not** called, so "received" is not observable (use a data message that
you display yourself, or accept the gap); the tap then opens your launcher activity with the data in the intent extras.
Battery optimisation, "restricted" app standby buckets, force-stop and a user who disabled the notification channel all
hide pushes from your code.

## How the pieces connect on the server

- On the durable profile the manager hands every ledger send an issuer callback (`ReceiptMarkerIssuer::callback($scope)`),
  and the relay sends each delivery under the ledger's message id. Nothing to wire when receipts are enabled.
- A marker is issued only for individual installations (not topics, conditions, broadcast channels) and not for Live
  Activity or push-to-start tokens.
- `PushReceivedReported` and `PushOpenedReported` are dispatched once per delivery and event.
- Receipts do not depend on the device registry or on notifications; they depend on the ledger.

What was not verified: the endpoint and funnel are exercised with synthetic markers and a fake transport on SQLite. No
real device has produced a receipt, and the Swift and Kotlin code was not compiled or run.

---

*This page is generated from [`docs/receipts.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/receipts.md). To change it, edit that file in the repository, not this site.*
