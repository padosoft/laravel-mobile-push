---
title: "APNs credentials"
description: "How to give the package the right to send to your iOS app. Two methods exist: a signing key (.p8, \"token authentication\", recommended) and a push certificate (mTLS)."
---

# APNs credentials

How to give the package the right to send to your iOS app. Two methods exist: a **signing key** (`.p8`, "token authentication", recommended) and a **push certificate** (mTLS). One APNs connection = one app (bundle id) in one environment.

Apple's own documentation: [Establishing a token-based connection to APNs](https://developer.apple.com/documentation/usernotifications/establishing-a-token-based-connection-to-apns) and [Establishing a certificate-based connection to APNs](https://developer.apple.com/documentation/usernotifications/establishing-a-certificate-based-connection-to-apns). The menus of the Apple developer website change over time; the steps below describe what to look for, not the exact clicks.

## What you need

| Value | Where it comes from | Config key | `.env` (example config) |
|---|---|---|---|
| Team ID (10 characters) | Your Apple developer account, membership details | `team_id` | `MOBILE_PUSH_APNS_TEAM_ID` |
| Key ID (10 characters) | Shown next to the key when you create it | `key_id` | `MOBILE_PUSH_APNS_KEY_ID` |
| The `.p8` file | Downloaded once when you create the key | `key` (a secret source) | `MOBILE_PUSH_APNS_KEY_PATH` or `MOBILE_PUSH_APNS_KEY_BASE64` |
| Bundle id | Your Xcode project (for example `com.example.shop`) | `bundle_id` | `MOBILE_PUSH_APNS_BUNDLE_ID` |
| Environment | `sandbox` for builds installed from Xcode, `production` for TestFlight and App Store builds | `environment` | `MOBILE_PUSH_APNS_ENVIRONMENT` |

The environment is **declared, never guessed**. A device token issued to a development build only works on the sandbox environment, and a token of a TestFlight/App Store build only on production. A token sent to the wrong environment is answered `BadDeviceToken`; the package then *quarantines* that device (it does not delete it) and never switches environment by itself. An operator can probe the other environment explicitly with `mobile-push:devices:probe-environment` (see [devices.md](/modules/devices)).

## Creating a signing key (`.p8`)

1. Sign in to the Apple developer website with an account that can manage keys, open the section for **keys** (under certificates, identifiers and profiles) and create a new key with the **Apple Push Notifications service (APNs)** capability enabled.
2. Apple now lets you choose the key's **scope** and **environment**:
   - a **team-scoped** key can sign for every app (topic) of the team. Apple documents that these keys are restricted to *Sandbox* or to *Production* (older team keys that work in both environments keep working), with at most two keys per environment;
   - a **topic-specific** key signs only for the apps (topics) you select, within a single environment (Apple: up to 200 keys per environment and 400 topics per key).
3. Download the `.p8` file. **Apple lets you download it only once**: store it in your secret manager immediately. Note the Key ID shown with it, and your Team ID.

What this means for the package:

- Use a key whose environment matches the connection's `environment`. A key restricted to the other environment is refused by APNs (`BadEnvironmentKeyIdInToken`); the package classifies it as an authentication failure (`auth_token_invalid`), and `mobile-push:explain BadEnvironmentKeyIdInToken` explains the remedy. If you serve both a development and a production build of the same app, configure two connections (`ios-sandbox`, `ios`) with a key for each environment.
- A topic-specific key must include the bundle id of the connection. The package has no setting for the key's scope and does not check it locally (it cannot: the `.p8` file does not say which topics it covers); APNs is the only judge. The exact error APNs returns for a topic outside a topic-specific key has **not been verified** in this project.
- The package checks locally that the file is an EC P-256 key (what ES256 needs). It cannot check the Key ID, the Team ID or the key's scope without asking APNs: `mobile-push:doctor --live` does that (see below).

### The provider token is managed for you

