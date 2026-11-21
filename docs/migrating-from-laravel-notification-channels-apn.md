# Migrating from `laravel-notification-channels/apn`

`laravel-notification-channels/apn` is the community Laravel notification channel for APNs. It is a thin, tidy wrapper
around [Pushok](migrating-from-pushok.md): you return an `ApnMessage` from `toApn()`, the channel adapts it to a Pushok
`Notification` and sends it. If that is your setup, this page maps it to `padosoft/laravel-mobile-push` and lists what
behaves differently.

**What I read.** The README, `src/ApnMessage.php`, `src/ApnChannel.php` and `composer.json` at tag **`v6.5.0`** (the latest
tag listed by `git ls-remote --tags https://github.com/laravel-notification-channels/apn`), fetched from
`raw.githubusercontent.com`. I did not run it. At that tag it requires PHP `^8.4`, `edamov/pushok ^0.18|^0.19` and
`illuminate/* ^12.0|^13.0`; this package requires PHP 8.3+ and Laravel 12 to 13. For the Pushok side read the
[Pushok guide](migrating-from-pushok.md); everything about outcomes, configuration and the plan applies here too and is not
repeated in full.

Related pages: [notifications and fallback](notifications-and-fallback.md), [getting started](getting-started.md),
[messages](messages.md), [devices](devices.md), [credentials for APNs](credentials-apns.md), [outcomes](outcomes.md),
[testing](testing.md), [comparison](comparison.md), [upgrading](upgrading.md).

## Side-by-side

```php
// laravel-notification-channels/apn v6.5.0
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Apn\ApnMessage;

class AccountApproved extends Notification
{
    public function via($notifiable) { return [ApnChannel::class]; }

    public function toApn($notifiable)
    {
        return ApnMessage::create()
            ->badge(1)
            ->title('Account approved')
            ->body("Your {$notifiable->service} account was approved!");
    }
}

// the notifiable
public function routeNotificationForApn() { return $this->apn_token; }   // a string or an array of strings
```

```php
// This package
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;

class AccountApproved extends Notification
{
    public function via($notifiable) { return ['apns']; }              // or 'push' for iOS and Android

    public function toApns($notifiable)
    {
        return ApnsMessage::alert('Account approved', "Your {$notifiable->service} account was approved!")->badge(1);
    }
}

// the notifiable: typed destinations, never raw strings...
public function routeNotificationForPush($notification = null): array
{
    return [ApnsDeviceToken::of($this->apn_token)];
}
// ...or use the device registry (HasPushDevices) and write nothing here.
```

<!-- example: migrating-apn-notification -->
```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;

final class MigratingApnAccountApproved extends Notification
{
    public function via(mixed $notifiable): array
    {
        return ['apns'];
    }

    public function toApns(mixed $notifiable): ApnsMessage
    {
        return ApnsMessage::alert('Account approved', 'Your account was approved!')->badge(1);
    }
}

$user->notify(new MigratingApnAccountApproved);

$fake->assertSentTimes(1);   // the apns channel keeps the iOS destination only; $user also has an Android one
```

What changes in the notification class:

| apn channel | This package |
|---|---|
| `via()` returns `ApnChannel::class` | returns `'apns'` (iOS only), `'fcm'` (Android only) or `'push'` (every destination) |
| `toApn($notifiable)` returning `ApnMessage` | `toApns($notifiable)` returning an `ApnsMessage` (or a cross-platform `PushMessage`); `toPush()` is the fallback for all three channels |
| `routeNotificationForApn()` returning a string or array of strings | `routeNotificationForPush($notification)` returning **typed destinations** (`ApnsDeviceToken::of()`); a raw string is refused with an `InvalidDestinationException`. Or the device registry ([devices](devices.md)) |
| `ApnVoipChannel` / `toApnVoip()` / `ApnVoipMessage` / `routeNotificationForApnVoip()` | `ApnsMessage::voip($data)` sent to an `ApnsVoipToken::of()` destination (a VoIP token is a different token kind, typed explicitly) |
| `ApnLiveActivityMessage` | the Live Activities module: [live activities](live-activities.md). It has its own token registry and rules; it is not a notification |

