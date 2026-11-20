# Receipts: what the app must send

A receipt is a report from the app. Read it for what it is:

- The marker in the payload proves the app **possesses the marker**. It does not prove delivery and it does not prove
  that a person read anything.
- iOS and Android do **not** guarantee that your code runs for every delivered notification. A missing report means
  **"not observed"**, never "not delivered".
- The funnel always shows its denominator: accepted by the provider -> able to report (carried a marker) -> reported
  received -> reported opened.
- Topic, condition and broadcast-channel pushes carry **no marker** (one payload reaches many phones). They have no
  individual receipts, by design.

## The payload

Every individual push sent with receipts on carries one extra custom value, `mp_r` (APNs: a custom top-level key next to
`aps`; FCM: a `data` entry). It is an opaque URL-safe string (about 55 characters). Do not parse it, do not log it, do
not send it anywhere except to the receipts endpoint. It expires (default 7 days) and is bound to one delivery.

## The endpoint

`POST {prefix}/receipts` (default `/mobile-push/receipts`), JSON:

```json
{ "marker": "<mp_r value>", "event": "received", "at": "2026-11-20T10:00:03Z" }
```

- `event` is `received` or `opened`. `at` is optional (ISO-8601 with offset); the server clamps it into
  `[delivery creation, now]` because phone clocks lie.
- A small batch is accepted: a JSON array of such objects (or `{"receipts": [...]}`), capped by
  `mobile-push.receipts.max_batch` (default 20).
- `204`: recorded, or already recorded (idempotent: send it again if unsure). Each event is recorded once per delivery;
  `opened` before `received` is fine.
- `422`: not accepted. The body is always the same; it never says why (malformed, forged, expired, unknown).
  One case is legitimate: the report outran the server (the push reached the phone before the server saved the send
  result). Retry once or twice after a few seconds, then drop it.
- `429`: too many reports from this IP or for this marker. Back off (`Retry-After`).
- Fire and forget, with a short timeout. Never block the notification on it. Do not queue reports for days.

## iOS

Received: a Notification Service Extension (`UNNotificationServiceExtension`) with `mutable-content: 1` in the push.
Opened: the `UNUserNotificationCenterDelegate`.

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

Caveats (iOS): the extension runs only for pushes that are visible alerts with `mutable-content: 1`; it does not run for
silent/background pushes, when the user force-quit... (the extension still runs, but a background-only push does not wake
the app), when notifications are turned off for the app, in Focus modes that suppress delivery, or when the system kills the
extension for exceeding its time or memory budget. "Opened" is only seen when the user taps the notification itself.

## Android

Received: `FirebaseMessagingService.onMessageReceived`. Opened: the intent behind the notification tap.

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

Caveats (Android): with a `notification` message (not `data`) delivered while the app is in the background, FCM shows the
notification itself and `onMessageReceived` is **not** called, so "received" is not observable (use a data message that
you display yourself, or accept the gap); the tap then opens your launcher activity with the data in the intent extras.
Battery optimisation, "restricted" app standby buckets, force-stop and a user who disabled the notification channel all
hide pushes from your code.

## Strong attribution (optional)

The marker is the capability, so the route has no auth middleware by default: the app can report from an extension that
has no user session. If you need a report tied to an authenticated installation, add your own middleware and send the
auth header from the app:

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
`PushOpenedReported` (they carry the scope, effect id and delivery id, never the token or the marker). Note that a
Notification Service Extension usually cannot reach the app's session; for it, the marker alone is what you have.

## Sending side

Plug the issuer into the send options so each individual destination gets a marker:

```php
$options = $options->withReceiptMarker(app(ReceiptMarkerIssuer::class)->callback($scope));
```

and send each delivery under the message id the ledger assigned to it at insert time: pass
`ClaimedDelivery::providerMessageId()` as the `SendItem` `providerMessageId`, so a delivery whose outcome stays
unknown can still be matched by its marker.
Requires `mobile-push.delivery.profile = durable` and `mobile-push.receipts.enabled = true`.
