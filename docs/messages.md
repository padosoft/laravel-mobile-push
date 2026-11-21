# Messages

What you can put in a push, what it becomes on iOS and on Android, and the limits the package enforces. How to address and send it is in [sending.md](sending.md).

There are three kinds of message, all implementing `Padosoft\MobilePush\Core\Message\Message`:

| Class | Use it for |
|---|---|
| `Core\Message\PushMessage` | The unified message: one object for iOS and Android. Start here. |
| `Core\Apns\Message\ApnsMessage` | Every APNs push type and `aps` key, when you send to iOS only (background, VoIP, widgets, controls, location, MDM, ...). |
| `Core\Fcm\Message\FcmMessage` | The FCM HTTP v1 message model (`android`, `android_v2`, `apns` block for iOS apps on FCM), when you send to FCM only. |
| `Core\Apns\LiveActivity\LiveActivityMessage` | Live Activity `start` / `update` / `end`. Usually built for you by the Live Activities module: see [live-activities.md](live-activities.md). |

Runnable examples on this page are executed under `MobilePush::fake()`. The ones that call `MobilePush::fakeTransport()` switch to the bytes fake, so the real compilers run and the example checks the exact JSON that would go on the wire.

## The unified `PushMessage`

<!-- example: messages-unified -->
```php
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$message = PushMessage::make('Order shipped', 'Arrives Friday')
    ->data(['order_id' => '42'])
    ->badge(3)
    ->sound()                         // "default"
    ->image('https://cdn.example.com/parcel.png')
    ->deepLink('myshop://orders/42')
    ->actionCategory('ORDER')
    ->group('orders')
    ->collapse('order-42')
    ->priority(MessagePriority::High)
    ->ttl(3600)
    ->policy('orders');

MobilePush::to($user)->send($message)->counts();
```

`PushMessage` is a plain serializable object; every setter returns the same instance. Field by field:

