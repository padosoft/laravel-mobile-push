---
title: "Laravel notifications and fallback"
description: "The package registers three notification channels, push, apns and fcm, so a push is just another channel of a Laravel notification."
---

# Laravel notifications and fallback

The package registers three notification channels, `push`, `apns` and `fcm`, so a push is just another channel of a
Laravel notification. A notification can also opt in to a **fallback** channel (mail, SMS, ...) for the cases where the
push is *known* not to have been accepted.

| | |
|---|---|
| Enable | always on: the channels are registered with the package; nothing to switch |
| Fallback switch | `mobile-push.fallback.enabled` (env `MOBILE_PUSH_FALLBACK_ENABLED`, default `true`). A notification still has to opt in with `pushFallback()` |
| Migrations | none for the channels. Package-level queueing uses the queue; the durable profile needs the ledger migrations (`--tag=mobile-push-migrations-ledger`), see [queues and Horizon](/reliability/queues-and-horizon) |

Related pages: [sending](/sending/sending), [messages](/sending/messages), [devices](/modules/devices), [policies](/modules/policies),
[outcomes](/reliability/outcomes), [testing](/sending/testing), [queues and Horizon](/reliability/queues-and-horizon).

What was not verified: the channels are tested with Laravel 12/13 Testbench and the fake driver, with a mail fallback
captured by the `array` mailer. Nothing was sent to real providers.

## The three channels

| Channel | Message method | Destinations used |
|---|---|---|
| `push` | `toPush($notifiable)` | every destination (iOS and Android) |
| `apns` | `toApns($notifiable)`, falling back to `toPush()` | iOS only (APNs destinations and APNs connections) |
| `fcm` | `toFcm($notifiable)`, falling back to `toPush()` | Android only (FCM destinations and FCM connections) |

The message methods return a `PushMessage` or a native message (`ApnsMessage`, `FcmMessage`); anything else throws an
`InvalidMessageException`. A notification with none of the needed methods throws one too, naming the channel.

```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Message\PushMessage;

final class NotificationsDocOrderShipped extends Notification
{
    public function __construct(private readonly string $orderId) {}

    public function via(mixed $notifiable): array
    {
        return ['push'];
    }

    public function toPush(mixed $notifiable): PushMessage
    {
        return PushMessage::make('Your order shipped', 'Order #'.$this->orderId)
            ->data(['order_id' => $this->orderId])
            ->deepLink('https://example.com/orders/'.$this->orderId);
    }
}

$user->notify(new NotificationsDocOrderShipped('1042'));

$fake->assertSentTimes(2);   // one iOS destination, one Android destination of $user
```

Different content per platform, using the two provider channels:

```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;

final class NotificationsDocPlatformSpecific extends Notification
{
    public function via(mixed $notifiable): array
    {
        return ['apns', 'fcm'];
    }

    public function toApns(mixed $notifiable): ApnsMessage
    {
        return ApnsMessage::alert('Hello', 'iOS flavour')->threadId('orders');
    }

    public function toFcm(mixed $notifiable): PushMessage
    {
        return PushMessage::make('Hello', 'Android flavour');
    }
}

$user->notify(new NotificationsDocPlatformSpecific);

$fake->assertSentTimes(2);   // the apns channel sent the iOS push, the fcm channel the Android one
```

## Who gets the push: routing

For each notifiable the channel looks, in this order:

1. `routeNotificationForPush($notification)`: returns typed destinations (`ApnsDeviceToken::of()`, `FcmToken::of()`, ...) or
   `SendItem`s. **Raw strings are refused** (`InvalidDestinationException`): the platform is never guessed. This wins over
   the registry.
2. The device registry: a model using `HasPushDevices` ([devices](/modules/devices)); registry devices only, active ones.
3. For on-demand notifications, the route given with `Notification::route('push', $destinations)`.

A notifiable without any destination produces an **empty report, not an error**. The `apns` and `fcm` channels keep only
the destinations (and the connections) of their provider.

## Hooks a notification can define

All optional. Each is a plain method (the interfaces exist so you can type-hint, but a method is enough).

| Hook (interface) | Purpose |
|---|---|
| `pushConnection($notifiable): ?string` (`HasPushConnection`) | send through this connection. On the `apns` / `fcm` channels it must be a connection of that provider, else a `ConfigurationException`. `null` = route by provider and by the connection each registered device belongs to |
| `pushScope($notifiable): ?string` (`HasPushScope`) | the tenant scope; `null` = the current scope |
| `pushEffectKey($notifiable): ?string` (`HasPushEffectKey`) | the logical effect key (see below) |
| `pushOptions(PendingSend $pending, $notifiable): PendingSend` (`CustomizesPendingPush`) | last-moment customisation of every `PendingSend` the channel builds (`bulk()`, `expiresAt()`, `withGate()`, ...). Must return the `PendingSend` |
| `pushFallback($notifiable): array` (`HasPushFallback`) | channels to fall back to, e.g. `['mail']`; `[]` opts out for this notifiable |
| `pushQueue($notifiable): bool\|array` , or the marker `QueuesPush` | hand the push to the package queue (below) |
| `routeNotificationForPush($notification)` (on the **notifiable**) | typed destinations, as above |

