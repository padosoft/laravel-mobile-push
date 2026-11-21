---
title: "Security and privacy"
description: "A push package holds three kinds of sensitive things: credentials (a .p8 key, a service-account file), device tokens (an address that can wake one person's phone) and message content."
---

# Security and privacy

A push package holds three kinds of sensitive things: **credentials** (a `.p8` key, a service-account file), **device tokens** (an address that can wake one person's phone) and **message content**. This page lists what is stored where and how, how the keys rotate, what is redacted everywhere, how to protect the endpoints the package can expose, what `forget` and `export` really do, and, at the end, what the package does **not** protect you from.

> **This package does not make your application compliant with the GDPR or any other regulation.** `forget` and `export` help you answer a person's request for the data this package holds; they do not cover your backups, your other systems, your legal bases or your deadlines. Every `forget` report says so, and lists what it cannot reach.

Related pages: [devices](/modules/devices), [delivery profiles](/reliability/delivery-profiles), [receipts](/modules/receipts), [observability](/modules/observability), [multi-tenancy](/operations/multi-tenancy), [commands](/operations/commands).

## What is stored where

| Place | What | How it is protected |
|---|---|---|
| **Device registry** (`mobile_push_devices`, module `devices`) | The device token and (FCM) the installation id. | Token **encrypted** (AES-256-GCM, authenticated, bound to the scope and the destination kind). A **keyed HMAC index** next to it is used for lookups, so the token is never searched in clear. Profile fields (locale, timezone, app version, OS version, device model, `metadata`) and the owner type and id are **not encrypted**. |
| **Ledger** (`mobile_push_effects`, `mobile_push_deliveries`, durable profile) | The message **snapshot** (so the relay can send it later) and, for raw tokens, the destination. | Both **encrypted** (AES-256-GCM). The snapshot is bound to the scope and the effect id: copied to another effect it fails to authenticate, and it is authenticated **before** it is unserialized, so a database writer without the key cannot inject objects. Registry devices are **not copied**: the registry stays the only place that holds the token. `effect_key`, owner type and id, notification class and the outcome (reason code, 120-character sanitized provider reason) are stored **in clear**: do not put personal data in an effect key. |
| **Live Activity tables** (module `live_activities`) | Update tokens and push-to-start tokens, activity state. | Tokens encrypted, with a keyed index, like the registry. |
| **Send log** (`log.sinks`: `log`, `database`, `otel`) | One record per result. | Destination appears only as its **keyed index and a masked form**; provider text is redacted; the payload is recorded per `log.payload` (below). |
| **Cache** (`cache.store`) | Provider tokens (APNs JWT, Google access token), circuit-breaker and rate-limit state, relay markers, metric counters. | Provider tokens **encrypted** (AES-256-GCM, bound to the key). Guard state is counters and timestamps only, stored in clear. |
| **Queue payloads** (light profile) | The serialized message and the raw destinations. | The job is `ShouldBeEncrypted`: Laravel encrypts the payload with `APP_KEY`. A durable relay job carries ids only. |
| **Receipts** | Only a flag that a marker was issued, and the client's `received` / `opened` timestamps. | The marker is an HMAC with a dedicated key ring and is never logged or stored. |

### Encryption at rest

`Padosoft\MobilePush\Core\Privacy\TokenCipher`: **AES-256-GCM**. The envelope is `v<key version>:` followed by base64url of `nonce(12) || tag(16) || ciphertext`, with a fresh random nonce per value. The 32-byte AES key is derived from the ring key with HKDF (so the same ring material is not used directly for two purposes). The associated data binds the key version, the scope and a context (the destination kind, or `ledger-message|<effect id>`), so a value copied to another scope or column fails authentication.

### The keyed index

`TokenIndex`: `v<key version>:` plus `HMAC-SHA256(key, scope || kind || value)` with length-prefixed fields. It lets the registry and the safe-mode allowlist find a token without storing it in clear. Because it is keyed with a secret **separate from the encryption key**, a leaked database does not allow guessing tokens offline, and indexes cannot be correlated with another system's hashes. Within one scope and key version the same token always has the same index (that is how a lookup works), so two equal rows are visible as equal to anyone who can read the table.

## Key rings and rotation

There are three independent rings. Each is a map **version to key**: a base64 string of at least 32 random bytes (an optional `base64:` prefix is accepted); versions are integers 1 to 9999 (receipts: 1 to 255).

| Ring | Keys | Current version | Env (version 1 only) |
|---|---|---|---|
| Lookup index (HMAC) | `privacy.index_keys` | `privacy.index_key_current` (`MOBILE_PUSH_INDEX_KEY_VERSION`, 0 = highest) | `MOBILE_PUSH_INDEX_KEY` |
| Encryption at rest | `privacy.encryption_keys` | `privacy.encryption_key_current` (`MOBILE_PUSH_ENCRYPTION_KEY_VERSION`, 0 = highest) | `MOBILE_PUSH_ENCRYPTION_KEY` |
| Receipt markers (HMAC) | `receipts.keys` | `receipts.key_current` (`MOBILE_PUSH_RECEIPTS_KEY_VERSION`, 0 = highest) | `MOBILE_PUSH_RECEIPTS_KEY` |

**When a ring is empty, version 1 is derived from `APP_KEY`** (HKDF-SHA256 with a fixed label per purpose, so the two rings differ). That works out of the box, with one consequence you must decide on **before** production:

> If you rely on the derived keys and later change `APP_KEY`, every stored token and snapshot becomes unreadable ("unknown key version" / authentication failure) and index lookups stop finding rows. Set **explicit** keys now, so `APP_KEY` can change independently.

Rotate by **adding a version**, never by replacing one. The environment variables only define version 1; for more versions put the map in the published config file:

```php
// config/mobile-push.php (the *_V2 variable names are yours to choose; only the version-1 names are read by the stock config)
'privacy' => [
    'index_keys' => [1 => env('MOBILE_PUSH_INDEX_KEY'), 2 => env('MOBILE_PUSH_INDEX_KEY_V2')],
    'index_key_current' => 2,                 // new writes use version 2; lookups try every version
    'encryption_keys' => [1 => env('MOBILE_PUSH_ENCRYPTION_KEY'), 2 => env('MOBILE_PUSH_ENCRYPTION_KEY_V2')],
    'encryption_key_current' => 2,
],
```

Generate a key with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`.

What happens after you add version 2 and make it current:

- **New writes** use version 2. **Reads** use the version named in the stored value, so old rows keep working.
- Index lookups try the candidates of **every** version in the ring.
- **Existing rows are migrated lazily**: each time a device registers or re-registers (the app does it on launch), its token is re-indexed under the current version and re-encrypted if it uses an older one. A device that never opens the app again keeps its old version.
- There is **no command that re-encrypts everything in one go**. Keep the old version in the ring until you are sure no row still uses it. Removing a version makes the values written with it unreadable. In the ledger that means raw destinations that cannot be decrypted end as `skipped`, message snapshots that cannot be opened are skipped as cancelled, and a fingerprint that names a removed version can no longer be verified, so a replay of that effect key is a conflict.
- Ledger message snapshots and effect fingerprints of existing rows are **not** rewritten; they keep their version until they are pruned.

## Redaction

One component, `Padosoft\MobilePush\Core\Privacy\Redactor`, is the choke point used **before any sink**: logs, events, exceptions, telemetry and CLI output. It is deterministic and idempotent. It removes, from any text:

- every **runtime secret** registered with it (bearer tokens, receipt markers, the destination being logged), in their URL-encoded, JSON-escaped and base64 forms and line by line for multi-line secrets;
- PEM private keys, `Bearer <value>`, JWTs (`eyJ...`);
- APNs and FCM **path segments** (`/3/device/<token>`, `/registrations/<id>`);
- JSON fields whose name contains `token`, `secret`, `password`, `private_key`, `authorization`, `receipt` or `api_key`, and query parameters containing `token`, `secret`, `key`, `signature` or `password`;
- user info in URLs (`https://user:pass@host`).

Text that came **from a provider** (an FCM `error.message`, an APNs reason, an HTML error page, an exception message) goes through a stronger variant that also replaces any opaque run of 32 or more token characters, because providers can echo a token back. Request bodies are reduced to their size (they hold user content), response bodies are truncated to 512 characters, sensitive header names are masked entirely.

| Where | What is shown |
|---|---|
| **Logs** (log channel, database, OpenTelemetry) | The destination as its keyed index plus a masked form; provider reason sanitized to printable ASCII, 120 characters and redacted; no bearer, no marker. |
| **Events** (`PushResultProduced`, `PushBatchCompleted`, `DestinationInvalidated`, ...) | A **view** of the destination (kind, index, masked form, registry reference), never the typed destination object, which would export the token if serialized. |
| **Metrics** (Prometheus) | Bounded labels only: connection, provider, environment, status, reason code, traffic class. Never a user, device or campaign id. Series are capped (`metrics.max_series`, 1000). |
| **CLI** | Tokens are shown masked everywhere: a value shorter than 16 characters becomes `[masked]`, shorter than 32 `ab…yz`, otherwise `abcdef…wxyz`; a value containing anything but printable ASCII is always `[masked]`. `preview` never prints a bearer, a token or a marker. `send` redacts exception text with the tokens it was given. |
| **Pulse and Telescope** | The same views as the events; the package's raw events are hidden from Telescope's generic event watcher. |
| **Dumps** | Value objects hide their content in `__debugInfo`. (`var_export` and `serialize` ignore `__debugInfo`: that is why events carry views.) |

`log.payload` decides how much of the **message** a log record may carry: `none` (default), `keys` (only the dotted key names, never a value) or `full` (the redacted payload, an explicit opt-in). Redaction is pattern-based: it removes credentials and tokens, it cannot know that "Order for Jane Doe" is personal data. Leave it on `none` unless you need it.

```php
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;

$redactor = new Redactor([$iosToken]);                      // a runtime secret to remove wherever it appears

$line = $redactor->string("POST /3/device/{$iosToken} Authorization: Bearer abcdef123456");
// The token and the bearer value are replaced by placeholders such as [redacted:secret]: nothing of them survives.

$shown = TokenMasker::mask($iosToken);                      // "ababab…abab": enough to recognise it, not to use it

$safe = $redactor->providerMessage('The token '.$androidToken.' is not valid');   // opaque 32+ character runs are removed
```

## Secrets: credentials

A connection never holds a secret as a plain config value you could leak in a dump. Each secret is a **source**, exactly one of: `path` (absolute file path), `inline` (the text), `base64` (the file, base64-encoded, handy for one-line environment variables) or `resolver` (a class-string implementing `Padosoft\MobilePush\Core\Auth\SecretResolver`, with a `reference`, for a vault). Never a closure: the config stays cacheable (`config:cache`).

```php
// A resolver reads the secret from your vault; config:cache keeps working because the config holds only a class name.
'key' => ['resolver' => \App\Push\VaultSecrets::class, 'reference' => 'push/ios/AuthKey_ABC123.p8'],
```

```php
final class VaultSecrets implements \Padosoft\MobilePush\Core\Auth\SecretResolver
{
    public function resolve(string $reference): string
    {
        return app('vault')->read($reference);   // returns the PEM text; never log it
    }
}
```

At load time every connection is **inspected** (`mobile-push:doctor`, `mobile-push:credentials:check`): the key parses and is EC P-256, a certificate is not expired and belongs to the declared team, a service-account file is valid. The inspection never contacts a provider. Secret values are held in objects that redact themselves.

**KMS / HSM.** An APNs token connection can name a `signer` (a class or container binding implementing the core `Signer` interface) instead of a `key`, so that the private key never leaves the KMS: the package then only receives signatures and reads no key file. See [APNs credentials](/get-started/credentials-apns#apns-credentials-where-to-put-the-key-secret-sources-keeping-the-key-in-a-kmshsm). There is no `signer` for FCM; use Application Default Credentials there.

## Protecting the endpoints the package can expose

| Endpoint | Default | Protection |
|---|---|---|
| **Prometheus metrics** (`GET {metrics.prometheus.path}`, default `mobile-push/metrics`) | **Off** (`metrics.prometheus.enabled`, `MOBILE_PUSH_PROMETHEUS_ENABLED`) | When on, the default middleware list is `RequireMetricsToken`: the scraper sends `Authorization: Bearer <token>` with the token of `metrics.prometheus.token` (`MOBILE_PUSH_METRICS_TOKEN`). **With no token configured every request is refused (403).** A wrong token gets 401 with `WWW-Authenticate: Bearer`. The comparison is constant-time and does not leak the length. Making it public is explicit: set `metrics.prometheus.middleware` to your own list, for example `[]`. The route is registered at boot and is skipped when routes are cached, so run `route:clear` / `route:cache` after enabling it. |
| **Receipts** (`POST {receipts.route.prefix}/receipts`, default `/mobile-push/receipts`) | **Off** (`receipts.enabled`) | **No authentication by design**: the opaque marker is the proof of possession. The marker is an HMAC with its own key ring, expires (`receipts.marker_ttl`, 7 days) and is bound to one delivery. The response never says why a report was refused (always the same 422). Rate limits: `receipts.rate_limit.per_ip` (120 per minute) and `per_marker` (10 per minute), `max_batch` 20. Add your own middleware in `receipts.route.middleware` if you want more (default `['throttle:mobile-push-receipts']`). |
| **Health** | Console only (`mobile-push:health`) | Nothing is exposed over HTTP by this package. |

Expose the metrics endpoint only on an internal network or behind your own gateway as well. A receipt is a report that the **app possesses the marker**, not proof that a person read anything; see [receipts](/modules/receipts).

## `forget` and `export`

Both work on **one owner** (`Type:id`, the notifiable as the registry and the ledger store it) inside **one scope**: the same owner id in another tenant is never touched ([multi-tenancy](/operations/multi-tenancy)). A class with a morph-map alias is converted to the alias, which is what the tables store.

### `mobile-push:forget`

In this order, each store processed on its own (one failing store never hides the others; the report says which failed and the command can simply be run again, every step is idempotent):

| Step | Effect |
|---|---|
| Ledger: pending deliveries cancelled | The owner's non-terminal deliveries become `cancelled` **first**, so a queued job cannot send them. A request already on the wire cannot be recalled (the report says so). |
| Devices | The owner's device registrations are **deleted, tokens included**. |
| Live Activities | The owner's activities and push-to-start tokens are deleted. |
| Ledger: deliveries erased | The encrypted destination, the owner and the device reference are erased; the encrypted **message snapshot** of every effect addressed to the owner **alone** is erased (and, where an effect records the notifiable it originates from, that reference too). **Non-personal tombstones stay**: state, counters and the non-reversible logical key, which keep a re-submitted job from re-creating rows. |
| Send log | Rows tied to the owner, and rows that name only a destination that was one of the owner's tokens (a send by raw token, no notifiable). A row that belongs to **another** notifiable is never touched, even if it addressed the same phone. |

What it **cannot reach**, printed with every report (and in the `export` notes):

- Database **backups and snapshots**: the rows removed here still exist in them until they expire.
- **External collectors**: OpenTelemetry backends, log files and log channels already written or shipped, monitoring and alerting systems.
- **Jobs still waiting in the queue on the light profile**: their payload stays until a worker runs or discards them; the eligibility check stops a send to a deleted registry device, but a job holding a raw token is not affected.
- The **providers' own systems** (Apple APNs, Google FCM) and notifications already delivered to the device.
- **Your application's own** tables, caches and logs.

Also true, and not hidden by the report: deliveries to **raw tokens** (`toToken()`) carry no owner and cannot be found by owner; the `effect_key` is not erased; and the message snapshot of an effect that **also** addresses somebody else (another owner, a raw token, a topic) is **kept**, because the others still need it to be sent: it holds the same message they receive, and it goes with the effect at the retention prune. The registry method `DeviceRegistrar::detachOwner()` only **detaches** a device from its owner (a "logout"); it is not erasure.

```bash
php artisan mobile-push:forget "App\Models\User:42" --scope=acme --force
```

It asks for confirmation unless `--force` is given; in a non-interactive run without `--force` it refuses. Exit code 0: done. Exit code 1: refused, declined, or a store failed (rerun it). The same service is available in code:

```php
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerEraser;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerRef;

$report = app(OwnerEraser::class)->forget(OwnerRef::parse('App\Models\User:42'), 'default');

$report->succeeded();      // false only when a store failed
$report->notReached();     // the list above, as strings
$report->lines();          // the human-readable report
```

### `mobile-push:export`

Writes one JSON document: `owner`, `scope`, `generated_at` and one section per store (`devices`, `live_activities`, `deliveries`, `effects`, `send_log`), each with a `status`, a `truncated` flag and `rows`. Each store is capped at 10,000 rows (`truncated: true` says so). **Never exported**: clear tokens (they are masked), receipt markers, encrypted envelopes, keyed indexes and message contents (the document says whether a snapshot exists, not what it says). Without `--output` the JSON is the only thing printed (safe to pipe); with `--output` the file is created readable by the current user only (mode 0600) and an existing file is **never overwritten**. The file holds personal data: store and share it accordingly.

## What the package does not protect against

Read this list as part of the threat model.

- **A compromised application server.** The process holds decrypted tokens, signing keys and the cache credentials. If someone can run code as your app, they have everything the app has.
- **Database access plus the keys.** Encryption at rest protects a stolen database dump **without** `APP_KEY` / the ring keys. Whoever has both reads everything. The default (derived) keys are only as secret as `APP_KEY`.
- **The providers.** Apple and Google see every token and every message you send. Content is **not** end-to-end encrypted.
- **Non-token data.** Locale, timezone, device model, app and OS version, the free-form device `metadata`, owner ids, effect keys and outcome reasons are stored in clear.
- **Personal data inside your payloads.** The ledger snapshot is encrypted, but the message exists in clear in the worker, in a light-profile queue job (encrypted with `APP_KEY` by Laravel, readable by anyone with that key), on the provider, and on the lock screen. `log.payload = full` writes the redacted payload to your logs: redaction is pattern-based and cannot recognise names or addresses.
- **Tokens on the command line.** `mobile-push:send` accepts tokens as arguments, visible in the process list and the shell history. It warns; use `--stdin` or `--file`.
- **Replayed receipts.** A marker proves possession, not identity: whoever obtains a marker can report for it, once per event (first write wins).
- **Backups, retention and legal obligations**, as above.
- **Memory of long-lived processes.** Secrets live in process memory for as long as a worker lives. Octane, Swoole and queue workers that serve many tenants were not tested for memory disclosure between requests.
- **Misconfiguration you opt into**: `metrics.prometheus.middleware = []` makes the metrics public; `mode = live` with `live.allow` outside production sends to real devices; an unauthenticated receipts route is a deliberate design.

## Reporting a vulnerability

See `SECURITY.md` in the repository root for the process. Never put a real token, key or marker in an issue.

---

*This page is generated from [`docs/security-and-privacy.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/security-and-privacy.md). To change it, edit that file in the repository, not this site.*
