---
title: "Identity"
description: "Defines who an operation belongs to: the tenant (scope), the app binding it was created for, the typed destination it targets, and the pool and cache boundaries that follow from those."
---

# Identity

**Purpose.** Defines who an operation belongs to: the tenant (scope), the app binding it was created for, the typed destination it targets, and the pool and cache boundaries that follow from those. Sources: `docs/PLAN.md` §4.2 and §6.1, and `src/Core/Identity`, `src/Core/Transport/ConnectionProfile.php`.

## Rules

1. **Scope** is a tenant boundary. It is a string, defaults to `default`, and comes from `MobilePush::scopeUsing()` or `->scope()`. It is never taken from client payload (plan §4.2).
2. `Scope::normalize()` trims the value; an empty value becomes `default`; anything else must match `[A-Za-z0-9._:-]` with at most 64 characters, otherwise it throws `\InvalidArgumentException` (note: not a `PushException`).
3. **App binding**: `BindingRef { scope, name, provider, appId, environment, generation }`. The generation changes on every credential rotation or reconfiguration (plan §4.2, code comment).
4. A job carries a `BindingRef` (and the destination reference and generation), never the secret. At consume time the current credential is resolved for that identity. If it does not match, the result is `Skipped` with `ReasonCode::BindingMismatch` and no fallback to the default connection (plan §4.2). A **generation change alone is not a mismatch**: the current credential of the same identity is used. A mismatch is a different identity (scope, provider, app id or environment) behind the same name, or an identity that no longer resolves (orchestrator's resolution, `README.md`). Direct `send()` also dispatches `Sending\Events\BindingMismatchDetected`; the durable relay and the light job handle it in their own way (`docs/delivery-profiles.md`).
5. **Destinations are typed.** Provider, token type, bundle and environment are never inferred from the shape of a string. Each destination is built with `::of($value)`, which normalizes and validates and throws `InvalidDestinationException` (a `ValidationException`) before any network call.
6. Destinations are disjoint classes; a value of one type cannot be passed as another.
7. `ConnectionProfile::$poolKey` is an opaque string. Requests with different pool keys never share a connection. `Core\Driver\PoolKey` builds it from the provider, endpoint role, host and port, the **effective credential identity** (APNs token: team id and key id; mTLS: SHA-256 certificate fingerprint; FCM: the Google principal), environment, HTTP version, proxy (only hashed: it may hold a password), CA bundle, credential generation and binding generation. The scope is deliberately **not** an input: two tenants that share one credential may share a connection; two different credentials never do.
8. The scope applies to caches, locks, deduplication, receipts, `export`/`forget`, devices, Live Activities, campaigns and channel actions (plan §4.2); every ledger and registry query is scoped.

## Scope, binding and generation

| Field | Source of the value | Notes |
|---|---|---|
| `scope` | `scopeUsing()` / `->scope()` | Default `default` |
| `name` | connection name in host config | Not part of `identity()` |
| `provider` | `Provider::Apns` or `Provider::Fcm` | |
| `appId` | APNs: bundle ID. FCM: Firebase project ID | Plan §4.2 also says "+ package" for FCM; the code has no package field (see Plan vs code) |
| `environment` | `Environment::Production` or `Sandbox` | FCM is always `Production` per the enum's docblock |
| `generation` | string, default `'1'` | Bumped on every rotation |

- `identity()`: SHA-256 over scope, provider, appId and environment. Generation and name are excluded. Use it to scope caches, limits and ledger rows.
- `fingerprint()`: `identity()` plus name and generation.
- `sameIdentityAs()`, `toArray()` and `fromArray()` serialize the binding for jobs; the array holds no secret.

## Typed destinations

| Class (`Identity\Destination\`) | `DestinationKind` | Accepted value (from code) |
|---|---|---|
| `ApnsDeviceToken` | `apns_device_token` | Hex, lowercased, even length, at least 64 characters |
| `ApnsVoipToken` | `apns_voip_token` | Same hex rule (PushKit token) |
| `ApnsLiveActivityToken` | `apns_live_activity_token` | Same hex rule |
| `ApnsPushToStartToken` | `apns_push_to_start_token` | Same hex rule |
| `ApnsBroadcastChannel` | `apns_broadcast_channel` | Base64-style characters, 8 to 256 long |
| `FcmToken` | `fcm_token` | Non-empty, at most 4096 characters, no whitespace |
| `FcmFid` | `fcm_fid` | Exactly 22 characters from `[A-Za-z0-9_-]` |
| `FcmTopic` | `fcm_topic` | Leading `/topics/` removed; `[a-zA-Z0-9-_.~%]`, 1 to 900 characters |
| `FcmCondition` | `fcm_condition` | Non-empty, at most 2048 characters, contains `in topics` |

- All destinations share `AbstractDestination`: private constructor, `of()`, `value()`, `ref()`, `withRef()` (returns a copy), `equals()` (same kind and same value, compared with `hash_equals`).
- `__debugInfo()` redacts the value: `[redacted <kind>]`.
- `DestinationKind::isIndividual()` is false for broadcast channels, topics and conditions. No receipt, cap, fallback or invalidation applies to them (plan §8 is consistent with this).

## Types

- `Identity\Scope`, `BindingRef`, `Provider`, `Environment`, `Platform` (`ios`, `android`)
- `Identity\Destination` (interface), `DestinationKind`, `DestinationRef { id, generation (int), ownerType, ownerId }`
- `Identity\Destination\*` (table above) and `AbstractDestination`
- `Transport\ConnectionProfile` (carries `poolKey`)

## Worked cases

**A. Token with the same value in two tenants.**
- Situation: tenant `acme` and tenant `beta` both register the same APNs device token string.
- The package: two `BindingRef`s have different `identity()` values; caches, limits and ledger rows are separate because they are scoped.
- The caller sees: each send is resolved against its own scope; neither tenant's result changes the other's.

**B. Malformed token.**
- Situation: `ApnsDeviceToken::of('abc')`.
- The package: validation fails before any network call.
- The caller sees: `InvalidDestinationException`. No provider is guessed from the string.

**C. Topic with prefix.**
- Situation: `FcmTopic::of('/topics/news')`.
- The package: normalizes to `news`.
- The caller sees: `value() === 'news'`.

**D. Job after credential rotation.**
- Situation: a job was queued for generation 7; the credential is now generation 8.
- The package: the identity is the same, so the current credential (generation 8) is used. Only a different identity (for example the connection now points to another bundle id) is a mismatch.
- The caller sees: the normal result of the send; for a mismatch, `Skipped` with `BindingMismatch`.

## Plan vs code

- Plan §4.2 lists eight destination classes. The code has nine: it adds `ApnsVoipToken` on purpose (a PushKit token is a different registration from a device token). Plan §6.3 mentions VoIP payload limits, so VoIP is in scope.
- Plan §4.2 says FCM `app_id` is "project + package". `BindingRef` only has `appId` (the Firebase project id); the Android package restriction is a message option (`restricted_package_name`), not part of the identity (orchestrator's resolution).
- Plan §4.2 says `Scope` values come from `MobilePush::scopeUsing()`. That is the Laravel layer, not in `src/Core`.
- `FcmCondition` validation only checks for the substring `in topics`: a syntax sanity check on an explicitly typed value, not inference and not a parse of the expression. Google validates it.
- `BindingRef::$generation` is a string, while `DestinationRef::$generation` is an int. The plan does not specify either type.

## Open points

- Whether the FCM `environment` is always `Production` (code docblock) or may be declared by the app (plan §4.2 applies that rule to devices generally). The code always uses `Production` for FCM; nothing in the package needs another value.

---

*This page is generated from [`docs/contracts/identity.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/identity.md). To change it, edit that file in the repository, not this site.*