```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Sending\PendingSend;

final class NotificationsDocInvoicePaid extends Notification
{
    public function __construct(private readonly int $invoiceId) {}

    public function via(mixed $notifiable): array
    {
        return ['apns'];
    }

    public function toApns(mixed $notifiable): PushMessage
    {
        return PushMessage::make('Invoice paid', '#'.$this->invoiceId)->policy('billing');
    }

    public function pushConnection(mixed $notifiable): ?string
    {
        return 'ios';                                  // an APNs connection of your config
    }

    public function pushEffectKey(mixed $notifiable): ?string
    {
        return 'invoice-paid:'.$this->invoiceId;       // stable business key
    }

    public function pushOptions(PendingSend $pending, mixed $notifiable): PendingSend
    {
        return $pending->transactional();
    }
}

$user->notify(new NotificationsDocInvoicePaid(77));

$fake->assertSentOnConnection('ios');
```

## The effect key, and what a queue retry does

Every push has an **effect key**: the logical "this notification for this person". Provider message ids are derived
from it, so retrying the same effect reuses the same `apns-id`; in the durable profile it also deduplicates inside a
window (`ledger.dedupe_seconds`). The default (`EffectKeys::forPush`) is `n:&lt;notification id&gt;:<hash of notifiable class
and key>`.

Laravel assigns a notification its id **once per `notify()` call** and serialises it into the queued job, so the retries
of a queued notification reuse the same effect key and the same provider message ids. With a direct `sendNow()` the id
is new on every call, and a notification without an id (a channel called by hand) gets **no effect key and therefore no
idempotency**. Define `pushEffectKey()` when a business key is available: it deduplicates across notification instances.

## Who owns queueing

There is exactly one owner of queueing, never two jobs for one push:

| Your notification | What happens |
|---|---|
| implements `ShouldQueue` | Laravel's `SendQueuedNotifications` job is the owner (one job **per channel**). The push is sent **inline inside that job**; the channel never calls `PendingSend::queue()`. The report is consumed and becomes the `response` of `NotificationSent` |
| not queued, implements `QueuesPush` (or `pushQueue()` returns `true` or an options array) | the channel hands the push to the **package queue** (`PendingSend::queue()`), nothing is sent inline, the returned report is **empty** and **no fallback is evaluated** (the outcome is not known yet) |
| neither | sent inline (synchronously) |

`pushQueue($notifiable)` may return an array with `connection` (queue connection), `queue` (queue name), `delay`
(seconds or `DateTimeInterface`) and `after_commit` (bool). Package-level queueing uses the profile you configured:
light profile = encrypted `SendPushJob`s, durable profile = the ledger and its relay ([queues and Horizon](/reliability/queues-and-horizon)).

Pick `ShouldQueue` when you want Laravel's retry/backoff semantics and the fallback; pick `QueuesPush` when you want the
durable ledger, campaigns-style chunking and the receipts, and accept that the fallback cannot be evaluated by the channel.

## Fallback to another channel

Fallback is opt-in per notification and **strict**: it fires only when the push is *known* not to have been accepted for
that person. A person who may have received the push never also gets the email.

```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Message\PushMessage;

class SecurityCodeChanged extends Notification
{
    public function via($notifiable): array
    {
        return ['push'];
    }

    public function toPush($notifiable): PushMessage
    {
        return PushMessage::make('Security code changed', 'Was this you?');
    }

    public function pushFallback($notifiable): array
    {
        return ['mail'];        // channels named push, apns and fcm are ignored
    }

    public function toMail($notifiable) { /* ... */ }
}
```

The order is: opt-in (`pushFallback`) -> the rules below -> the permission callback -> an idempotency claim in the cache ->
`Notification::sendNow()` for that channel alone, on a clone of the notification with its own deterministic id.

### Decision table

`FallbackRules` evaluates the results of one push effect for one notifiable, in this order; the first match wins.

| # | Situation | Fallback? | Why |
|---|---|---|---|
| 1 | no result at all (no eligible destination) | **yes**, unless `fallback.when_no_destination = false` | nothing could be pushed |
| 2 | any topic, condition or broadcast destination | never | unknown audience |
| 3 | any `Deferred` | no, not decided | a retry is planned |
| 4 | any `Accepted` or `Validated` | no | the person may have the push |
| 5 | any `OutcomeUnknown` | no | the provider may have accepted it; an unknown outcome is not a failure |
| 6 | any `Skipped` by consent/eligibility (`PolicyIneligible`) or by a frequency cap (`PolicyFrequencyCapped`) | no | suppressed on purpose; a cap must not be bypassed through mail, and an opt-out is not a failure |
| 7 | any `Simulated` | no | nothing real was attempted |
| 8 | at least one `Rejected`, `Failed` or `Expired` | **yes** | known not accepted |
| 9 | otherwise (only other skips, e.g. safe mode or an open circuit) | no | nothing known to have been refused |

