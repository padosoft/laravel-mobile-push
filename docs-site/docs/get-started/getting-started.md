---
title: "Getting started"
description: "From an empty Laravel app to a push on your own phone, one step at a time. If you have never sent a push notification before, read every step; nothing is skipped."
---

# Getting started

From an empty Laravel app to a push on your own phone, one step at a time. If you have never sent a push notification before, read every step; nothing is skipped.

**What you will have at the end:** a Laravel app with one iOS connection (APNs) and one Android connection (FCM), a passing `mobile-push:doctor`, a first send in simulation (nothing leaves your machine), then a first real send to a test phone.

**Status reminder:** the package is pre-release. It is verified against a protocol-level HTTP/2 mock server and the providers' published schemas, but has not yet been run against the real APNs/FCM servers in this project. Your first real send is also a test of the package: if something does not behave as described, please open an issue.

## 0. What you need

- PHP 8.3 or newer with the `curl`, `openssl` and `json` extensions, and a libcurl built with HTTP/2 (`php -r 'var_dump(curl_version()["features"] & CURL_VERSION_HTTP2);'` must not print `int(0)`). `ext-intl` is recommended (exact text truncation).
- Laravel 12 or 13, with an application key (`php artisan key:generate`): the package derives its encryption and index keys from `APP_KEY` unless you configure dedicated ones.
- For iOS: an Apple developer account and the bundle id of your app. For Android: a Firebase project with your app added.
- For a real send: a phone with a development build of your app that has asked for notification permission, and its device token (step 8).

## 1. Install the package

```bash
composer require padosoft/laravel-mobile-push
```

The service provider and the `MobilePush` facade are registered automatically (Laravel package discovery).

## 2. Run the installer

```bash
php artisan mobile-push:install
```

It asks which optional modules you want (device registry, Live Activities, database send log, campaigns, receipts) and which delivery profile (`light`: best effort, no tables; `durable`: every delivery recorded in a ledger before it is sent). For a first try answer **no** to every module and choose **light**: you can enable them later. Non-interactive form: `php artisan mobile-push:install --profile=light` (`--modules=devices,logs`, `--force` to overwrite published files).

It publishes `config/mobile-push.php` (and the migrations of the modules you chose, which are safe to run twice), then prints the `.env` variables to set, the queue worker setup, and the next command to run. It does not run `php artisan migrate`: do that when you want the tables. Migrations are published, not loaded from `vendor/`, unless you set `MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true` (config `migrations.autoload`): then nothing is published and `migrate` loads the files of the enabled modules instead. Use one way or the other; a published copy always wins, so a table is never created twice.

**The scheduler.** The package registers a few scheduled tasks itself (sweepers and alerts when they are enabled, and a daily maintenance run: the prune commands of the modules you enabled and a credentials check at `03:17`). They only run if your host runs Laravel's scheduler: add the standard cron entry `* * * * * php artisan schedule:run` (or use `php artisan schedule:work` locally). With the default light profile and no optional module the only scheduled entry is the daily credentials check. The full list, conditions and switches: [commands](/operations/commands#artisan-commands-what-the-package-schedules-by-itself).

## 3. Create the APNs key (iOS)

On the Apple developer website, create a key with the **Apple Push Notifications service** capability, choose its environment, download the `.p8` file (only possible once) and note its **Key ID** and your **Team ID**. Full walkthrough, key scopes and certificate authentication: [credentials-apns.md](/get-started/credentials-apns).

Put the file somewhere outside your web root and out of git, for example `storage/push/AuthKey_ABC123DEFG.p8`.

## 4. Create the Firebase service account (Android)

In the Firebase console, project settings, **Service accounts**: generate a new private key and download the JSON file. Full walkthrough, least privilege and Application Default Credentials: [credentials-fcm.md](/get-started/credentials-fcm).

Put it next to the APNs key, for example `storage/push/firebase.json`.

## 5. Fill in `.env` and enable the two connections