From the `.p8` key the package signs a provider token (a JWT, `ES256`, `kid` = Key ID, `iss` = Team ID). Apple's rules, which the package follows: a token may not be used more than one hour after it was issued, and must not be renewed more often than every 20 minutes. The package renews it after 45 minutes, by a single process at a time (a lock in the shared cache), while the others keep using the still-valid one; tokens are shared between workers through `mobile-push.cache.store` (encrypted at rest). On `ExpiredProviderToken` it refreshes once and retries once; on `TooManyProviderTokenUpdates` it slows down instead of failing over. Keep the server clock synchronized (NTP): APNs refuses tokens dated more than an hour from its own clock.

## Configuring the connection

```php
// config/mobile-push.php
'connections' => [
    'ios' => [
        'driver' => 'apns',
        'auth' => 'token',                       // default
        'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
        'key_id' => env('MOBILE_PUSH_APNS_KEY_ID'),
        'key' => [
            'path' => env('MOBILE_PUSH_APNS_KEY_PATH'),        // set exactly ONE of the sources
            'base64' => env('MOBILE_PUSH_APNS_KEY_BASE64'),
        ],
        'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),
        'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),
    ],
],
```

Other APNs keys of a connection: `port` (`443`, default, or `2197`), `broadcast_host` (`standard` or `api-broadcast`, the host used to publish Live Activity broadcasts: Apple documents both and **which one is operational has not been verified** against Apple), `secondary` (below), `live` (sending outside production, see [safe-mode.md](/reliability/safe-mode)) and the transport keys. Every key, its env var and its default is in the generated [configuration reference](/get-started/configuration).

## Where to put the key: secret sources

A secret is never a bare string in the config: it is a **source** array with exactly **one** of these keys (empty and null values are ignored, so the example above with two env variables works as long as only one is set):

| Source | Example | Notes |
|---|---|---|
| `path` | `['path' => storage_path('push/AuthKey_ABC123DEFG.p8')]` | Absolute path of a readable file. Keep it outside the web root and out of git. |
| `inline` | `['inline' => env('MOBILE_PUSH_APNS_KEY')]` | The PEM text itself; `\n` sequences are accepted. |
| `base64` | `['base64' => env('MOBILE_PUSH_APNS_KEY_BASE64')]` | The base64 of the file: handy for one-line environment variables (`base64 -w0 AuthKey_ABC123DEFG.p8`). |
| `resolver` + `reference` | `['resolver' => App\Push\VaultSecrets::class, 'reference' => 'push/apns-key']` | A class implementing `Padosoft\MobilePush\Core\Auth\SecretResolver` (`resolve(string $reference): string`), built by the container. Use it to read from Vault, AWS Secrets Manager, GCP Secret Manager... |

Never put a closure in the config: it must stay cacheable (`php artisan config:cache`).

```php
namespace App\Push;

use Padosoft\MobilePush\Core\Auth\SecretResolver;

final class VaultSecrets implements SecretResolver
{
    public function __construct(private VaultClient $vault) {}   // your own client, injected by the container

    public function resolve(string $reference): string
    {
        return $this->vault->read($reference);                   // must return the PEM text
    }
}
```

The secret is read when the connection is first used in a process, and the resolved connection is cached per process (`connection_cache.size`, default 64). A worker therefore sees a rotated secret only after a restart or `MobilePush::forgetConnection('ios')`.

### Keeping the key in a KMS/HSM

`Padosoft\MobilePush\Core\Auth\Signer` is the interface the token providers sign through (`algorithm()`, `keyId()`, `sign($signingInput)`, which for ES256 must return the raw 64-byte `R||S`). An implementation backed by a KMS never needs the key material. **The Laravel connection config has no key to plug in a custom `Signer` today**: the configured APNs connection always signs with the built-in OpenSSL signer from the `key` source. Using a KMS signer currently means building the core `ApnsTokenProvider` and driver yourself, outside the facade.

## Certificate authentication (mTLS)

```php
'ios-legacy' => [
    'driver' => 'apns',
    'auth' => 'certificate',
    'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
    'certificate' => ['path' => storage_path('push/apns-cert.pem')],      // may also contain the private key
    'certificate_key' => ['path' => storage_path('push/apns-key.pem')],   // only when the key is a separate file
    'passphrase' => env('MOBILE_PUSH_APNS_CERT_PASSPHRASE'),              // a string or a secret source
    'bundle_id' => 'com.example.shop',
    'environment' => 'production',
],
```