Reasons are exposed as `FallbackReason` (`no_destination`, `not_accepted`, `no_destination_disabled`, `unknown_audience`,
`undecided`, `accepted`, `outcome_unknown`, `suppressed_by_policy`, `simulated`, `nothing_known_refused`).

### The permission callback

A permission to push is not a permission to email. Register one or more callbacks (typically in a service provider);
each must return **strictly `true`** for the channel to be used; with none registered the answer is "allowed". It runs
after the push outcome justified a fallback and before anything is sent.

```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Laravel\Notifications\FallbackFacts;
use Padosoft\MobilePush\Laravel\Notifications\FallbackPolicy;
use Padosoft\MobilePush\Laravel\Notifications\FallbackRules;

app(FallbackPolicy::class)->allowUsing(
    fn (object $notifiable, Notification $notification, string $channel): bool => $channel !== 'sms',
);

$policy = app(FallbackPolicy::class);
$notification = new class extends Notification {};

// The decision table, evaluated on counters: a rejected push justifies a fallback, an unknown outcome never does.
$rejected = FallbackRules::decide(FallbackFacts::of(['rejected' => 1]));
$unknown = FallbackRules::decide(FallbackFacts::of(['outcome_unknown' => 1]));

expect($rejected->fallback)->toBeTrue()
    ->and($unknown->fallback)->toBeFalse()
    ->and($policy->allows($user, $notification, 'mail'))->toBeTrue()
    ->and($policy->allows($user, $notification, 'sms'))->toBeFalse();

$policy->flush();
```

### Idempotency

The evaluator claims `fb:<hash of effect key and channel>` in the cache with `Cache::add()` (store:
`fallback.cache_store`, TTL `fallback.idempotency_ttl`, default 86400 seconds). Calling it twice for the same
notification and channel sends nothing the second time. If the fallback send throws, the claim is released so a retry may
try again. A notification without an effect key has no claim and is therefore not idempotent. When it fires, a
`PushFellBack` event is dispatched (notifiable type and id, notification class and id, channels, reason, status counters;
no token, no content). `FallbackOutcome` (returned by `FallbackEvaluator::evaluate()`) lists `dispatched` channels and
`withheld` ones (`policy_denied`, `already_sent`).

Configuration:

| Key | Env | Default |
|---|---|---|
| `fallback.enabled` | `MOBILE_PUSH_FALLBACK_ENABLED` | `true` |
| `fallback.when_no_destination` | `MOBILE_PUSH_FALLBACK_WHEN_NO_DESTINATION` | `true` |
| `fallback.idempotency_ttl` | `MOBILE_PUSH_FALLBACK_IDEMPOTENCY_TTL` | `86400` |
| `fallback.cache_store` | `MOBILE_PUSH_FALLBACK_CACHE_STORE` | `null` (the package cache store) |

### When a fallback never fires, and the durable-profile gap

- The channel only evaluates the fallback after an **inline** send: either a plain `notify()` or a `ShouldQueue`
  notification (inline inside Laravel's job). The `QueuesPush` path returns an empty report and evaluates nothing.
- A `Deferred` result means "not decided": with the light profile **nothing calls the evaluator again**, so a failure
  that happens after a deferral never falls back.
- **Durable profile: not wired.** The package queue (`QueuesPush`, `queue()`) records the effect in the ledger. When every
  delivery of an effect reaches a final state, `Padosoft\MobilePush\Laravel\Integration\IntegrationModule` dispatches an
  `EffectSettled` event (scope, effect id, per-state counts, origin), once per effect. **Nothing in the package turns that
  event into a fallback**, and the channel does not store the original notification or fill the event's `origin`, so a
  listener cannot rebuild the notification from the event alone. If you need a fallback for durable sends, either use a
  `ShouldQueue` notification (the fallback is then evaluated inline), or keep your own record keyed by the effect key
  (`EffectKeys::forPush($notifiable, $notification)`) and call
  `app(FallbackEvaluator::class)->evaluate($user, $notification, $results, $effectKey)` from your `EffectSettled` listener
  with `DeliveryStore::results($scope, $effectId)`. An `OutcomeUnknown` result never allows a fallback.
- `IntegrationModule` (registered by the service provider) is what dispatches `EffectSettled`: after every
  `RelayDeliveriesJob` of an effect in the durable profile it checks whether the effect settled and announces it once.

## Consent and eligibility checks

The fallback permission callback decides who may be contacted on the *other* channel. For the push itself, register your
consent or eligibility check once with `MobilePush::eligibleUsing()`; it is evaluated immediately before the network, for
every destination of every send, and a `false` answer is a `Skipped` result with `PolicyIneligible` (which, per row 6 of
the table, never triggers a fallback). See [policies](/modules/policies) and [devices](/modules/devices).

---

*This page is generated from [`docs/notifications-and-fallback.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/notifications-and-fallback.md). To change it, edit that file in the repository, not this site.*