```dotenv
# iOS (APNs), token authentication with a .p8 key
MOBILE_PUSH_APNS_TEAM_ID=ABCDE12345
MOBILE_PUSH_APNS_KEY_ID=ABC123DEFG
MOBILE_PUSH_APNS_KEY_PATH=/var/www/app/storage/push/AuthKey_ABC123DEFG.p8
MOBILE_PUSH_APNS_BUNDLE_ID=com.example.shop
MOBILE_PUSH_APNS_ENVIRONMENT=sandbox        # sandbox: build installed from Xcode. production: TestFlight / App Store

# Android (FCM)
MOBILE_PUSH_FCM_CREDENTIALS_PATH=/var/www/app/storage/push/firebase.json
MOBILE_PUSH_FCM_PROJECT_ID=                 # empty = taken from the JSON file

# Behaviour
MOBILE_PUSH_MODE=auto                       # auto = real sends only when APP_ENV=production
MOBILE_PUSH_DELIVERY_PROFILE=light
```

Use absolute paths. Instead of a path you can give the base64 of the file (`MOBILE_PUSH_APNS_KEY_BASE64=`, `MOBILE_PUSH_FCM_CREDENTIALS_BASE64=`; produce it with `base64 -w0 file`), which suits platforms where secrets are environment variables. Set **one** of the two, never both.

Now open `config/mobile-push.php`. The `connections` array is empty; just above it, a comment contains a ready-made `'ios' => [...]` and `'android' => [...]` example that reads exactly these variables. **Copy the two blocks into `connections`** (remove the leading `|` comment characters). You can rename them; the rest of this guide uses `ios` and `android`. If you cache the configuration, run `php artisan config:clear` after editing.

## 6. Check everything with `doctor`

```bash
php artisan mobile-push:doctor
```

On a correct local setup the output looks like this (this is a real run, with keys generated for the test):

```
PHP and curl
  ✓ PHP 8.3.6 with the required extensions (curl, openssl, json).
  i libcurl 8.5.0, OpenSSL/3.0.13.
  ✓ libcurl has every constant the transport needs.
  ✓ HTTP/2 is available (APNs requires it).
  ✓ TLS with ALPN is available.
  ✓ Client certificates can be passed in memory (certificate-based APNs works).

Configuration
  ✓ config/mobile-push.php parses (delivery profile: light).

Execution mode
  i SIMULATED: mode is "auto" and APP_ENV is "local", so NO request is sent to APNs or FCM; every send ends as "simulated".
      Remedy: To send for real set MOBILE_PUSH_MODE=live (outside production also set live.allow=true on the connection and list your test devices in live.only_to).

Connection "ios"
  ✓ The APNs key parses and is EC P-256.
  ✓ apns: com.example.shop (sandbox, team ABCDE12345, key id TEST123456), generation cedf01e7fd19.

Connection "android"
  ✓ The service-account file is complete and its key is RSA >= 2048.
  ✓ fcm: my-shop-app (production, service account push-sender@my-shop-app.iam.gserviceaccount.com, key id abc123), generation ef2621d1b20d.

Cache
  ✓ The cache store (default) is reachable.
  ✓ The cache store takes locks.

Queue timeouts
  ✓ transport_timeout (30 s) < chunk_deadline (40 s), margin 5 s
  ✓ chunk_deadline (40 s) < job_timeout (50 s), margin 5 s
  i job_timeout (50 s) < worker_timeout (?), margin 0 s: not applicable (The sync driver runs the job in-process: there is no worker timeout and no redelivery.)
  i worker_timeout (?) < retry_after (?), margin 0 s: not applicable (The sync driver runs the job in-process: there is no worker timeout and no redelivery.)

Database
  i No enabled module needs a table.

Clock
  i Clock skew cannot be checked offline: keep NTP/chrony running on every server. APNs refuses provider tokens signed more than about an hour away from its own clock (ExpiredProviderToken).

14 ok, 0 warning(s), 0 not verifiable, 0 failure(s).
Result: everything checked is fine (exit 0).
```

What each section checks:

