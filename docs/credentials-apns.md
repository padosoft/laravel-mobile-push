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

The environment is **declared, never guessed**. A device token issued to a development build only works on the sandbox environment, and a token of a TestFlight/App Store build only on production. A token sent to the wrong environment is answered `BadDeviceToken`; the package then *quarantines* that device (it does not delete it) and never switches environment by itself. An operator can probe the other environment explicitly with `mobile-push:devices:probe-environment` (see [devices.md](devices.md)).

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

What the 20-minute limit is counted on: Apple's page "Establishing a token-based connection to APNs" (read on
2026-11-22) says that APNs reports an error "if you use a new token more than once every 20 minutes on the same
connection". The same connection is the only scope Apple states: the page does not say whether the limit is also
counted per key or per team, and neither does the `TooManyProviderTokenUpdates` row of the error table. The package
fits the stated scope: a worker keeps the token it is using for at least 20 minutes (45 by default) before it looks at
the shared cache again, so one connection never sees a second token sooner. How APNs counts beyond that was not
measured (it needs a real key).

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

Other APNs keys of a connection: `port` (`443`, default, or `2197`), `broadcast_host` (`standard` or `api-broadcast`, the host used to publish Live Activity broadcasts: Apple documents both and **which one is operational has not been verified** against Apple), `secondary` (below), `live` (sending outside production, see [safe-mode.md](safe-mode.md)) and the transport keys. Every key, its env var and its default is in the generated [configuration reference](configuration.md).

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

`Padosoft\MobilePush\Core\Auth\Signer` is the interface the token providers sign through. For an APNs token connection you can point the connection at a class that implements it, so that the private key never reaches the application: the class asks your KMS/HSM to sign.

```php
'ios' => [
    'driver' => 'apns',
    'auth' => 'token',
    'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
    'signer' => App\Push\KmsApnsSigner::class,   // a class or a container binding name; no `key`, `key_id` is optional
    'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),
    'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),
],
```

`signer` is accepted in the primary block and in `secondary` (same rules for both). The value must be a string (a closure would break `config:cache`); the container builds it, so the class can take its KMS client in the constructor, and a binding name works as well as a class name.

What the class must satisfy:

- `algorithm()` returns `ES256`. Any other algorithm is refused.
- `keyId()` returns the 10-character APNs key id: it goes into the `kid` header of the provider token. An empty id is refused. If the connection also sets `key_id`, it must be equal to the signer's, otherwise the connection fails (remove `key_id`, the signer provides it).
- `sign($signingInput)` returns the **raw 64-byte `R||S`** signature of ES256 (JOSE format), not the DER form most KMS APIs return. Convert the DER answer before returning it.

What is and is not read: with `signer` set, the `key` source is **not read** (it is not even required to exist) and nothing is inspected locally, so the "EC P-256" check does not run: the key is the KMS's business. `team_id` and `bundle_id` stay required. `signer` applies to `auth => token` only; with `auth => certificate` it is ignored. The signer is resolved once per resolved connection (it is cached per process like any connection), and it is called when a provider token is minted, not once per push: the token is renewed by a single process at a time and shared through the cache as described above.

Errors, all raised as a `ConfigurationException` naming the config key when the connection is first used and before any request: the value is not a string, the container cannot resolve it, it resolves to something that is not a `Signer`, it signs with another algorithm, it reports no key id, or its key id differs from `key_id`.

An illustrative skeleton (it cannot run as it is: the KMS client and its method names are yours):

```php
namespace App\Push;

use Padosoft\MobilePush\Core\Auth\Signer;

final class KmsApnsSigner implements Signer
{
    public function __construct(private KmsClient $kms) {}   // your own client, injected by the container

    public function algorithm(): string
    {
        return 'ES256';
    }

    public function keyId(): ?string
    {
        return config('services.apns.key_id');                // the 10-character APNs key id
    }

    public function sign(string $signingInput): string
    {
        $der = $this->kms->signEcdsaSha256($signingInput);    // the KMS hashes and signs; usually answers DER
        return $this->derToRawRs($der);                       // 64 bytes: R and S, 32 bytes each, left-padded
    }
}
```

How the credential checks report it: `mobile-push:doctor`, `mobile-push:connections`, `mobile-push:health` and `mobile-push:credentials:check` do not report a credential error for a `signer` connection; they print an `ok` finding that says "External signer: expiry not verifiable (the key is held outside this application, in a KMS / HSM)". The package cannot warn you before the key is revoked or rotated in the KMS; `doctor --live` still proves the whole chain with APNs.