## `ApnMessage` mapping

| `ApnMessage` (v6.5.0) | `ApnsMessage` / `PushMessage` |
|---|---|
| `ApnMessage::create()` / constructor `(title, body, custom)` | `ApnsMessage::alert($title, $body, $subtitle)`, or `PushMessage::make($title, $body)` |
| `title()`, `subtitle()`, `body()` | the factory arguments; `->title()`, `->subtitle()`, `->body()` |
| `badge($n)` | `->badge($n)` (and `PushMessage::incrementBadge()` for a server-side counter, see [policies](policies.md)) |
| `sound($nameOrSound)` | `->sound('default')` / `Sound::named()`, critical: `->critical()` |
| `interruptionLevel(...)` | `ApnsMessage::interruptionLevel(InterruptionLevel::...)` |
| `category($c)` | `ApnsMessage::category($c)` |
| `threadId($id)` | `ApnsMessage::threadId($id)` |
| `contentAvailable(1)` | `ApnsMessage::background()` (a silent push has priority 5) or `->contentAvailable()` |
| `mutableContent(1)` | `->mutableContent()` |
| `pushType(ApnMessagePushType::...)` | `ApnsMessage::pushType(PushType::...)` |
| `expiresAt($dateTime)` | `PushMessage::expiresAt()` / `ttl()` |
| `collapseId($id)` | `PushMessage::collapse($key)`, or `ApnsMessage::collapseId($id)` |
| `apnsId($uuid)` | not settable per message: provider ids are derived from the effect key (`pushEffectKey()` hook, see [notifications and fallback](notifications-and-fallback.md)) |
| `priority(ApnMessagePriority::...)` | `PushMessage::priority(MessagePriority::...)` or `ApnsMessage::priority(Priority::...)` |
| `titleLocKey()`, `titleLocArgs()`, `locKey()`, `locArgs()`, `actionLocKey()` | `Alert::localized()`, `withTitleLocalization()`, `withBodyLocalization()` ([messages](messages.md)); I found no equivalent of `action-loc-key` |
| `customAlert(...)` | build an `Alert`, or `ApnsMessage::withAlert()` |
| `custom([...])` | `PushMessage::data([...])` or `ApnsMessage::custom()` / `data()` |
| `urlArg()`, `urlArgs()` | no equivalent found |
| `via($pushokClient)` | the `pushConnection($notifiable)` hook or `->connection('name')`: one **named connection** per app, configured in `mobile-push.connections` |

## Configuration

The channel reads `config/broadcasting.php` (`connections.apn`). Here it is one entry of `config/mobile-push.php`:

| `broadcasting.connections.apn` | `mobile-push.connections.ios` |
|---|---|
| `key_id`, `team_id` | `key_id`, `team_id` |
| `app_bundle_id` | `bundle_id` |
| `private_key_path` / `private_key_content` | `key => ['path' => ...]` / `key => ['inline' => ...]` (or `base64`, or a `resolver`) |
| `private_key_secret` | no equivalent for a token key (see the [Pushok guide](migrating-from-pushok.md)) |
| `certificate_path`, `certificate_secret` | `auth => 'certificate'`, `certificate => ['path' => ...]`, `passphrase` (and `team_id`, required here) |
| `production` (bool) | `environment => 'production'` or `'sandbox'`, declared explicitly |

```php
'connections' => [
    'ios' => [
        'driver' => 'apns',
        'auth' => 'token',
        'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
        'key_id' => env('MOBILE_PUSH_APNS_KEY_ID'),
        'key' => ['base64' => env('MOBILE_PUSH_APNS_KEY_BASE64')],   // the base64 of the .p8 file: one line in .env
        'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),
        'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),
    ],
],
```

## Behavioural differences that matter for a channel user