| Section | Checks | When it fails |
|---|---|---|
| PHP and curl | The extensions, the libcurl version and constants, HTTP/2, ALPN, in-memory client certificates | Install a libcurl with nghttp2 / a newer PHP image. Without HTTP/2 nothing can be sent to APNs. |
| Configuration | `config/mobile-push.php` parses and the delivery profile is valid; warns when no connection is configured | Fix the config or the `MOBILE_PUSH_*` variables, then `php artisan config:clear`. |
| Execution mode | Whether sends are simulated or live, and why | `SIMULATED` is expected on your laptop; a warning means live sending is on outside production. |
| Connection "…" | Each connection's credentials, read and checked locally (key type, certificate dates, key/certificate match, service-account fields, secondary of the same team/project); a summary line with app id, environment and credential identity | The finding says what is wrong and how to fix it. Nothing is sent to Apple or Google. |
| Cache | The cache store used for provider tokens, breakers and rate limits is reachable and takes locks | Point `MOBILE_PUSH_CACHE_STORE` to a shared, lock-capable store (redis, memcached, database, dynamodb) in production. |
| Queue timeouts | The time-budget chain transport timeout < chunk deadline < job timeout < worker timeout < `retry_after` (SQS: visibility timeout) | A violation means a slow send could be redelivered to a second worker and sent twice. "Not verifiable" means the worker timeout cannot be read: declare it (`queue.worker_timeout`). See [queues-and-horizon.md](/reliability/queues-and-horizon). |
| Database | The tables of the enabled modules exist | Publish the migrations it names (`mobile-push:install` or the module's publish tag) and run `php artisan migrate`; with `migrations.autoload` just run `migrate`. |
| Clock | Information only | Keep NTP running. |

Symbols: `✓` ok, `i` information, `! warning:`, `! not verifiable:` (a check that could not be performed is never reported as a pass), `✗ failed:`. Exit code: 0 everything fine, 1 warnings or something not verifiable, 2 at least one failure. `--json` prints the same as JSON; `php artisan mobile-push:doctor ios` checks one connection.

`php artisan mobile-push:doctor --live` additionally sends **one real request per connection** to prove the credentials work (APNs: a push to an obviously invalid token, expected answer `BadDeviceToken`; FCM: a `validate_only` call). It is refused while the mode is `simulate`; use it after step 9.

## 7. The first send, in simulation

```bash
echo "<any 64-hex-character token>" | php artisan mobile-push:send --platform=ios --stdin --title="Hello" --body="First push"
```

Expected output on your laptop (real run; the token is masked):

```
 SIMULATED: NOTHING WAS SENT
No request reached APNs or FCM: the effective mode is "simulate".
Simulation mode: no request was sent. To send for real set MOBILE_PUSH_MODE=live (outside production also set the binding's live.allow=true and add the test device to live.only_to).

+-------------+-----------+-----------+---------------------------------------------+---------------------+
| Destination | Status    | Reason    | Hint                                        | Provider message id |
+-------------+-----------+-----------+---------------------------------------------+---------------------+
| ababab…abab | simulated | simulated | Simulation mode: no request was sent. ...   |                     |
+-------------+-----------+-----------+---------------------------------------------+---------------------+
1 destination(s): 1 simulated. Exit code 3 (simulated: nothing was sent).
```

**Why simulation is the default:** with `MOBILE_PUSH_MODE=auto`, only `APP_ENV=production` sends for real, so a developer laptop, a CI run or a staging server with a copy of production data can never push to real customers by mistake. Everything else (configuration, routing, message validation, the report) runs as in production.

The same from code (this block runs in the documentation test suite):

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Hello', 'First push'));

foreach ($report as $result) {
    echo $result->status->value, ' ', $result->reason->code->value, ' ', $result->reason->hint, PHP_EOL;
}
```

## 8. Get a real device token from your app

The token is created by the phone and your app sends it to your server (usually after login). Two minimal snippets, to adapt to your app:

```swift
// iOS (AppDelegate): convert the token to the hex string the package expects
func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
    let token = deviceToken.map { String(format: "%02x", $0) }.joined()
    // send `token` to your server
}
```

```kotlin
// Android (Firebase Messaging)
FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
    // send `token` to your server
}
```

Remember which environment the iOS build uses: a build run from Xcode talks to the **sandbox** environment, TestFlight and App Store builds to **production**. The connection's `environment` must match. To store tokens per user and write `MobilePush::to($user)`, enable the device registry: [devices.md](/modules/devices).

## 9. Go live

**In production** (`APP_ENV=production`, `MOBILE_PUSH_MODE=auto`): sends are live. Nothing else to do.

**Outside production** (your laptop, staging), three switches are needed, on purpose:

1. `MOBILE_PUSH_MODE=live`;
2. `live.allow => true` on the connection (`MOBILE_PUSH_APNS_LIVE_ALLOW=true` / `MOBILE_PUSH_FCM_LIVE_ALLOW=true` in the example config);
3. the test device in the connection's `live.only_to` allowlist (`MOBILE_PUSH_APNS_LIVE_ONLY_TO=<token>` / `MOBILE_PUSH_FCM_LIVE_ONLY_TO=<token>`, comma-separated for several).

Every other destination is `Skipped` with `policy_safe_mode`, and the hint says which switch is missing. Real runs:

```
| ababab…abab | skipped | policy_safe_mode | Blocked: live sending outside production is not enabled for this binding. To allow it set live.allow=true on the binding and add the test device to live.only_to. |
| ababab…abab | skipped | policy_safe_mode | Blocked: the destination is not in this binding's live.only_to allowlist of test devices. |
```

Topics, broadcast channels and channel management need their own flags (`live.allow_topics`, `live.allow_broadcast`, `live.allow_channel_management`). Allowlisted tokens are converted to keyed indexes when the config is loaded and are never logged. Details: [safe-mode.md](/reliability/safe-mode).

## 10. The first real send

From the command line (tokens on standard input, not as arguments, so they do not end up in your shell history):

```bash
echo "$MY_IPHONE_TOKEN" | php artisan mobile-push:send --platform=ios --stdin --title="Hello" --body="It works"
echo "$MY_ANDROID_TOKEN" | php artisan mobile-push:send --platform=android --stdin --title="Hello" --body="It works"
```

The status you want is `accepted`, with a provider message id. **`accepted` means the provider accepted the push, not that the phone displayed it.** Exit codes: 0 accepted, 1 partial, 2 invalid input, 3 simulated, 4 unknown outcome.

From code, in a route or a tinker session:

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toToken(Platform::Ios, $token)->send(PushMessage::make('Hello', 'It works'));

foreach ($report as $result) {
    dump($result->status->value, $result->reason->code->value, $result->reason->hint, $result->providerMessageId);
}
```