What was run: the signer path is tested with a signer that wraps a key generated at runtime, and, in CI (`backends` workflow, `KmsSignerTest`), with a signer written against the **AWS KMS API** (`Sign` with `ECDSA_SHA_256` on an `ECC_NIST_P256` key created inside the service, DER answer converted with `DerSignature::toJose()`), served by a local emulator of that API: the provider token on the wire verifies against the public key the service publishes, and two pushes cost one remote signature. Not verified: a **real** KMS or HSM (AWS, Google Cloud, Azure, a hardware module), their latency and failure modes, and APNs accepting a KMS-made signature (it is plain ECDSA P-256, which is what ES256 needs, but no such token was shown to Apple).

There is **no `signer` for FCM**: the Google credential is an RSA service-account key that the core signs itself, and the connection does not accept a custom signer. If the key must not be exportable on the Android side, use Application Default Credentials or Workload Identity Federation through `google/auth` (see [FCM credentials](credentials-fcm.md#option-2-application-default-credentials-googleauth)).

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
- The credential **generation** (part of every binding identity) changes when the credentials change, so a job queued with the old generation is reported as a binding mismatch instead of silently using another identity. See [multi-tenancy.md](multi-tenancy.md).

## Checking the credentials

`php artisan mobile-push:doctor` reads every connection without contacting Apple:

```
Connection "ios"
  ✓ The APNs key parses and is EC P-256.
  ✓ apns: com.example.shop (sandbox, team ABCDE12345, key id TEST123456), generation cedf01e7fd19.
```

A missing or unreadable key (not applicable to a connection with an external `signer`), a key that is not P-256, an expired certificate, a key that does not match its certificate, or a secondary of another team is a failure with a remedy. What it cannot know offline (the Key ID really belongs to the Team ID, the key covers the bundle id, the environment is right) needs a real request: `php artisan mobile-push:doctor --live` sends **one real push per APNs connection to an obviously invalid device token**. APNs answering `BadDeviceToken` proves the authentication works; an authentication error is reported as a failure. The probe is refused when the effective mode is `simulate`, and outside production it is skipped unless the connection allows live sending to that destination; in practice, run it from production or with `MOBILE_PUSH_MODE=live`.

`php artisan mobile-push:credentials:check` lists the same findings per connection (codes such as `apns.key.valid`, `apns.certificate.expiring`, `apns.certificate.key_mismatch`), never contacts Apple, can raise the expiry alerts (`--no-alert` to only report), supports `--json`, and exits 0 (ok), 1 (warnings, for example a certificate close to expiry) or 2 (errors). Schedule it daily if you use certificates.

Next: [FCM credentials](credentials-fcm.md), or back to [getting started](getting-started.md).

## What was checked against Apple's real endpoint

Nothing in this project has run with a real Apple key or certificate. One thing was checked against Apple's real
**sandbox** host (`api.sandbox.push.apple.com`) on 2026-11-21, and it needs no credential and can deliver nothing. It was run twice: from the build environment, through its HTTPS
proxy, and from a GitHub-hosted runner, which connects to Apple directly, so the second run also went through the
package's own TLS and HTTP/2 negotiation with Apple (the transport refuses anything but HTTP/2 for APNs). Both gave the
same results:

- **A push with a provider token signed by a key Apple does not know** (a P-256 key generated on the spot, a made-up team
  id and key id, a device token of 64 zeros). APNs answered HTTP 403 `{"reason":"InvalidProviderToken"}`; the package
  returned `failed` / `auth_token_invalid` after one attempt, with no action on the destination: an authentication
  problem never quarantines or invalidates a device.
- **The same with a provider token issued two hours earlier.** Apple answered `InvalidProviderToken` again, not
  `ExpiredProviderToken`: with an unknown key the age of the token is never looked at. The refresh-and-retry path for
  `ExpiredProviderToken` therefore remains verified against fixtures only.
- **A request with no `authorization` header at all** (sent with curl, not through the package, which never sends
  one): HTTP 403 `{"reason":"MissingProviderToken"}`, with an `apns-id` response header.

You can repeat the first two with `php tests/Support/Probes/apns-unauthenticated-probe.php` from a clone of the
repository; the `providers` GitHub workflow runs it every week and on demand, and fails if Apple's answer or its
classification changes. Everything past authentication (an accepted push, `BadDeviceToken`, `Unregistered` with its timestamp, the
5xx advice, Live Activities, broadcast channels, certificates) is still verified against fixtures and the local mock
server only. The production host was not contacted.
