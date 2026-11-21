# Multi-tenancy: scopes, runtime connections and key rotation

Use this page when one application serves several customers ("tenants"), each with their own Apple team, bundle id,
Firebase project or keys, or when credentials live in a database instead of `config/`. The central idea is small:

> Every push belongs to a **scope** (the tenant). Every job carries the **identity** of the app binding it was created
> for. When the job runs, the package finds the *current credential for that identity*; if the identity no longer
> matches, **nothing is sent** and the package never falls back to a default connection.

Related pages: [devices](devices.md), [delivery profiles](delivery-profiles.md),
[security and privacy](security-and-privacy.md), [testing](testing.md), [configuration](configuration.md).

## Scope

A scope is a string such as `acme` or `tenant:42` (letters, digits, `.`, `_`, `:` and `-`, 1 to 64 characters; anything
else throws `InvalidArgumentException`). Empty means `default`.

| Where | How |
|---|---|
| Default for the app | `scope` (`MOBILE_PUSH_SCOPE`, default `default`) |
| Dynamic, per request or job | `MobilePush::scopeUsing(fn () => tenant()->slug)` |
| One send | `MobilePush::scope('acme')->...` |
| One notification | implement `Padosoft\MobilePush\Laravel\Notifications\HasPushScope::pushScope($notifiable)` |
| CLI | `--scope=acme` on the commands that take it: `send`, `preview`, `connections`, `relay`, `forget`, `export`, `deliveries:prune`, `devices:probe-environment`, `fcm:topic-subscription`, `live-activity:start`, `live-activity:list`, `channels:list`, `channels:create`, `channels:sync` (see [commands](commands.md)) |

**The scope always comes from your code, never from client input.** Do not derive it from a request header or payload.
`scopeUsing()` is one callback per process: set it in a service provider (so queue workers have it too) and make it read
the current tenant from something you control.

In a long-lived process (Octane, a queue worker) the manager is shared by every request, so configure it **once, at boot**.
When a per-request setup cannot be avoided, each registration has an undo:

| Set with | Undone with |
|---|---|
| `MobilePush::scopeUsing($resolver)` | `MobilePush::scopeUsing(null)` or `MobilePush::forgetScopeResolver()`: the scope is `mobile-push.scope` again |
| `MobilePush::register($name, $config, $scope)` | `MobilePush::unregister($name, $scope)`: the runtime connection is removed, its cached driver dropped and its pools closed; a configured connection of the same name is used again |
| `MobilePush::resolveConnectionUsing($resolver)` | `MobilePush::forgetConnectionResolver()`: drivers already resolved stay cached until `forgetConnection($name)` or `flush()` |
| `MobilePush::eligibleUsing($check)` | `MobilePush::forgetEligibility($check)`: removes the registrations of that very callable (the same closure instance) and returns how many |
| `FallbackPolicy::allowUsing($check)` | `FallbackPolicy::forget($check)` or `FallbackPolicy::flush()` |

See [runtimes](runtimes.md#5-state-between-requests-octane-style) for what was tested.

### What a scope separates

| Separated by scope | Shared across scopes |
|---|---|
| Device registry rows (a device belongs to one scope) | Rate-limit bucket and throttle gate: keyed by the **provider account** (Apple team, Firebase project) |
| Ledger effects, deliveries, the deduplication key (`scope + binding identity + effect key`) | Authentication breaker: keyed by the **credential** |
| The keyed token index (the scope is part of the HMAC) and the encryption context of tokens and message snapshots | Provider-token cache: keyed by the credential |
| Receipts, send log rows, Live Activity and broadcast-channel rows | HTTP/2 connection pools: keyed by the credential (the scope is deliberately **not** an input) |
| `forget` / `export`: they only ever touch the scope you name | |
| The destination-quality breaker (it is keyed by binding identity, which contains the scope) | |

Practical consequence: two tenants that use **the same Firebase project or Apple team** share its quota accounting. One
tenant's campaign can use up the shared rate-limit bucket (the reserved share for transactional traffic is the only
protection) and a throttling answer pauses everyone on that account. Give tenants their own credentials when you need
isolation.