All the points of the [Pushok guide](migrating-from-pushok.md#behavioural-differences-a-migrating-team-must-know) apply:
simulation by default outside production, explicit platform and environment, statuses instead of exceptions, an unknown
outcome that is not a failure, retries inside the package. In addition, specific to the notification channel:

1. **`NotificationFailed` is not dispatched.** The apn channel dispatches Laravel's `NotificationFailed` for every response
   that is not 200, carrying the APNs id, the **token** and the error reason. This package's channel does not dispatch that
   event: failures of single destinations are `SendResult`s. If you have a `NotificationFailed` listener that deletes tokens,
   replace it with the registry (automatic invalidation and quarantine) or with a listener on the package events
   (`DestinationInvalidated`, `DestinationQuarantined`, `PushResultProduced`, which carry a masked view of the destination,
   not the token), and see [observability](observability.md).
2. **What the channel returns.** The apn channel returns the Pushok responses (or `null` when there are no tokens). This one
   returns a `SendReport` that is consumed before the channel returns, and it becomes the `response` of `NotificationSent`.
   No destination at all is an empty report, not an error.
3. **Simulation shows up as "sent".** In `auto` mode on a non-production environment a notification goes through the channel
   and every result is `Simulated`; `NotificationSent` still fires. Check the results, or the execution mode, before
   concluding that a staging device should have received something ([safe mode](safe-mode.md)).
4. **Queueing has one owner.** If the notification implements `ShouldQueue`, Laravel's job owns it and the push is sent
   inline inside it (one job per channel). The package queue is a different, opt-in path (`QueuesPush`) and never runs
   together with `ShouldQueue` ([notifications and fallback](notifications-and-fallback.md)).
5. **Retries reuse the same message id.** A retried queued notification keeps its notification id, so the effect key and the
   provider message id are stable.
6. **Fallback to mail or SMS** is available on the push channel: `pushFallback()` returns the channels, and it fires only
   when the push is *known* not to have been accepted (never after an unknown outcome or an opt-out). See
   [notifications and fallback](notifications-and-fallback.md).

## A step-by-step plan, with a way back

1. **Inventory** the notifications that use `ApnChannel`/`ApnVoipChannel`, the `routeNotificationForApn*()` methods, any
   `NotificationFailed` listener and every `ApnMessage::via($client)`.
2. **Install** `padosoft/laravel-mobile-push` next to the channel and add the connection (same key and bundle as today).
   `php artisan mobile-push:doctor`; `php artisan mobile-push:connections`.
3. **Decide where tokens live**: keep your own column and return typed destinations from `routeNotificationForPush()` (the
   smallest change), or adopt the registry ([devices](devices.md)). Adding the registry is a separate project; do not couple it
   to this migration unless you want to.
4. **Convert one notification**: add `toApns()` next to `toApn()` and keep `via()` switchable by a flag: return
   `[ApnChannel::class]` while the flag is off and `['apns']` when it is on. Both methods can live in the class during the
   transition.
5. **Compare** with `php artisan mobile-push:preview --connection=ios --title=... --body=...` (what the message compiles to,
   nothing sent), then send to your own device with the allowlist (`MOBILE_PUSH_MODE=live`, `live.allow`, `live.only_to`)
   through `mobile-push:send`.
6. **Replace the failure handling** (point 1 above) before switching the traffic that matters, so dead tokens keep being
   cleaned up.
7. **Roll out notification by notification**, watching `outcome_unknown` and `rejected` by reason (log sink, Pulse or
   Prometheus: [observability](observability.md)).
8. **Remove** the channel package and its `broadcasting.php` entry when no `ApnChannel` is left.

**Rollback.** Flip the flag of step 4 back: `via()` returns `ApnChannel::class` again and the old path (config, key,
`toApn()`) is untouched. What a rollback does not undo: pushes already sent through this package, rows it wrote (registry,
ledger, logs) and any token it invalidated or quarantined. Keep your token column as the source of truth until the end of
step 7.

## Not verified

Nothing here has run against real APNs or a real device. How the channel behaves is read from its source at `v6.5.0`, not
from running it; check the method names against the version you have installed.