## My push did not arrive: checklist

Go down the list; most problems are in the first five lines.

1. **Status `simulated`?** The mode is `simulate` (or `auto` outside production). See step 9.
2. **Status `skipped` with `policy_safe_mode`?** Outside production: `live.allow` or `live.only_to` is missing. The hint says which.
3. **Status `rejected` with `destination_rejected` (APNs `BadDeviceToken`)?** Most often the environment: a sandbox token sent to `production` or the reverse. Check how the build was installed (step 8) and the connection's `environment`. `php artisan mobile-push:explain BadDeviceToken` explains it.
4. **`destination_mismatch` (`DeviceTokenNotForTopic`, FCM `SENDER_ID_MISMATCH`)?** The token belongs to another app or Firebase project: check `bundle_id` / the service-account project.
5. **`auth_*` reason?** The credentials: Key ID, Team ID, key environment, revoked key, wrong service account. Run `php artisan mobile-push:doctor --live`.
6. **`accepted` but nothing on the phone?** The provider took it; what happens next is the phone's business. Check that the app has notification permission, that it is not in a Focus mode, that the notification channel exists on Android, and that a silent (`content-available`) push is not expected to show an alert. Apple and Google may also delay or drop low-priority pushes to save battery.
7. **`outcome_unknown`?** The request left, but the answer was lost (network). The push may have been delivered: do not resend an alert blindly. See [outcomes.md](/reliability/outcomes).
8. **`deferred` / `failed`?** The provider was unavailable or throttling; `nextAttemptAt` says when a retry is planned. See [retries-and-limits.md](/reliability/retries-and-limits).
9. **Nothing at all, no report line?** No destination resolved (an empty report is not an error) or the report was never read: a report is a cursor, consume it (`counts()`, `foreach`).

For each reason code, its cause and its remedy: `php artisan mobile-push:explain <reason>` and [troubleshooting.md](/operations/troubleshooting).

## Next steps

- [Sending](/sending/sending): every way to address and send, the report, queues, the CLI.
- [Messages](/sending/messages): the unified message, native APNs/FCM options, limits.
- [Testing](/sending/testing): `MobilePush::fake()` and `MobilePush::fakeTransport()`.
- [Delivery profiles](/reliability/delivery-profiles) and [queues and Horizon](/reliability/queues-and-horizon) before production.
- [Configuration reference](/get-started/configuration): every key.

---

*This page is generated from [`docs/getting-started.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/getting-started.md). To change it, edit that file in the repository, not this site.*