## Connections for tenants

A connection is looked up by `(scope, name)`. The first source that has it wins:

1. a **runtime registration** for that scope: `MobilePush::register($name, $config, $scope)`;
2. the **resolver**: `MobilePush::resolveConnectionUsing(fn (string $name, string $scope): ?array => ...)`;
3. `mobile-push.connections.<name>` in the config file (any scope).

The array has exactly the keys of a configured connection ([configuration](configuration.md): `driver`, `team_id`,
`key_id`, `key`, `bundle_id`, `environment`, `secondary`, `live`, ...). Secrets are secret **sources** (`path`, `inline`,
`base64`, or a `resolver` class implementing `SecretResolver`), exactly as in the config file.

<!-- example: tenancy-register -->
```php
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// Register one connection for the tenant "acme" (for example while handling a "connect your app" form).
MobilePush::register('ios', [
    'driver' => 'apns',
    'auth' => 'token',
    'team_id' => 'ABCDE12345',
    'key_id' => 'KEY1234567',
    'key' => ['path' => storage_path('app/push/acme/AuthKey_KEY1234567.p8')],
    'bundle_id' => 'com.acme.shop',
    'environment' => 'production',
], 'acme');

$names = MobilePush::connectionNames('acme');   // ['ios']; the registration is validated again at first use
```

A runtime registration lives **in this process only**: it is not saved, and a queue worker never sees it. For anything
that must reach a worker use the **resolver**, which every process (web, worker, scheduler) sets up the same way:

```php
// app/Providers/AppServiceProvider.php, boot()
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::scopeUsing(fn () => app('currentTenant')->slug);

MobilePush::resolveConnectionUsing(function (string $name, string $scope): ?array {
    $app = TenantPushApp::query()->where('tenant', $scope)->where('name', $name)->first();   // cache this lookup

    return $app === null ? null : [
        'driver' => 'apns',
        'auth' => 'token',
        'team_id' => $app->team_id,
        'key_id' => $app->key_id,
        'key' => ['resolver' => \App\Push\VaultSecrets::class, 'reference' => $app->vault_path],
        'bundle_id' => $app->bundle_id,
        'environment' => $app->environment,   // declared by you, never guessed
    ];
});
```

Notes that matter:

- The resolver is called **on every resolution** (every send and every job), so keep it cheap or cache it. The result
  is hashed; the resolved driver is kept in a per-process cache and rebuilt only when the config for that name
  changes. Changing a key in your database therefore takes effect on the next resolution, with no restart.
- Return `null` when the scope has no connection of that name. Never return another tenant's config as a default.
- The configured connection list (`mobile-push.connections`), `mobile-push:doctor`, `mobile-push:connections`,
  `mobile-push:circuit` and `mobile-push:credentials:check` only see configured connections (and runtime ones of the
  current scope). **Connections supplied only by a resolver cannot be listed**, so those commands cannot check them.
- The per-process cache holds at most `connection_cache.size` (`MOBILE_PUSH_CONNECTION_CACHE_SIZE`, default 64)
  resolved connections; the least recently used one is evicted and its HTTP/2 pools are closed. The transport also has a
  global pool limit (default 32 pools per process) with the same eviction. With many tenants per worker expect a new TLS
  handshake when a cold tenant sends. `MobilePush::forgetConnection($name, $scope)` and `MobilePush::flush()` drop cached
  connections by hand.

<!-- example: tenancy-scope-using -->
```php
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// Normally in a service provider's boot(), reading the tenant you resolved from the authenticated request.
MobilePush::scopeUsing(fn () => 'tenant:42');

$scope = MobilePush::currentScope();   // "tenant:42": ledger rows, devices and caches of this send use it
```

<!-- example: tenancy-scoped-send -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// In a test: declare the tenant's connection on the fake (the fake knows `ios` and `android` otherwise).
$fake->connection('ios', 'apns', 'com.acme.shop', 'acme');

MobilePush::scope('acme')->connection('ios')
    ->toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Welcome to Acme'))
    ->each(fn () => null);