| Method | iOS (APNs) | Android (FCM) |
|---|---|---|
| `make(?title, ?body)`, `title()`, `body()` | `aps.alert.title`, `aps.alert.body` | `notification.title`, `notification.body` |
| `subtitle()` | `aps.alert.subtitle` | no equivalent: `UnsupportedCapabilityException`, or dropped with a warning under `lenient()` |
| `data(array)` | custom top-level keys next to `aps` (values as given) | `data` map; values are stringified (`true` becomes `"true"`), `null` values are dropped with a warning, reserved keys (`from`, `message_type`, `google.*`, `gcm.*`) are rejected |
| `badge(?int)` | `aps.badge` | `android.notification.notification_count` |
| `incrementBadge()` | the server-side badge counter picks the number (policies module, see [policies.md](policies.md)) | same |
| `sound(?string = 'default')` | `aps.sound` | `android.notification.sound` |
| `image(url)` | custom key `image_url` + `mutable-content: 1`; your Notification Service Extension must download it | `notification.image` |
| `deepLink(url)` | custom key `deep_link` | `data.deep_link` |
| `actionCategory()` | `aps.category` | `android.notification.click_action` |
| `group()` | `aps.thread-id` | `android.notification.tag` |
| `collapse(key)` | `apns-collapse-id` header | `android.collapse_key` |
| `priority(MessagePriority)` | `apns-priority` 10 (`High`, default) or 5 (`Normal`) | `android.priority` `HIGH` / `NORMAL` |
| `expiresAt()` / `ttl()` | `apns-expiration` | `android.ttl` (remaining seconds, capped at 4 weeks) |
| `silent()` | push type `background`, `content-available: 1`, priority 5 | data-only message, plus an `apns` block (`apns-push-type: background`, priority 5, `content-available`) so an iOS app on FCM also wakes |
| `critical()` | critical sound dictionary + `interruption-level: critical` (needs Apple's entitlement) | no equivalent (as for `subtitle()`) |
| `timeSensitive()` | `interruption-level: time-sensitive` | no equivalent (as for `subtitle()`) |
| `mutableContent()` | `aps.mutable-content: 1` | no equivalent (as for `subtitle()`) |
| `policy()` | never sent: the bucket used by consent and the frequency cap | never sent |
| `replaceable()` | never sent: a later copy replaces this one, so an unknown outcome may be retried | same |
| `bypassFrequencyCap()` | never sent: skips the frequency cap (never consent) | same |
| `truncate()` | opt-in: shorten title/subtitle/body to fit the limit | opt-in: shorten title/body |
| `lenient()` | drop what a platform cannot carry, with a warning, instead of failing | same |

Rules the compilers enforce (an `InvalidMessageException` or `UnsupportedCapabilityException`, thrown before the network):

- A `silent()` message cannot carry a title, subtitle, body, sound, badge, `critical()` or `timeSensitive()` (on FCM also no image, action category or group). Under `lenient()` they are dropped with a warning.
- `critical()` and `timeSensitive()` are mutually exclusive.
- An APNs alert push needs an alert, a badge or a sound.
- A custom data key may not be `image_url` or `deep_link` when you also use `image()` / `deepLink()` (the compiler writes those keys).

**One message, two platforms.** iOS-only fields (`subtitle`, `critical`, `timeSensitive`, `mutableContent`) make the FCM compiler refuse the message. When you send to a user who has an iPhone and an Android phone, either add `->lenient()` or put the iOS-only part in the APNs overlay below, which FCM never sees:

<!-- example: messages-two-platforms -->
```php
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::to($user)->send(
    PushMessage::make('Order shipped', 'Arrives Friday')
        ->apns(fn (ApnsMessage $m) => $m->subtitle('Order #42'))
)->counts();

$wire->assertApnsRequest(fn (array $headers, array $payload) => $payload['aps']['alert']['subtitle'] === 'Order #42');
$wire->assertFcmRequest(fn (array $message) => ! isset($message['notification']['subtitle']));
```

**Where a warning goes.** Without `lenient()`, a field FCM cannot carry is refused before the network: a send whose only
connection is FCM throws `UnsupportedCapabilityException`; in a send that also reaches an iPhone, the Android
destinations are `Rejected` with `payload_invalid` and the iPhone gets the subtitle (see the
[pre-flight](sending.md#multi-connection-sends-and-the-pre-flight)). With `lenient()` the field is dropped for the
platform that cannot carry it, the push is sent, and the warning is on **that destination's result**, in
`$result->meta['warnings']` (several warnings joined by ` | `). The same `meta` reaches `PushResultProduced` listeners
(`$event->result->meta`). Warnings of truncation (`truncate()`) and of the other compiler adjustments (a TTL capped at
four weeks, a dropped null data key) arrive the same way. `mobile-push:preview` prints them without sending.

<!-- example: messages-lenient-warnings -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

$result = MobilePush::toToken(Platform::Android, $androidToken)
    ->send(PushMessage::make('Order shipped')->subtitle('Order #42')->lenient())
    ->retained()[0];

// The push went out without the subtitle, and the result says why.
$wire->assertFcmRequest(fn (array $message) => ! isset($message['notification']['subtitle']), 1);
expect($result->meta['warnings'])->toContain('Dropped iOS-only capability "subtitle"');
```

## Platform overlays: `->apns()` and `->fcm()`

`PushMessage::apns(callable)` and `PushMessage::fcm(callable)` give you the native message of each platform. The callback runs **immediately** against an empty overlay (`ApnsMessage::overlay()`, `FcmMessage::overlay()`); only what you set on it is laid on top of the message compiled from the unified fields, so the overlay wins where both set a value. Overlays are stored as data, never as closures, so the message stays serializable for queues.

<!-- example: messages-overlays -->
```php
use Padosoft\MobilePush\Core\Apns\InterruptionLevel;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

$message = PushMessage::make('Your rider is close', '2 minutes away')
    ->apns(fn (ApnsMessage $m) => $m
        ->interruptionLevel(InterruptionLevel::TimeSensitive)
        ->relevanceScore(0.9)
        ->targetContentId('order-42'))
    ->fcm(fn (FcmMessage $m) => $m
        ->android(fn (AndroidConfig $a) => $a
            ->notification(fn (AndroidNotification $n) => $n->channelId('deliveries')->color('#FF6600')))
        ->analyticsLabel('rider-close'));

MobilePush::to($user)->send($message)->counts();

$wire->assertApnsRequest(fn (array $headers, array $payload) => $payload['aps']['interruption-level'] === 'time-sensitive');
$wire->assertFcmRequest(fn (array $message) => $message['android']['notification']['channel_id'] === 'deliveries');
```

What the overlays offer:

- **`ApnsMessage`** (overlay or standalone): `pushType(PushType)`, `priority(Priority)`, `collapseId()`, `topic()` (explicit `apns-topic`), `withAlert(Alert)`, `title()`, `subtitle()`, `body()`, `badge()`, `sound(Sound|string)`, `critical(soundName, volume)`, `threadId()`, `category()`, `contentAvailable()`, `mutableContent()`, `targetContentId()`, `interruptionLevel(InterruptionLevel)`, `relevanceScore()`, `filterCriteria()`, `contentChanged()`, `custom(key, value)`, `data(array)`, `truncate()`. `Alert::localized($locKey, $args)` and `withTitleLocalization()` / `withSubtitleLocalization()` / `withBodyLocalization()` build localized alerts; `Sound::named()` / `Sound::critical()` build sounds.
- **`FcmMessage`** (overlay or standalone): `title()`, `body()`, `image()`, `data()`, `android(AndroidConfig|callable)`, `androidV2(AndroidConfigV2|callable)`, `apns(FcmApnsConfig|callable)` (the APNs block for iOS apps reached through FCM: `header()`, `headers()`, `payload()`, `liveActivityToken()`, `image()`, `analyticsLabel()`), `analyticsLabel()`, `truncate()`.
- **`AndroidConfig`** (`android`): `collapseKey()`, `priority(AndroidPriority)`, `ttl()`, `restrictedPackageName()`, `data()`, `notification(AndroidNotification|callable)`, `analyticsLabel()`, `directBootOk()`, `bandwidthConstrainedOk()`, `restrictedSatelliteOk()`.
- **`AndroidNotification`**: `title()`, `body()`, `icon()`, `color()`, `sound()`, `tag()`, `clickAction()`, `titleLocKey()` / `titleLocArgs()`, `bodyLocKey()` / `bodyLocArgs()`, `channelId()`, `ticker()`, `sticky()`, `eventTime()`, `localOnly()`, `notificationPriority(NotificationPriority)`, `defaultSound()`, `defaultVibrateTimings()`, `vibrateTimings()`, `defaultLightSettings()`, `lightSettings(LightSettings)`, `visibility(NotificationVisibility)`, `notificationCount()`, `image()`, `proxy(NotificationProxy)`, `bypassProxyNotification()`, `id()` (`id` exists only in `android_v2`, `proxy` / `bypassProxyNotification` only in `android`).

### `android` or `androidV2`

FCM HTTP v1 has two Android blocks, `android` and the newer `android_v2` (modelled on the Discovery document revision 20261112 that the package pins in its tests). They are **mutually exclusive**: setting both throws `InvalidMessageException`. When the FCM overlay of a `PushMessage` uses `androidV2()`, the unified fields are mapped into `android_v2` instead of `android`. `android_v2` has no priority field: `High` needs nothing, `Normal` is reported as a warning and not applied; `silent()` becomes `background_sync`. `AndroidConfigV2` adds `mutableContent()`, `useAsV1DataMessage()` and `backgroundSync()`.

<!-- example: messages-android-v2 -->
```php
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfigV2;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::toToken(Platform::Android, $androidToken)->send(
    PushMessage::make('Hello', 'From android_v2')
        ->fcm(fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $v2) => $v2->collapseKey('greeting')))
)->counts();

$wire->assertFcmRequest(fn (array $message) => isset($message['android_v2']) && ! isset($message['android']));
```

## Native messages

Send an `ApnsMessage` to an APNs destination or an `FcmMessage` to an FCM destination when you need something the unified message does not model. A native message sent through the other provider's connection is refused before the network.

`ApnsMessage` factories: `alert(?title, ?body, ?subtitle)`, `background()` (content-available, priority 5), `voip(array $data)` (to an `ApnsVoipToken`; 5120-byte limit), `widgetsReload()`, `controlsReload()`, `complication()`, `fileProvider()`, `location()`, `mdm(string $topic)`, `pushToTalk()`, `accessory()`. The `apns-topic` is derived from the bundle id and the push type (for example `.voip`, `.push-type.liveactivity`), unless you set `topic()`.

<!-- example: messages-native-apns -->
```php
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(ApnsMessage::background()->data(['sync' => 'inbox']))
    ->counts();

$wire->assertApnsRequest(fn (array $headers, array $payload) => $headers['apns-push-type'] === 'background'
    && $headers['apns-priority'] === '5'
    && $payload['aps']['content-available'] === 1
    && $payload['sync'] === 'inbox');
```

FCM targets: a registration token (`FcmToken`), a Firebase installation id (`FcmFid`, 22 characters), a topic (`FcmTopic`) or a condition (`FcmCondition`). The request carries exactly one of `token`, `fid`, `topic`, `condition`, taken from the destination type; the package never converts one into another.

<!-- example: messages-native-fcm -->
```php
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidPriority;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::to(FcmFid::of('cXr4bW0rZ2RlbW9fZmlkXy'))->send(
    FcmMessage::make('Sync done', '3 new files')
        ->data(['folder' => 'inbox'])
        ->android(fn (AndroidConfig $a) => $a->priority(AndroidPriority::Normal)->ttl(600))
)->counts();

$wire->assertFcmRequest(fn (array $message) => $message['fid'] === 'cXr4bW0rZ2RlbW9fZmlkXy'
    && $message['android']['priority'] === 'NORMAL');
```

## Live Activity messages

`LiveActivityMessage::start($type, $attributes, $contentState, $alert)`, `::update($contentState)` and `::end(?$finalContentState)` build the three Live Activity events, with `alert()`, `sound()`, `staleAt()`, `dismissAt()`, `relevanceScore()`, `priority()`, `inputPushToken()`, `inputPushChannel()`, `channelStoresNoMessages()` and `truncate()`. Updates and ends are *replaceable* (a later one supersedes an earlier one), so an unknown outcome of an update may be retried; a `start` is not. You can send one directly to an `ApnsLiveActivityToken` (update/end), an `ApnsPushToStartToken` (start) or an `ApnsBroadcastChannel` (update/end), but the Live Activities module also keeps the activity registry, serializes transitions and never invents a timestamp: prefer it ([live-activities.md](live-activities.md)).

<!-- example: messages-live-activity -->
```php
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::to(ApnsLiveActivityToken::of($iosToken))
    ->send(LiveActivityMessage::update(['etaMinutes' => 5])->staleAt(now()->addMinutes(10)))
    ->counts();

$wire->assertApnsRequest(fn (array $headers, array $payload) => $headers['apns-push-type'] === 'liveactivity'
    && $payload['aps']['event'] === 'update'
    && $payload['aps']['content-state']['etaMinutes'] === 5);
```

## Size limits and truncation

| Target | Limit | How it is counted |
|---|---|---|
| APNs device, Live Activity, push-to-start | 4096 bytes | the encoded JSON body |
| APNs VoIP | 5120 bytes | the encoded JSON body |
| APNs broadcast channel | 5120 bytes | the encoded JSON body |
| FCM token / FID | 4096 bytes | see below |
| FCM topic / condition | 2048 bytes | see below |

The size is checked **after** the overlays, the encoding and the receipt marker (`mp_r`, when receipts are on) are applied. FCM counting rule used by the package: the sum of `strlen(key) + strlen(value)` of the string entries of `notification`, `data`, `android(.v2).data` and the title/body/image of the Android notification; no JSON punctuation, no target and no envelope. **Google publishes the two numbers but not the exact counting rule**, so this rule is the package's conservative reading and is not verified against the provider.

Over the limit, the destination ends `Rejected` with `payload_too_large` (nothing is sent to it). With `->truncate()` the package shortens **only** the title, subtitle and body, on whole grapheme clusters (emoji, flags and combining marks are never split; `ext-intl` is used when present, a PCRE fallback otherwise), with the ellipsis counted, and records a warning. Ids, URLs, custom data and Live Activity content state are never shortened: if they alone exceed the limit, truncation cannot help and the result is still `payload_too_large`.

<!-- example: messages-truncate -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();
$long = str_repeat('Very long text. ', 400);   // about 6.4 KB

$rejected = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('News', $long))->retained()[0];
// $rejected->reason->code === ReasonCode::PayloadTooLarge: nothing was sent

MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('News', $long)->truncate())->counts();
$wire->assertApnsRequest(fn (array $headers, array $payload) => str_ends_with($payload['aps']['alert']['body'], '…'), 1);
```

## Expiry: `expiresAt()`, `ttl()` and "now or never"

- `expiresAt(DateTimeInterface)` sets an absolute logical expiry; `ttl(int $seconds)` a relative one, pinned to an absolute instant when the send (or the queued effect) is created. Each later one replaces the other. `PendingSend::expiresAt()` overrides both for one send.
- Every attempt recomputes the APNs `apns-expiration` and the FCM remaining `ttl` from the current time, so retries never extend the life of a message.
- Once the expiry has passed, nothing more is sent: the destination ends `Expired`.
- **`ttl(0)` means "now or never"**: exactly one attempt, `apns-expiration: 0` and FCM `ttl: "0s"` (the provider does not store it for an offline device), and no retry of any kind, failover included. `isNowOrNever()` tells you whether a message is in this mode.
- Without an expiry, the provider's own default storage applies, and the package's retry budget (`retry.max_elapsed`, default 3600 s) bounds the retries. See [retries-and-limits.md](retries-and-limits.md).

<!-- example: messages-now-or-never -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Incoming call', 'Anna is calling')->ttl(0))
    ->counts();

$wire->assertApnsRequest(fn (array $headers) => $headers['apns-expiration'] === '0');
```

## The capability matrix

`Padosoft\MobilePush\Core\Policy\CapabilityMatrix` records, per unified capability, whether each platform supports it natively, needs code in your app, or does not have it. The table below is the output of `(new CapabilityMatrix)->toMarkdown()`:

| Capability | iOS (APNs) | Android (FCM) |
|---|---|---|
| `title` | native — aps.alert.title | native — notification.title |
| `subtitle` | native — aps.alert.subtitle | unavailable — no FCM field |
| `body` | native — aps.alert.body | native — notification.body |
| `image` | requires app code — Notification Service Extension downloads and attaches the image (mutable-content) | native — notification.image |
| `deep_link` | requires app code — handle the custom payload key when the notification is opened (unverified convention) | requires app code — intent filter / tap handler reads the data key |
| `actions` | requires app code — register the UNNotificationCategory the payload names | requires app code — FCM has no action buttons: build them in a data-message handler |
| `badge` | native — aps.badge (the app may still need to clear it) | native — notification.notification_count (unverified) |
| `sound` | native — aps.sound | native — notification.sound (resource name or "default") |
| `critical` | native — interruption-level critical; needs Apple's critical-alerts entitlement | unavailable — use a high-importance notification channel in the app |
| `time_sensitive` | native — interruption-level time-sensitive; needs the entitlement | requires app code — use a high-importance notification channel (unverified) |
| `silent` | native — content-available=1, push type background | native — data-only message |
| `group` | native — aps.thread-id | requires app code — group key set when the app builds the notification (unverified) |
| `collapse` | native — apns-collapse-id header | native — android.collapse_key |
| `mutable_content` | native — aps.mutable-content=1 | unavailable — no equivalent |
| `expiry` | native — apns-expiration header | native — android.ttl |
| `data` | native — custom top-level keys, counted in the 4 KB payload | native — data map, string values only |
| `live_activity` | requires app code — declare ActivityAttributes and the widget UI; the push starts/updates/ends it | unavailable |
| `broadcast` | requires app code — app subscribes to the channel with ActivityKit (iOS 18+) | native — topics (closest equivalent, unverified) |

The author of the matrix marked five cells as **not re-verified** against the current provider documentation: Android `badge` (`notification_count`), Android `group`, Android `time_sensitive`, the iOS `deep_link` convention, and Android `broadcast` (FCM topics as the closest equivalent of an APNs broadcast channel). "Requires app code" means the push alone does nothing visible without code you write in the iOS/Android app.

The matrix is a reference: the compilers enforce the "unavailable" cells themselves (see the rules above). Other methods: `support($capability, Platform)`, `capabilities()`, `unavailableOn(Platform)`.

## Checking a message locally

`php artisan mobile-push:preview --connection=ios --title="Order shipped" --body="Arrives Friday" --data=order=42` prints the headers and the JSON a message compiles to, without sending or validating anything remotely (no credential, token or topic is checked). Output of that command on a test connection:

```
   INFO  Preview for APNS using connection "ios" (apns, app com.example.shop, sandbox).

Local preview only: nothing was sent and nothing was validated remotely (no credential, token or topic was checked). APNs has no dry run; FCM can validate remotely with mobile-push:send --validate-only.

Destination  <APNs device token placeholder>
Headers (credentials are added at send time and never shown)
  apns-push-type: alert
  apns-topic: com.example.shop
  apns-priority: 10

Payload
{
    "aps": {
        "alert": {
            "title": "Order shipped",
            "body": "Arrives Friday"
        }
    },
    "order": "42"
}

Size 80 of 4096 bytes (2%)
```

`mobile-push:simulator` writes the same payload as an `.apns` file for the iOS Simulator (it checks rendering only, not APNs or your credentials). Both are described in [commands.md](commands.md).