- Export the push certificate **together with its private key** from the keychain where it was created, then convert it to PEM (for example `openssl pkcs12 -in cert.p12 -out apns-cert.pem -nodes`, or keep the key encrypted and set `passphrase`).
- When the connection is first used, the package checks that the certificate is within its validity dates, that the private key belongs to it, and that its team matches `team_id`; `doctor` and `credentials:check` warn `credentials.warn_days` (default 30) days before it expires. The environment and topic extensions of Apple certificates are decoded on a best-effort basis: **they have not been checked against real Apple certificates** in this project, so always declare `environment`.
- Certificates are passed to libcurl in memory; `mobile-push:doctor` tells you whether your libcurl supports it (7.71 or newer).
- Certificates expire every year; `.p8` keys do not. Prefer a key unless you have a reason not to.

## Secondary credential and rotation

```php
'ios' => [
    // ... primary as above ...
    'secondary' => [
        'key_id' => env('MOBILE_PUSH_APNS_SECONDARY_KEY_ID'),
        'key' => ['base64' => env('MOBILE_PUSH_APNS_SECONDARY_KEY_BASE64')],
        // team_id defaults to the primary's; auth, certificate, certificate_key, passphrase also accepted
    ],
],
```

- The secondary is a **rotation twin**, not a fallback to another account: it must belong to the same team and the same environment, otherwise the connection fails with a `ConfigurationException` when it is first used.
- When the primary reaches the authentication breaker's threshold (consecutive credential failures: `guards.auth.threshold`, default 5), the breaker switches to the secondary instead of opening, once; a destination gets at most one extra attempt for the failover, within its retry budget. The decision is shared by every worker using that credential (it lives in the shared cache). A `CredentialFailedOver` event is dispatched and, with alerts on, an operational alert is sent.
- To rotate: create the new key, deploy it as `secondary`, check it with `mobile-push:doctor --live`, then promote it to primary and remove the old key from the config, and finally revoke the old key at Apple. Apple recommends closing existing connections after revoking a key; a deploy (worker restart) does that.
- The credential **generation** (part of every binding identity) changes when the credentials change, so a job queued with the old generation is reported as a binding mismatch instead of silently using another identity. See [multi-tenancy.md](/operations/multi-tenancy).

## Checking the credentials

`php artisan mobile-push:doctor` reads every connection without contacting Apple:

```
Connection "ios"
  ✓ The APNs key parses and is EC P-256.
  ✓ apns: com.example.shop (sandbox, team ABCDE12345, key id TEST123456), generation cedf01e7fd19.
```

A missing or unreadable key, a key that is not P-256, an expired certificate, a key that does not match its certificate, or a secondary of another team is a failure with a remedy. What it cannot know offline (the Key ID really belongs to the Team ID, the key covers the bundle id, the environment is right) needs a real request: `php artisan mobile-push:doctor --live` sends **one real push per APNs connection to an obviously invalid device token**. APNs answering `BadDeviceToken` proves the authentication works; an authentication error is reported as a failure. The probe is refused when the effective mode is `simulate`, and outside production it is skipped unless the connection allows live sending to that destination; in practice, run it from production or with `MOBILE_PUSH_MODE=live`.

`php artisan mobile-push:credentials:check` lists the same findings per connection (codes such as `apns.key.valid`, `apns.certificate.expiring`, `apns.certificate.key_mismatch`), never contacts Apple, can raise the expiry alerts (`--no-alert` to only report), supports `--json`, and exits 0 (ok), 1 (warnings, for example a certificate close to expiry) or 2 (errors). Schedule it daily if you use certificates.

Next: [FCM credentials](/get-started/credentials-fcm), or back to [getting started](/get-started/getting-started).

---

*This page is generated from [`docs/credentials-apns.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/credentials-apns.md). To change it, edit that file in the repository, not this site.*