$fake->assertSentInScope('acme');
```

## What a job carries

A queued send does **not** carry a secret or a bare connection name. It carries a `BindingRef`, an immutable
description of the identity the push was created for:

| Field | Meaning |
|---|---|
| `scope` | The tenant. |
| `name` | The connection name (as your config or resolver knows it). |
| `provider` | `apns` or `fcm`. |
| `appId` | APNs: the bundle id. FCM: the Firebase project id. |
| `environment` | `production` or `sandbox`. |
| `generation` | A short hash of the credential identities (see key rotation). |

The **identity** of a binding is `(scope, provider, appId, environment)`. The name and the generation are not part of
it. When a job runs, the worker resolves `(scope, name)` again and compares the identity:

| What the worker finds | Result |
|---|---|
| Same identity, same generation | Normal send. |
| Same identity, **different generation** (a key was rotated) | Normal send **with the current credential** for that identity. A generation change alone is not a mismatch. |
| The name now points to another app, provider, environment or scope | **`Skipped` with `binding_mismatch`.** Nothing is sent. No other connection is tried. |
| The name no longer resolves at all | Same. |

There is deliberately **no fallback to a default connection**: sending tenant A's push with tenant B's credentials, or
into the wrong environment, is worse than not sending. How the mismatch is reported depends on the path:

- A direct `send()` (and every path that goes through the manager) returns `Skipped` / `binding_mismatch` results for
  each destination and dispatches the `BindingMismatchDetected` event (with the expected and the current binding).
- The **durable relay** and the **light job** (`SendPushJob`) behave the same way: `BindingMismatchDetected` is dispatched
  **once per run**, each destination is recorded as `Skipped` / `binding_mismatch` with 0 attempts (in the ledger for the
  relay), and those results are passed to the log sinks when they are enabled. The light job still ends cleanly: it is not
  retried and not failed. A listener that throws does not change that.

## Key rotation and generations

The **generation** of a binding is not a counter. It is the first 12 hex characters of a hash of the *non-secret*
identities of the credentials of that connection: for an APNs key the team id and key id, for a certificate its
fingerprint, for FCM the service account's principal and key id (primary and secondary together). Rotating a key
therefore changes the generation by itself; there is nothing to bump.

A rotation without downtime:

1. Create the new key in the Apple / Google console. Keep the old one active.
2. Put the new key in the connection and keep the old as `secondary` (same team, same environment; the package refuses
   a secondary that belongs to another team or project):

   ```php
   'ios' => [
       'driver' => 'apns', 'auth' => 'token', 'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
       'key_id' => 'NEWKEY1234', 'key' => ['path' => storage_path('push/AuthKey_NEWKEY1234.p8')],
       'secondary' => ['key_id' => 'OLDKEY5678', 'key' => ['path' => storage_path('push/AuthKey_OLDKEY5678.p8')]],
       // ...
   ],
   ```
3. Deploy. The generation changes; queued jobs resolve the new credential for the same identity and keep working.
4. `php artisan mobile-push:connections` shows the credential and "(+ secondary)"; `mobile-push:circuit` shows which
   generation the authentication breaker uses (0 = primary, 1 = secondary).
5. Remove the old key from the config and revoke it at the provider.

The **secondary** also protects you the other way round: if the primary starts failing authentication
(`auth.threshold` consecutive failures, default 5), the breaker switches to the secondary once, for every worker, and
raises `CredentialFailedOver`. A failure reported by a worker that still used the old generation is ignored, which is
what keeps the switch single. See [retries and limits](retries-and-limits.md#the-three-circuit-breakers).

The same applies to the **privacy keys** (the keys that encrypt tokens and HMAC the lookup index): they are versioned
rings with their own rotation, covered in [security and privacy](security-and-privacy.md#key-rings-and-rotation).

## Pool isolation per credential

The package opens HTTP/2 connections to APNs and FCM and reuses them. A **pool key** decides which requests may share a
connection. It is derived from: provider, endpoint role, host and port, the **effective credential identity**
(APNs token key: team id + key id; certificate: its fingerprint; FCM: the Google principal), environment, HTTP version,
proxy, CA bundle, the credential generation (primary 0 or secondary 1) and the binding generation. The scope is **not**
an input.

- Two different credentials **never** share a connection, even to the same host. This matters for APNs, which refuses a
  token from a different key on a connection that already used another (`UnrelatedKeyIdInToken`).
- Two tenants that use the **same** credential may share one connection: it is the same identity to the provider.
- After a rotation the old and the new key use different pools.

### What a rotation or a revocation drains

When the configuration of a connection changes (a new key through `MobilePush::register()`, or a new config that a
restarted worker reads), the next send through that name builds a new driver. In that process:

- the previous driver of the same name and scope is dropped from the connection cache and **its transport pools are
  closed**; the new generation opens its own pool (different pool key);
- the provider token of the old generation is **never read again**: the token cache is keyed by the credential identity
  (APNs: team id + key id; FCM: principal + key id), so the new key mints its own JWT, or exchanges its own Google
  access token, on first use.

`MobilePush::unregister()` (and `MobilePush::forgetConnection()`) close the pools of that connection the same way. A job
captured for a binding whose name no longer resolves to the same identity is then `Skipped` / `binding_mismatch`
([what a job carries](#what-a-job-carries)); one that resolves to the same identity uses the current credential.

What is deliberately **not** done: the old token is not deleted from the shared token cache. Other workers may still
run the old configuration until they restart, and deleting the shared entry would make each of them mint again with the
old key (Apple refuses tokens minted too often, `TooManyProviderTokenUpdates`). The entry is unreachable for the new
generation and expires on its own (an APNs JWT is reused for at most 55 minutes, a Google access token until its
`expires_in`). A worker that keeps the old configuration keeps sending with the old key until it restarts; if that key
was revoked at the provider, its requests fail authentication and are classified like any authentication error (with a
`secondary` configured, the breaker switches to it, see above).

## Worked example: two tenants

Acme and Globex each connect their own iOS app. Acme has its own Apple team; Globex too. Both use the durable profile.

1. **Store** each tenant's `team_id`, `key_id`, bundle id and a reference to the `.p8` in your database (the key itself
   in a vault, read by a class implementing `SecretResolver`).
2. **Set the scope** from the authenticated tenant, in a service provider that also runs in queue workers:
   `MobilePush::scopeUsing(fn () => currentTenant()->slug)`. In a job that serves one tenant, set the scope at the start
   of the job (your own tenant-aware job middleware).
3. **Set the resolver** as in the example above. Both tenants name their connection `ios`; the scope tells them apart.
4. **Send**: `MobilePush::to($user)->queue($message)` (or a notification). The ledger rows are written with the tenant's
   scope and binding; the relay job carries only ids.
5. **Rotation**: Globex rotates its key. You update their row. The next resolution rebuilds the driver; jobs already in
   the queue resolve the new credential for the same identity. Acme is untouched: separate pool, separate token cache.
6. **A mismatch**: someone points Globex's `ios` at another bundle id while 500 pushes are queued. The relay finds a
   different identity behind the name and records the 500 as `skipped` / `binding_mismatch`. No push goes out with the
   wrong app. Fix the record, re-queue if you still want them.
7. **Erasure**: `php artisan mobile-push:forget "App\Models\User:42" --scope=globex` touches only Globex's rows. The same
   user id in Acme is left alone.
8. **A noisy neighbour**: if Acme and Globex shared one Firebase project, Acme's campaign would count against the same
   bucket. Use `->bulk()` for campaigns so the reserved share stays free for one-to-one messages.

A phone that moved from user A to user B is handled by the device registry: its generation changes with the owner, and
the eligibility check immediately before the network refuses a push that was queued for A. See [devices](devices.md).

## Limits and not verified

- Scope separation has been exercised in unit and scenario tests with two scopes sharing a database. It has **not** been
  exercised with thousands of tenants, with Octane, or with a database that enforces row-level security.
- Eviction of the connection cache closes pools; behaviour when a tenant's resolver is slow or flaky is whatever your
  resolver does: an exception from it aborts that resolution.
- A rate-limit bucket is per provider account. There is no per-tenant quota inside one account.
