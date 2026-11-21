---
title: "Device registry"
description: "The registry stores the installations of your users so that you can write MobilePush::to($user) instead of carrying tokens around. It is optional and off by default."
---

# Device registry

The registry stores the installations of your users so that you can write `MobilePush::to($user)` instead of carrying tokens around. It is optional and **off by default**.

| | |
|---|---|
| Enable | `mobile-push.devices.enabled` = `true` (env `MOBILE_PUSH_DEVICES_ENABLED=true`) |
| Migration tag | `php artisan vendor:publish --tag=mobile-push-migrations-devices` then `php artisan migrate` (or `php artisan mobile-push:install`; with `MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true` nothing is published and `migrate` loads the package files) |
| Table / connection | `mobile-push.devices.table` (`MOBILE_PUSH_DEVICES_TABLE`, default `mobile_push_devices`), `mobile-push.devices.connection` (`MOBILE_PUSH_DEVICES_CONNECTION`, default: the default connection) |
| Model | `mobile-push.devices.model` (default `Padosoft\MobilePush\Laravel\Devices\PushDevice`; yours must extend it) |
| Pruning | `mobile-push.devices.prune.invalidated_days` (30), `mobile-push.devices.prune.inactive_days` (365) |

When the module is enabled the package also loads its migration by itself; publishing it is for apps that keep their migrations under version control (the usual case).

What was and was not verified: the registry is covered by scenario tests on SQLite. MySQL and PostgreSQL were not exercised, and nothing has been run against real APNs or FCM or a real device.

Related pages: [sending](/sending/sending), [configuration](/get-started/configuration), [multi-tenancy](/operations/multi-tenancy), [security and privacy](/operations/security-and-privacy), [commands](/operations/commands), [troubleshooting](/operations/troubleshooting).

## The principle: the app declares, the package never guesses

A device row is bound to an **app binding**: scope (tenant) + provider + app id + environment. An APNs token from the sandbox is rejected by the production endpoint and vice versa, and the shape of the token tells you nothing. So:

- the **platform** (`ios` / `android`), the **environment** (`production` / `sandbox`) and the **bundle id** come from the app, which knows how it was built and signed;
- your server turns that declaration into one of your configured connections, and checks that the connection really is that app. The package then stores whatever binding you hand it;
- nothing is ever inferred from the token string, and the environment is never switched automatically (see [environment probe](/modules/devices#device-registry-environment-probe-link-troubleshooting)).

## Setting it up

Add the trait to the model that owns devices (a `User`, a `Team`, ...). The owner is polymorphic, so any Eloquent model with a scalar primary key works.

```php
use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Laravel\Devices\HasPushDevices;

class User extends Model
{
    use HasPushDevices;
}
```

The trait gives you:

| Member | What it does |
|---|---|
| `pushDevices()` | the `MorphMany` relation to `PushDevice` |
| `registerPushDevice(BindingRef $binding, Destination $destination, array $attributes = [])` | idempotent upsert through `DeviceRegistrar::register()` |
| `pushDestinations(BindingRef\|string\|null $binding = null, ?string $scope = null)` | the **active** devices as typed destinations, each carrying its registry reference. A `BindingRef` selects exactly that scope and binding identity; a string selects a connection name inside `$scope` (default: the default scope); `null` selects every binding of the scope. Another tenant's rows are never read |

`MobilePush::to($user)` uses the registry for any model with `pushDevices()` (unless it defines `routeNotificationForPush()`, which then wins). Without the registry enabled it throws a `ConfigurationException` that tells you to enable it.

`Padosoft\MobilePush\Laravel\Devices\PushDeviceOwner` is an optional abstract Eloquent base class that already uses the trait.

## Registering a device from an API controller

The attributes the registrar accepts are `platform` (`Platform` or `'ios'`/`'android'`), `locale`, `timezone`, `app_version`, `os_version`, `device_model`, `metadata` (an array, merged into the stored one) and `fid` (an `FcmFid`, FCM token rows only). Empty strings are ignored.

The example below is a complete, realistic controller. It declares the mapping from what the app says to your connection names in your own config file (an allow-list, not a guess), validates the input, builds a typed destination and registers it for the authenticated user. `config/push_apps.php` is yours; the package knows nothing about it.

```php
// config/push_apps.php
return [
    // 'platform:environment:bundle' => connection name from config/mobile-push.php
    'ios:production:com.example.shop' => 'ios',
    'ios:sandbox:com.example.shop' => 'ios-sandbox',
    'android:production:com.example.shop' => 'android',
];
```

```php
// app/Http/Controllers/PushDeviceController.php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

final class PushDeviceController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'environment' => ['required', Rule::in(['production', 'sandbox'])],
            'bundle_id' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:4096'],
            'fid' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'timezone'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'device_model' => ['nullable', 'string', 'max:128'],
        ]);

        // 1. What the app declared must be an app WE operate. No match: refuse, do not fall back to a default.
        $connection = config('push_apps')[implode(':', [$data['platform'], $data['environment'], $data['bundle_id']])] ?? null;
        abort_if($connection === null, 422, 'Unknown app.');

        // 2. The tenant comes from the authenticated context, never from the payload.
        $binding = MobilePush::binding($connection);

        // 3. A typed destination: a malformed token is refused before anything is written.
        try {
            $destination = $data['platform'] === 'ios'
                ? ApnsDeviceToken::of($data['token'])
                : FcmToken::of($data['token']);
        } catch (InvalidDestinationException) {
            abort(422, 'The token is not valid.');   // never echo the token back
        }

        $attributes = ['platform' => Platform::from($data['platform'])] + array_filter([
            'locale' => $data['locale'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'device_model' => $data['device_model'] ?? null,
        ]);

        if ($data['platform'] === 'android' && ! empty($data['fid'])) {
            $attributes['fid'] = FcmFid::of($data['fid']);   // same row, still ONE send per message
        }

        $device = $request->user()->registerPushDevice($binding, $destination, $attributes);

        return response()->json(['id' => $device->id, 'state' => $device->state->value], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        // Logout of this phone. Body: the same platform/environment/bundle_id/token as at registration.
        // ...resolve $binding and $destination exactly as above, then:
        // app(\Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar::class)->unregister($binding, $destination, $request->user());

        return response()->json(null, 204);
    }
}
```

Notes on the example:

- `MobilePush::binding($name)` resolves the connection and validates its configuration (a wrong key, an expired certificate and similar throw a `ConfigurationException` before any request). Use `->appId` and `->environment` on the returned `BindingRef` if you want to double-check them against the declaration.
- Re-registering is idempotent: the app can call this endpoint at every launch and whenever the token changes. A refresh updates `last_seen_at` and the profile fields; it does not change the generation.
- An FCM token on an iPhone is legitimate (FCM delivers to iOS through APNs). In that case declare `platform = ios` for the **row's `platform` attribute** but build an `FcmToken` and use an FCM connection: the row's provider and token kind come from the destination and binding, the `platform` attribute is only what the app told you about the phone. The package does not cross-check them.
- Topics, conditions, broadcast channels and Live Activity tokens cannot be registered here; `register()` throws an `InvalidDestinationException` for them (Live Activity tokens have their own tables: see [live activities](/modules/live-activities)).

A destination that is syntactically wrong is refused by the typed constructors. This example runs against the fake:

```php
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;

$apns = ApnsDeviceToken::of(strtoupper($iosToken));   // normalised to lowercase hex
$fcm = FcmToken::of($androidToken);

$refused = false;
try {
    ApnsDeviceToken::of('this is not a hex token');
} catch (InvalidDestinationException) {
    $refused = true;                                   // the message never contains the value
}

expect($apns->value())->toBe($iosToken)
    ->and($fcm->kind()->value)->toBe('fcm_token')
    ->and($refused)->toBeTrue();
```

## Generations

Every row has a `generation`, starting at 1. A job, a ledger row or a queued push remembers *which generation of which device* it was prepared for, and is re-checked against the row right before the network. The generation goes up when the meaning of the row changes:

| Event | Effect |
|---|---|
| First registration | generation 1 |
| Same owner registers again while the row is `active` | refresh only: `last_seen_at`, profile, key rotation; generation unchanged |
| The row was `quarantined` or `invalidated` and the app registers it again | generation + 1, state `active`, `registered_at` = now |
| A **different owner** registers the same token (logout then login of another user, shared phone) | generation + 1, owner replaced, `registered_at` = now |
| `unregister($binding, $destination, $owner)` (logout of one phone) | owner cleared, generation + 1; the row is kept. If a *different* current owner holds it, nothing changes (a late logout of user A must not detach user B) |
| `DeviceRegistrar::forgetOwner($owner, $scope = null)` | clears the owner on every device of that owner (one statement) and bumps each generation. **This is a detach, not an erasure**: the encrypted token stays in the row. To erase, use `mobile-push:forget` (see [commands](/operations/commands)) |

Two writers racing on the same destination are serialised by a unique key on `(binding_identity, kind, token_index)` and a row lock; the loser re-reads and treats its call as a re-registration.

## Ownership changes: logout and a shared phone

Because a phone can change hands, a push prepared for Alice must never reach Bob's session on the same phone:

1. Alice logs out: your logout endpoint calls `unregister()` (or the app simply stops registering). The generation moves.
2. Bob logs in and the app registers the same token: the row's owner becomes Bob, the generation moves again.
3. Anything queued for Alice carries the old owner and generation. At send time the eligibility gate (below) finds a different owner and skips the destination with `PolicyIneligible` / `owner_changed`.

The package does not call `unregister()` for you: it has no idea when your user logs out.

## Quarantine versus invalidation

Only **final** outcomes change the registry, and only for the generation that was attempted (a late answer about generation 7 never touches generation 8, even with the same raw token). An unknown outcome (`OutcomeUnknown`) never changes a row.

| State | Meaning | Triggers (from the outcome catalogues) | Effect |
|---|---|---|---|
| `quarantined` | routing suspicion: something about *where* we sent is wrong, the token itself may be fine | APNs `BadDeviceToken` (400: malformed, or from the other environment), APNs `DeviceTokenNotForTopic` (400: another app), FCM `SENDER_ID_MISMATCH` (403: another Firebase project) | kept, never sent to again until the app registers it again (new generation) or the [probe](/modules/devices#device-registry-environment-probe-link-troubleshooting) corrects it |
| `invalidated` | the provider said the registration is gone | APNs `ExpiredToken` / `Unregistered` with HTTP **410**, FCM `UNREGISTERED` (404) | kept until pruned, never sent to; re-registration reactivates it with a new generation |

Rules worth knowing:

- Invalidation needs Apple's explicit statement: only an HTTP 410 invalidates an APNs destination.
- A 410 carries a timestamp ("the token was invalid as of ..."). If the timestamp is **not newer than the row's `registered_at`**, the app re-registered after Apple's moment, so the report is stale and the row is left alone (`stale_timestamp`).
- Only individual installations are touched: a topic or a broadcast channel is never quarantined or invalidated.
- A successful (`Accepted`) send updates `last_sent_at` on the attempted generation.
- The reason is stored in `state_reason` (at most 64 characters) and the change is announced with the events `DestinationQuarantined` / `DestinationInvalidated` (their `registryChange` field says what the registry did).

## Eligibility is re-checked right before sending

When the registry is enabled the manager adds a gate that runs **immediately before the network**, one indexed query per window of destinations. For every registry destination it checks that the row still exists in this scope and binding, is `active`, belongs to the same owner and has the same generation it had when the send was prepared. Failures are `Skipped` results with reason `PolicyIneligible` and a raw reason of:

| Raw reason | Meaning |
|---|---|
| `row_missing` | pruned, deleted, or from another scope |
| `state_not_active` | quarantined or invalidated |
| `owner_changed` | logout, or a shared phone (also reported when the row was registered again and changed hands) |
| `generation_changed` | registered again after this push was queued |

This also applies to pushes sitting in the queue and in the durable ledger: the destination is looked up again when the worker picks it up. Destinations that are not device-registry kinds (Live Activity tokens, topics, broadcast channels) are not looked up in this table.

Your own consent or opt-out check goes through `MobilePush::eligibleUsing()` and is evaluated at the same moment; see [policies](/modules/policies) and [notifications and fallback](/sending/notifications-and-fallback).

## Encryption and the keyed index

- The token (and the FID) is stored **encrypted** (AES-256-GCM; the associated data binds the scope and the destination kind, so a value copied into another scope or column fails authentication).
- Lookup uses a **keyed index** (`v<version>:` plus an HMAC-SHA256 over scope, kind and value), stored in `token_index` (`fid_index` for the FID). The key is independent from the encryption key, so a leaked database does not allow guessing tokens offline.
- Both key rings come from `mobile-push.privacy.*`. When empty, version 1 of each is derived from `APP_KEY` with a purpose-specific HKDF label. To rotate, add a new version and keep the old ones until rows were rewritten: `privacy.index_keys` (`MOBILE_PUSH_INDEX_KEY`, `MOBILE_PUSH_INDEX_KEY_VERSION`) and `privacy.encryption_keys` (`MOBILE_PUSH_ENCRYPTION_KEY`, `MOBILE_PUSH_ENCRYPTION_KEY_VERSION`). A register call re-indexes and re-encrypts the row it touches to the current version, and lookups try every known version.
- `PushDevice` hides `token`, `token_index`, `fid` and `fid_index` from serialisation. `destination()` decrypts on demand; never log it. Commands and tables print `TokenMasker::mask()` only.

Losing the encryption key means losing the ability to send to the stored devices (the apps would have to register again). Treat the key like a secret and back it up with the rest of your secrets.

## FCM token and FID in one row

An Android installation can be addressed by its FCM registration token or by its Firebase installation id (FID). The registry keeps them in **one row**: the row's kind is `fcm_token`, the FID is an extra encrypted column with its own index. `pushDestinations()` returns one destination per row (the token), so a message is sent **once** per installation, never twice. Pass `'fid' => FcmFid::of($fid)` when registering, or call `DeviceRegistrar::attachFid($device, $fid)` later (it throws `InvalidDestinationException` unless the row is an FCM token row). Registering an `FcmFid` destination whose FID is already attached to a token row finds that row instead of creating a second one. `$device->fidDestination()` returns the FID as a typed destination carrying the same registry reference.

## Pruning

The package never deletes a device by itself during a send. Dead rows are removed by:

```bash
php artisan mobile-push:devices:prune                       # uses mobile-push.devices.prune.*
php artisan mobile-push:devices:prune --pretend             # count only
php artisan mobile-push:devices:prune --invalidated-days=14 --inactive-days=180
```

It deletes invalidated rows whose state changed more than `invalidated_days` ago and rows (any state) not seen for `inactive_days` (`last_seen_at`, or `registered_at` when never seen), in chunks of 500. The package does **not** schedule it: add it to your scheduler (`Schedule::command('mobile-push:devices:prune')->daily();`). It skips with a warning when the module is disabled. Programmatic use: `DevicePruner::prune(?int $invalidatedOlderThanDays, ?int $inactiveOlderThanDays, bool $dryRun)`.

For privacy requests, `mobile-push:export` and `mobile-push:forget` handle a whole owner (see [security and privacy](/operations/security-and-privacy) and [commands](/operations/commands)).

## Environment probe (link troubleshooting)

The usual reason for a sudden wave of `BadDeviceToken` is an environment mismatch: a TestFlight or development build (sandbox token) registered under a production binding, or the reverse. The package does **not** retry the other environment automatically, because that would send real tokens to another endpoint without anyone deciding it. Those rows are quarantined instead.

When you want to check and fix them, run the explicit operator command:

```bash
php artisan mobile-push:devices:probe-environment --quarantined --allow-cross-environment --limit=10
```

Options: `--device=ID` (one quarantined row), `--quarantined` (the quarantined APNs device tokens of the scope), `--connection=NAME` (the connection of the same app on the other environment, when several match), `--limit=N` (1-100, default 10), `--allow-cross-environment` (**required**: acknowledges that tokens are sent to the other environment), `--scope`, `--force` (skip the confirmation), `--json`.

For each quarantined token it sends **one silent background push** through the connection of the same app (same scope, same bundle id) on the other environment, one device at a time:

- accepted: the row is re-labelled (environment and binding) and reactivated under a new generation;
- rejected again: it stays quarantined;
- a refusal of the credential (a key limited to one environment answers this way): the whole run stops;
- anything else: the row is left untouched.

The execution mode and safe mode are asked first: in simulation nothing is sent. The fix for the root cause is in the app and in your declaration table (see the controller above), not the probe.

## Not verified

The probe, quarantine and invalidation logic follow Apple's and Google's documented answers as recorded in the outcome catalogues; none of it has run against real providers. The 410 timestamp comparison is tested with synthetic values.

---

*This page is generated from [`docs/devices.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/devices.md). To change it, edit that file in the repository, not this site.*
