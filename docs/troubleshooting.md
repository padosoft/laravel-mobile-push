# Troubleshooting

Find your symptom, read the cause, apply the remedy. When you do not find it, go to the [reason catalog](#reason-catalog)
at the end: every APNs reason, every FCM error and every package reason code, with what the package does about it.

Start with these four commands; most problems show up in one of them (all are read-only; none sends a push, except
`doctor --live`, which says so):

| Command | Answers |
|---|---|
| `php artisan mobile-push:doctor` | Is the environment, the configuration, the credentials, the cache, the queue timeouts and the database sound? |
| `php artisan mobile-push:connections` | Which connections exist, what mode is effective, which environment, which credential, days left. |
| `php artisan mobile-push:circuit` | Is a circuit breaker open, or is the provider account paused? |
| `php artisan mobile-push:explain <reason>` | What a reason (`BadDeviceToken`, `UNREGISTERED`, `quota_exceeded`) means and what to do. |

Details and exit codes of every command are in [commands](commands.md).

## By symptom

### Nothing arrives and the status is `simulated`

**Cause.** The effective mode is `simulate`: no request was sent, by design. With `mode = auto` (the default) a send is live
only when `APP_ENV` is exactly `production`. `APP_ENV=prod`, `staging`, `local` and a typo all simulate.

**Check.** `php artisan mobile-push:connections` prints `Effective mode: simulate (configured "auto", APP_ENV "local")`.
`mobile-push:send` ends with exit code **3** and a yellow `SIMULATED: NOTHING WAS SENT` banner.

**Remedy.**
- In production: make `APP_ENV=production`, or set `MOBILE_PUSH_MODE=live`. Clear the config cache (`php artisan
  config:clear`, then `config:cache` again): a cached config keeps the old values.
- On staging, to push to your own phone: [safe mode](safe-mode.md#sending-for-real-from-a-non-production-environment).

### `skipped` with `policy_safe_mode`

**Cause.** `mode = live` outside production, and the connection does not allow it, or the destination is not on the
allowlist, or the operation (topic, broadcast, channel management) needs its own switch. The `hint` names the missing
setting.

**Remedy.** Set `live.allow = true` on the connection and add the test device to `live.only_to`; for a topic or broadcast
also `live.allow_topics` / `live.allow_broadcast`. See [safe mode](safe-mode.md). Remember `only_to` holds raw test tokens
that are converted to keyed indexes at load: a token for the wrong scope or kind does not match.

### `skipped` with `policy_ineligible`

**Cause.** Your `MobilePush::eligibleUsing()` callback said no (an opt-out), or the device registry refused the device: it
is quarantined or invalidated, its owner changed since the push was queued, or its generation moved on. This is the
mechanism that stops a push queued for user A from reaching a phone handed to user B.

**Remedy.** Not an error. If you did not expect it, look at the device row (`state`, `state_reason`, `generation`) and at
your eligibility callback. See [devices](devices.md).

### `BadDeviceToken` on every token

**Cause.** Almost always the **environment**: the tokens were issued by the other APNs environment than the connection
talks to. Builds installed from Xcode use the sandbox; TestFlight and App Store builds use production (Apple's rule, not
something this package measures). Less often the token is malformed or truncated.

**Check.** `php artisan mobile-push:connections`: the `Env` column is the environment of the **connection**, which must
match the build that produced the tokens. Check the environment your app declared when it registered the token.

**What the package did.** The destination is **quarantined**, not deleted, and not retried. A burst of them opens the
destination-quality breaker (bulk traffic is then `skipped` / `circuit_open`).

**Remedy.** Fix the connection's `environment` (or register a second connection for the other environment), have the app
register again so the label is right, or run the [environment probe](#environment-probe) for the quarantined ones. Then
`mobile-push:circuit --reset` if a breaker opened.

### Works in TestFlight / debug builds but not in production (or the reverse)

Same family: the two environments have different tokens and different endpoints, and the package **never guesses** the
environment from a token. A registration carries the environment the app declared. Send debug-build tokens through a
connection with `environment => 'sandbox'` and store builds' tokens through `environment => 'production'`. If your
signing key is restricted to one environment, APNs answers `BadEnvironmentKeyIdInToken` (an authentication failure that
feeds the credential breaker): use a key enabled for both, or one key per environment. A mixed registry is exactly what the
[environment probe](#environment-probe) repairs.

### `410` on devices that still have the app

**What it means.** APNs answered 410 `Unregistered` or `ExpiredToken`: the token is no longer active for that topic. The
package invalidates the registration only when the answer is a real 410 with that reason **and** its timestamp is later
than your registration; a bare 410, or an old answer about a previous generation, never invalidates a newer
registration.

**Causes seen in practice.** The user reinstalled or restored the app (new token), the app registers its token once and
never again after it changed, or the token was registered under the wrong bundle id.

**Remedy.** Make the app send its current token to your registration endpoint on **every launch** and whenever the token
changes: a re-registration reactivates the device with a new generation. Check `last_seen_at` in the device row.
`mobile-push:devices:prune` removes invalidated rows after `devices.prune.invalidated_days` (30), so do not schedule it
more aggressively than your re-registration cadence.

### FCM `QUOTA_EXCEEDED`, or a `deferred` result with `quota_exceeded`

**Cause (one of three).**
1. Google really answered `QUOTA_EXCEEDED` / `RESOURCE_EXHAUSTED` (429): the project, device or topic quota.
2. **Your own rate limit** (`guards.rate.fcm`, default 500,000 messages per minute) ran out: no request was sent and the
   hint says "the provider account is throttled or the send rate limit is reached".
3. The **throttle gate**: an earlier throttling answer (an APNs 503 for the Apple team, an FCM `UNAVAILABLE` for the
   project) paused the whole account for at least 60 seconds (15 minutes after an APNs 503).

**Check.** `php artisan mobile-push:circuit` shows `Provider throttle: sending is paused until ...`.

**Remedy.** Nothing for the pause itself: the destinations were not lost; they are `Deferred` with `nextAttemptAt` and
are retried inside their budget (a **synchronous** `send()` returns them to you: queue them if they matter). For a big
campaign use `->bulk()` so one-to-one traffic keeps its share, lower `guards.rate.fcm` to your real quota, and spread the
load.

### Authentication errors (`auth_token_invalid`, `auth_token_expired`, `auth_forbidden`, `circuit_open`)

| Reason | Usual cause | Remedy |
|---|---|---|
| `ExpiredProviderToken` that **repeats** | The server clock is wrong (APNs refuses tokens signed more than about an hour from its own clock). | Fix NTP/chrony on every worker. |
| `InvalidProviderToken`, `UnrelatedKeyIdInToken`, `MissingProviderToken` | Wrong `key_id` / `team_id`, a revoked key, or a key that does not belong to the team. | `mobile-push:credentials:check`; compare the key id in the Apple console. |
| `BadEnvironmentKeyIdInToken` | The key is restricted to the other environment. | Use a key enabled for this environment. |
| `BadCertificate`, `BadCertificateEnvironment` | An expired or wrong-environment client certificate. | Renew it, or move to a `.p8` key. |
| FCM `PERMISSION_DENIED` | The service account lacks the Firebase Cloud Messaging API permission, or the project id is wrong. | Grant the role / correct `project_id`. |
| FCM `THIRD_PARTY_AUTH_ERROR` (`APNS_AUTH_ERROR`) | The APNs key or certificate **configured inside the Firebase project** was refused by Apple. | Fix it in the Firebase console; not the package's credential. |
| `skipped` / `circuit_open` | The authentication breaker opened after repeated failures. | Fix the credential first, then `mobile-push:circuit --reset`. A configured `secondary` credential takes over once automatically. |

### The queue sends twice

**Cause.** The queue handed a job to a second worker while the first was still sending: `retry_after` (SQS: visibility
timeout) is shorter than the job timeout, or the worker timeout is shorter than the job. The package's timeout chain
exists to prevent exactly this. The package's defaults fit Laravel's stock `retry_after` of 90 (job timeout 50 s), so
this happens when `queue.timeout` was raised without raising `retry_after`, or the application's own `retry_after` is
lower. A worker process killed and restarted mid-job gives the same symptom.

**Check.** `php artisan mobile-push:doctor`, section "Queue timeouts": any `violation` or `not verifiable` line. Raise
`retry_after` to at least what `mobile-push:install` prints (61 s with the defaults), or lower `queue.timeout`. Declare
`queue.worker_timeout` / `queue.visibility_timeout` so the chain can be verified. See
[queues and Horizon](queues-and-horizon.md#the-timeout-chain).

**Other causes.** The same effect queued twice by your code without an effect key (no deduplication: use
`->effectKey()` on the [durable profile](delivery-profiles.md)); a notification both `ShouldQueue` and queued by the
channel (the package has one owner of the queue per notification, but custom code can add a second).

### Unknown outcomes after a deploy

**Cause.** Workers were stopped while a request was in flight (`kill -9`, a container removed, a supervisor that does not
wait): the request may have left, so the destination is `outcome_unknown` with the hint "The worker stopped after the request
may have left: the provider may have accepted it". On the durable profile the sweeper writes it when the lease
expires; on the light profile a failed job records its destinations as unknown in the send log.

**Remedy.** Stop workers gracefully: `php artisan queue:restart` (workers finish the current job), `horizon:terminate`
with a supervisor `stopwaitsecs` at least as long as the worker `timeout` (55 s with the defaults), and a container
`terminationGracePeriod` to match. **Do not resend unknown outcomes blindly**: an alert may already have been
delivered. Replaceable messages were already retried by policy. Count them with `mobile-push:health`, the send log
(`status = outcome_unknown`) or the `outcome_unknown` results.

### Pushes queued, but nothing leaves the queue

| Check | Remedy |
|---|---|
| Is a worker listening to the **right queue**? Package jobs go to `push` and `push-bulk` (`queue.queue`, `queue.bulk_queue`), not `default`. | Start workers with `--queue=push` and `--queue=push-bulk`; see [queues](queues-and-horizon.md#what-to-run). |
| Durable profile: is the **scheduler** running? | Run `php artisan schedule:run` every minute. Without it a lost relay job is never recovered. `mobile-push:health` reports a ledger backlog (warning at 300 s, failure at 3600 s). |
| Durable profile: any rows stuck? | `php artisan mobile-push:sweep`, then `php artisan mobile-push:relay` (inline). |
| Is the mode `simulate`? Then the rows end `simulated`. | See the first symptom. |
| Are jobs failing (Horizon failed jobs, `failed_jobs`)? | Read the exception; `SendPushJob::failed()` records the destinations as unknown. |

### `Guard state is too contended to update`

**Cause.** The shared cache store cannot do the compare-and-set the breakers and the rate limiter need: the store is
`file` on several hosts, `null`, unreachable, or has no lock support.

**Remedy.** Point `cache.store` (`MOBILE_PUSH_CACHE_STORE`) at `redis`, `memcached`, `database` or `dynamodb`. `doctor`
reports "The cache store cannot take locks".

### `table "mobile_push_effects" already exists` when migrating

**Cause.** This was a defect of earlier builds: an enabled module registered its own migration files with the migrator
**and** `mobile-push:install` published copies under new names, so `migrate` ran both sets. The package now follows one
rule ([delivery profiles](delivery-profiles.md#migrations)): migrations are **published, not auto-loaded**, unless
`MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true`, in which case nothing is published and a published copy wins over the package
file. Every migration is also idempotent (`Schema::hasTable` / `hasColumn` guards), so a second run changes nothing.

**Remedy.** If your database still holds the failure from an older build: upgrade, and run `php artisan migrate` again
(the guarded migrations skip what exists). Choose one source from now on: published copies (default) or autoload.

### `IdempotencyConflictException`

**Cause.** Durable profile: the same effect key was used for a **different message** within `ledger.dedupe_seconds`.
Nothing was written or queued; the first effect is untouched.

**Remedy.** Make the key specific to the content ("order-42-shipped", not "order-42"), or send the same message again.
The conflict can also appear for an identical message when the encryption key version that fingerprinted the first one was
removed from the ring ([security](security-and-privacy.md#key-rings-and-rotation)).

### `skipped` with `binding_mismatch`

**Cause.** A queued push was created for one app identity (scope, provider, app id, environment) and the connection name
now points to another, or no longer resolves. The package refuses to fall back to a default connection.

**Remedy.** Restore the connection, or re-queue the push for the current one. See [multi-tenancy](multi-tenancy.md#what-a-job-carries).

### `payload_too_large`, or the text is cut

**Cause.** The compiled payload exceeds the provider limit (APNs 4 KB, 5 KB for VoIP; FCM 4096 bytes, 2048 for topics).
**Remedy.** Shorten it, send less `data`, or opt in to truncation of the visible text with `->truncate()`. `php artisan
mobile-push:preview` shows the size against the limit and the warnings.

### Live Activity update ignored

The server can only tell you what it sent and what APNs answered; `Accepted` is not "the activity changed". Check in this
order:

1. `php artisan mobile-push:live-activity:list`: the **server state**. Updates go only to an `active` activity (the app
   has reported its update token). `requested` means the app has not reported a token yet; `ending`, `ended` and `expired`
   accept nothing more. `expired` is the package's local expiry (`live_activities.local_expiry.hours`, 8): iOS ends an
   activity after about 8 hours; the server stops sending.
2. **Two updates in the same second**: the second is held as the row's pending transition (latest state wins) and goes out
   from the next second through `mobile-push:live-activity:flush` (scheduled every minute by the package when the module
   is on). Run `flush` to see it.
3. **High-priority allowance**: above `live_activities.high_priority.max_per_hour` (20) updates are sent at priority 5, which
   iOS may delay. Use `--low-priority` for chatty updates.
4. An answer of `channel_unknown`, `feature_not_enabled` or `topic_invalid` (the Live Activity topic suffix) is in the result
   and in `explain`.
5. If APNs accepted it and the activity did not change, the content state did not decode in the app (a field mismatch between
   your server's state and the Swift `ContentState`) or the activity belongs to another device. The package cannot see that.

### The metrics endpoint answers 403 or 401

403: no token configured (`metrics.prometheus.token` / `MOBILE_PUSH_METRICS_TOKEN`): the endpoint refuses everyone on
purpose. 401: wrong or missing `Authorization: Bearer <token>`. 404: the endpoint is off
(`MOBILE_PUSH_PROMETHEUS_ENABLED`) or the routes were cached before it was enabled (`php artisan route:clear`). See
[security and privacy](security-and-privacy.md#protecting-the-endpoints-the-package-can-expose).

### `doctor` says "not verifiable"

It means exactly that: the doctor could not read a value (the `queue:work --timeout` of a worker, an SQS visibility
timeout, the Horizon config of another environment). It is a warning (exit 1), never a pass. Declare the value in the
config key the remedy names, or check it by hand.

## Telling the cases apart in code

A short checklist of what to log when a push "did not arrive": the **status**, the **reason code**, the **raw reason**
and the **hint**. Never log the token.

<!-- example: troubleshooting-triage -->
```php
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// In a test: the answer was lost after the request was written.
$fake->unknownFor(ApnsDeviceToken::of($iosToken));

$lines = [];
MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Order shipped'))
    ->each(function (SendResult $r) use (&$lines): void {
        $lines[] = sprintf(
            'status=%s code=%s raw=%s fallback_allowed=%s',
            $r->status->value,
            $r->reason->code->value,
            $r->reason->rawReason ?? '-',
            $r->status->allowsFallback() ? 'yes' : 'no',   // "no" for OutcomeUnknown: nothing else is started from it
        );
    });

// $lines[0] starts with "status=outcome_unknown" and ends with "fallback_allowed=no".
```

## Environment probe

`php artisan mobile-push:devices:probe-environment` repairs registrations whose APNs environment label is wrong. It is the
**only** operation in the package that ever tries a device token on the *other* APNs environment, and it never runs by
itself.

**What it does.** For each quarantined APNs device token (selected with `--quarantined`, or one with `--device=<id>`) it
sends **one silent background push** (expiring in 5 minutes) through the connection of the **same app** (same scope, same
bundle id) on the **other** environment, one device at a time:

| Answer | Outcome | Effect |
|---|---|---|
| Accepted | `relabelled` | The registration moves to the other environment's binding and is reactivated under a **new generation**. |
| `BadDeviceToken` again | `still_rejected` | Stays quarantined. |
| Any credential refusal (`InvalidProviderToken`, `BadEnvironmentKey*`, ...) or an open circuit | `authentication_error` | The **whole run stops**; the other devices are `not_contacted`. A key limited to one environment answers this way. |
| Anything else | `untouched` | Nothing changes. |
| Accepted, but the same token is already registered for the other environment | `conflict` | Nothing changes. |
| The device changed while the probe ran | `changed` | Nothing changes. |
| Mode `simulate` | `not_sent` | No request. |
| Safe mode refused | `blocked` | No request. |
| No (or no unique) connection of that app on the other environment | `no_connection` | Name one with `--connection`. |
| The stored token cannot be decrypted | `unreadable` | Key rotation problem. |

**Why it is explicit.** `BadDeviceToken` is ambiguous (a malformed token, or a good token of the other environment). A
normal send must never quietly retry a token against the other environment: it would hide a configuration error, push
to a different app environment than you intended, and burn APNs error budget. So the package quarantines and **waits for
an operator**. You must pass `--allow-cross-environment` to acknowledge it, and confirm interactively (or `--force`).

**Risks.**
- A probe **is a real push** (silent, to the device). If accepted, it reaches the phone.
- Every **rejected** token counts against Apple's error budget; `--limit` (default 10, maximum 100) bounds a run.
  There is no time-based rate limit: run it in batches.
- It needs a second connection for the other environment with a key allowed for it.
- It needs the devices module, the live mode, and (outside production) the allowlist: a quarantined token not in
  `live.only_to` is `blocked`.
- It changes registry rows (binding, environment, generation, state). Nothing is undone automatically.
- Not verified against the real APNs services: the behaviour of environment-restricted keys is read from Apple's
  documentation and the package's tests, not measured.

Exit codes: 0 every device got a clean answer (relabelled, still rejected, or not sent), 1 something needs attention or the
run was refused, 2 the run was stopped by an authentication error. Full options in [commands](commands.md).

## Reason catalog

Every reason the package knows, generated from `php artisan mobile-push:explain --all --json` (the command always shows
the catalog of the installed version; use `php artisan mobile-push:explain <reason>` for one entry).

**Reading the tables.** *Package code* is the provider-neutral `ReasonCode`. *Status* is the status the destination gets
**if it ends there**. For reasons that are retried the status shown is the one after the budget is exhausted (`failed`),
but you will normally see `deferred` first. The raw provider string is in `rawReason` of the result.

### APNs reasons

| Reason | HTTP | Package code | Status | Cause and remedy | What the package does |
|---|---|---|---|---|---|
| `BadCollapseId` | 400 | `request_invalid` | `rejected` | apns-collapse-id is longer than 64 bytes: shorten the collapse id. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadDeviceToken` | 400 | `destination_rejected` | `rejected` | APNs refused the device token: it is malformed or belongs to the other environment (sandbox vs production). The registration is quarantined, not deleted; check the environment the app declared. | Does not retry: the same request would fail again. Final status: rejected. Quarantines the registration (kept, but not used until it is probed or re-registered). |
| `BadExpirationDate` | 400 | `request_invalid` | `rejected` | apns-expiration is not a valid UNIX time (channels with "no message stored" accept only 0): fix the expiry. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadMessageId` | 400 | `request_invalid` | `rejected` | apns-id is not a canonical lowercase UUID: let the package generate it. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadPriority` | 400 | `request_invalid` | `rejected` | apns-priority must be 10, 5 or 1 and allowed for the push type: fix the priority. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadTopic` | 400 | `topic_invalid` | `rejected` | apns-topic is invalid: use the bundle id with the suffix the push type requires (.voip, .push-type.liveactivity, ...). | Does not retry: the same request would fail again. Final status: rejected. |
| `DeviceTokenNotForTopic` | 400 | `destination_mismatch` | `rejected` | The device token belongs to another app (topic): the registration is quarantined; check the bundle id bound to this token. | Does not retry: the same request would fail again. Final status: rejected. Quarantines the registration (kept, but not used until it is probed or re-registered). |
| `DuplicateHeaders` | 400 | `request_invalid` | `rejected` | A header was sent twice: report this as a package bug. | Does not retry: the same request would fail again. Final status: rejected. |
| `IdleTimeout` | 400 | `transport_not_sent` | `failed` | APNs closed the idle connection without processing the request: it is resent on a fresh connection. | Retries within the retry budget with exponential backoff. Final status: failed. |
| `InvalidPushType` | 400 | `request_invalid` | `rejected` | apns-push-type is not valid for this request (broadcast accepts only LiveActivity): fix the push type. | Does not retry: the same request would fail again. Final status: rejected. |
| `MissingDeviceToken` | 400 | `request_invalid` | `rejected` | The request path has no device token: report this as a package bug. | Does not retry: the same request would fail again. Final status: rejected. |
| `MissingTopic` | 400 | `topic_invalid` | `rejected` | apns-topic is required with a multi-topic certificate: configure the bundle id on the connection. | Does not retry: the same request would fail again. Final status: rejected. |
| `PayloadEmpty` | 400 | `payload_invalid` | `rejected` | The payload is empty: add at least an aps dictionary. | Does not retry: the same request would fail again. Final status: rejected. |
| `TopicDisallowed` | 400 | `topic_invalid` | `rejected` | Pushing to this topic is not allowed (for broadcast, use the bare bundle id without a push-type suffix): check the topic and app capabilities. | Does not retry: the same request would fail again. Final status: rejected. |
| `FeatureNotEnabled` | 400 | `feature_not_enabled` | `rejected` | Broadcast push is not enabled for this bundle id: enable the capability in the Apple developer account. | Does not retry: the same request would fail again. Final status: rejected. |
| `MissingChannelId` | 400 | `channel_unknown` | `rejected` | apns-channel-id is missing: pass the channel id returned by APNs at creation. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadChannelId` | 400 | `channel_unknown` | `rejected` | apns-channel-id is not valid base64 or is too long: use the exact id returned by APNs. | Does not retry: the same request would fail again. Final status: rejected. |
| `ChannelNotRegistered` | 400 | `channel_unknown` | `rejected` | The broadcast channel does not exist (deleted, or other environment): resync channels and create it again if needed. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadRequestParams` | 400 | `payload_invalid` | `rejected` | The JSON body contains an attribute APNs does not recognise: remove it. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadRequestPayload` | 400 | `payload_invalid` | `rejected` | The JSON body could not be parsed by APNs: report this as a package bug. | Does not retry: the same request would fail again. Final status: rejected. |
| `MissingPushType` | 400 | `request_invalid` | `rejected` | apns-push-type is missing: broadcast requests require LiveActivity. | Does not retry: the same request would fail again. Final status: rejected. |
| `CannotCreateChannelConfig` | 400 | `channel_limit_reached` | `rejected` | The app reached the maximum number of broadcast channels: delete channels no longer used before creating new ones. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadCertificate` | 403 | `auth_certificate_invalid` | `failed` | The client certificate is invalid: renew or replace it. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `BadCertificateEnvironment` | 403 | `auth_certificate_invalid` | `failed` | The client certificate is for the other environment: use the matching certificate or endpoint. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `ExpiredProviderToken` | 403 | `auth_token_expired` | `failed` | The provider token is stale (older than one hour, or the server clock is wrong): it is refreshed once and the push retried; check the clock if it repeats. | Retries within the retry budget with exponential backoff. Final status: failed. Refreshes the provider token once and retries. |
| `Forbidden` | 403 | `auth_forbidden` | `failed` | APNs does not allow this action for this credential: check the key or certificate capabilities. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `InvalidProviderToken` | 403 | `auth_token_invalid` | `failed` | The provider token (JWT) was refused: check the key id, team id and that the .p8 key matches them and the environment; the credential breaker decides any failover. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `MissingProviderToken` | 403 | `auth_token_invalid` | `failed` | No provider token nor client certificate was presented: configure the connection credential. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `UnrelatedKeyIdInToken` | 403 | `auth_token_invalid` | `failed` | The token key id differs from the one first used on this connection: tokens of different keys must use separate connections (pool key). | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `BadEnvironmentKeyIdInToken` | 403 | `auth_token_invalid` | `failed` | The signing key is restricted to the other environment: use a key enabled for this environment. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `BadEnvironmentKeyInToken` | 403 | `auth_token_invalid` | `failed` | The signing key is restricted to the other environment: use a key enabled for this environment. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `TopicMismatch` | 403 | `topic_invalid` | `rejected` | The topic is not among the bundle ids of the token or certificate: use a credential of the same team/app. | Does not retry: the same request would fail again. Final status: rejected. |
| `BadPath` | 404 | `request_invalid` | `rejected` | The request path is invalid: report this as a package bug. | Does not retry: the same request would fail again. Final status: rejected. |
| `MethodNotAllowed` | 405 | `request_invalid` | `rejected` | The HTTP method is not allowed for this path: report this as a package bug. | Does not retry: the same request would fail again. Final status: rejected. |
| `ExpiredToken` | 410 | `destination_unregistered` | `rejected` | The device token has expired: the registration is invalidated; the app registers a new token at next launch. | Does not retry: the same request would fail again. Final status: rejected. Invalidates the registration (the device is no longer sent to). |
| `Unregistered` | 410 | `destination_unregistered` | `rejected` | The device token is no longer active for this topic (app uninstalled or notifications reset): the registration is invalidated. | Does not retry: the same request would fail again. Final status: rejected. Invalidates the registration (the device is no longer sent to). |
| `PayloadTooLarge` | 413 | `payload_too_large` | `rejected` | The payload exceeds the APNs limit (4 KB, 5 KB for VoIP): shorten it. | Does not retry: the same request would fail again. Final status: rejected. |
| `TooManyProviderTokenUpdates` | 429 | `auth_token_throttled` | `failed` | The provider token was renewed too often (Apple: at most once every 20 minutes): token renewals slow down; this is never a reason to switch credential. | Retries within the retry budget with exponential backoff. Final status: failed. Slows provider token renewals (never switches credential). |
| `TooManyRequests` | 429 | `destination_rate_limited` | `failed` | Too many consecutive pushes to the same device token: retried later; send less often to this device. | Retries within the retry budget and waits at least apns.device_rate_limit_delay (default 60 seconds). Final status: failed. |
| `InternalServerError` | 500 | `provider_internal` | `failed` | APNs internal error: retried after the delay Apple recommends for 5xx (15 minutes). | Retries within the retry budget and waits at least the 5xx delay Apple recommends (apns.server_error_retry_delay, default 15 minutes). Final status: failed. |
| `ServiceUnavailable` | 503 | `provider_unavailable` | `failed` | APNs is unavailable: retried after the delay Apple recommends for 5xx (15 minutes); sends to APNs are throttled meanwhile. | Retries within the retry budget and waits at least the 5xx delay Apple recommends (apns.server_error_retry_delay, default 15 minutes). Final status: failed. Pauses sending to this provider account for a while (throttle gate). |
| `Service Unavailable` | 503 | `provider_unavailable` | `failed` | APNs is unavailable: retried after the delay Apple recommends for 5xx (15 minutes); sends to APNs are throttled meanwhile. | Retries within the retry budget and waits at least the 5xx delay Apple recommends (apns.server_error_retry_delay, default 15 minutes). Final status: failed. Pauses sending to this provider account for a while (throttle gate). |
| `Shutdown` | 503 | `provider_shutdown` | `failed` | The APNs server is shutting down: retried after the delay Apple recommends for 5xx (15 minutes) on a new connection. | Retries within the retry budget and waits at least the 5xx delay Apple recommends (apns.server_error_retry_delay, default 15 minutes). Final status: failed. |

### FCM errors

| Error | HTTP | Package code | Status | Cause and remedy | What the package does |
|---|---|---|---|---|---|
| `INVALID_ARGUMENT` | 400 | `payload_invalid` | `rejected` | FCM refused the request as invalid (usually the payload: field values, data keys, sizes, TTL): fix the message. The registration is not touched. | Does not retry: the same request would fail again. Final status: rejected. |
| `UNREGISTERED` | 404 | `destination_unregistered` | `rejected` | The registration token or FID is no longer valid (app uninstalled, token rotated): the registration is invalidated. | Does not retry: the same request would fail again. Final status: rejected. Invalidates the registration (the device is no longer sent to). |
| `SENDER_ID_MISMATCH` | 403 | `destination_mismatch` | `rejected` | The token belongs to another Firebase project (sender id): the registration is quarantined; check the project bound to this token. | Does not retry: the same request would fail again. Final status: rejected. Quarantines the registration (kept, but not used until it is probed or re-registered). |
| `QUOTA_EXCEEDED` | 429 | `quota_exceeded` | `failed` | The FCM sending quota was exceeded (project, device or topic): retried after at least one minute; sends to this project are throttled. | Retries within the retry budget and waits at least fcm.throttled_min_delay (default 60 seconds). Final status: failed. Pauses sending to this provider account for a while (throttle gate). |
| `UNAVAILABLE` | 503 | `provider_unavailable` | `failed` | FCM is temporarily unavailable: retried with backoff honouring Retry-After; sends to this project are throttled. | Retries within the retry budget and waits at least fcm.throttled_min_delay (default 60 seconds). Final status: failed. Pauses sending to this provider account for a while (throttle gate). |
| `INTERNAL` | 500 | `provider_internal` | `failed` | FCM internal error: retried with backoff. | Retries within the retry budget and honours the Retry-After header. Final status: failed. |
| `THIRD_PARTY_AUTH_ERROR` | 401 | `auth_third_party` | `rejected` | The APNs certificate/key or Web Push credential configured in the Firebase project was refused by Apple or the browser vendor: fix it in the Firebase console (not the package credential). | Does not retry: the same request would fail again. Final status: rejected. |
| `APNS_AUTH_ERROR` | 401 | `auth_third_party` | `rejected` | Legacy name of THIRD_PARTY_AUTH_ERROR: the APNs credential configured in the Firebase project was refused; fix it in the Firebase console. | Does not retry: the same request would fail again. Final status: rejected. |
| `INVALID_ARGUMENT` | 400 | `payload_invalid` | `rejected` | FCM refused the request as invalid: fix the message. The registration is not touched. | Does not retry: the same request would fail again. Final status: rejected. |
| `UNAUTHENTICATED` | 401 | `auth_token_expired` | `failed` | The OAuth access token was refused (expired or revoked): it is refreshed once and the message retried. | Retries within the retry budget with exponential backoff. Final status: failed. Refreshes the provider token once and retries. |
| `PERMISSION_DENIED` | 403 | `auth_forbidden` | `failed` | The service account may not send for this Firebase project: grant it the "Firebase Cloud Messaging API" permission or use the right project id. | Does not retry: the same request would fail again. Final status: failed. Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential). |
| `NOT_FOUND` | 404 | `destination_unregistered` | `rejected` | FCM answered NOT_FOUND without an FCM error detail: it may be the destination or the project id; the registration is kept (it is invalidated only on an explicit UNREGISTERED). | Does not retry: the same request would fail again. Final status: rejected. |
| `RESOURCE_EXHAUSTED` | 429 | `quota_exceeded` | `failed` | FCM quota exhausted: retried after at least one minute; sends to this project are throttled. | Retries within the retry budget and waits at least fcm.throttled_min_delay (default 60 seconds). Final status: failed. Pauses sending to this provider account for a while (throttle gate). |
| `UNAVAILABLE` | 503 | `provider_unavailable` | `failed` | FCM is temporarily unavailable: retried with backoff honouring Retry-After; sends to this project are throttled. | Retries within the retry budget and waits at least fcm.throttled_min_delay (default 60 seconds). Final status: failed. Pauses sending to this provider account for a while (throttle gate). |
| `INTERNAL` | 500 | `provider_internal` | `failed` | FCM internal error: retried with backoff. | Retries within the retry budget and honours the Retry-After header. Final status: failed. |
| `DEADLINE_EXCEEDED` | 504 | `provider_unavailable` | `outcome_unknown` | FCM timed out while processing: the message may or may not have been accepted; retried only if replaceable. | Reports the outcome as unknown: the message may have been processed. It is retried only when it is replaceable (or by explicit opt-in), and never triggers a fallback or an invalidation. Final status: outcome_unknown. |

### Package reason codes

| Code | Status | Cause and remedy | What the package does |
|---|---|---|---|
| `none` |  | No reason: the destination was accepted by the provider. | Nothing to do. |
| `destination_unregistered` |  | The provider says the device token is no longer valid (app uninstalled, token expired or rotated). The device registry should forget it. | Invalidates the registration; never retries. |
| `destination_rejected` |  | The provider refused the token: malformed, or it belongs to the other environment (APNs sandbox vs production). Check the environment the app declared at registration. | Quarantines the registration instead of deleting it; no retry. |
| `destination_mismatch` |  | The token belongs to another app, topic or Firebase project than the connection. Check the bundle id or project bound to this token. | Quarantines the registration; no retry. |
| `destination_rate_limited` |  | Too many pushes to the same device in a short time. Send less often to this device. | Retries later with a minimum delay. |
| `channel_unknown` |  | The APNs broadcast channel does not exist (deleted, other environment, wrong id). Sync the channels and recreate it if needed. | No retry. |
| `channel_limit_reached` |  | The app reached the maximum number of APNs broadcast channels for this environment (about 10,000, or the configured cap). Delete channels you no longer use before creating new ones. | No retry: creating the channel again fails the same way. |
| `payload_invalid` |  | The provider could not accept the payload (unknown fields, values out of range, bad JSON). Fix the message. | No retry: the registration is not touched. |
| `payload_too_large` |  | The payload is bigger than the provider limit (APNs 4 KB, FCM 4096 bytes, 2048 for topics). Shorten it, or opt in to truncation of the visible text. | No retry. |
| `request_invalid` |  | A header, path or method was refused. Usually a package bug or an invalid collapse id / priority / expiration: report it with the raw reason. | No retry. |
| `topic_invalid` |  | The APNs topic (bundle id and its suffix) is wrong for this push type or credential. Check the bundle id of the connection. | No retry. |
| `feature_not_enabled` |  | The capability (for example broadcast push) is not enabled for this app in the provider account. Enable it in the Apple developer account. | No retry. |
| `auth_token_expired` |  | The provider token is stale. Check the server clock if it repeats. | Refreshes the token once and retries the push. |
| `auth_token_invalid` |  | The provider token or key was refused: wrong key id, team id, environment or revoked key. Fix the connection credentials. | Reports a failure to the authentication breaker; it opens after repeated failures and may fail over to the secondary credential. |
| `auth_token_throttled` |  | The provider token was renewed too often (Apple: at most once every 20 minutes). | Slows token renewals; never switches credential. |
| `auth_certificate_invalid` |  | The client certificate was refused: expired, revoked, or for the other environment. Renew it or use a .p8 key. | Reports a failure to the authentication breaker. |
| `auth_forbidden` |  | The credential is not allowed to do this (service account without the FCM permission, key without the capability). | Reports a failure to the authentication breaker; no retry. |
| `auth_third_party` |  | FCM could not authenticate to Apple or the browser vendor with the credential configured in the Firebase project. Fix it in the Firebase console (not the package credential). | No retry. |
| `quota_exceeded` |  | The provider quota was exceeded (per project, device or topic; or the channel limit for APNs broadcast). | Retries after at least a minute and pauses sending to the account. |
| `provider_unavailable` |  | The provider is temporarily unavailable. | Retries with backoff, honouring the provider delay, and throttles the account meanwhile. |
| `provider_internal` |  | The provider had an internal error. | Retries with backoff. |
| `provider_shutdown` |  | The provider server is shutting down. | Retries on a new connection after the provider delay. |
| `transport_not_sent` |  | The request provably never left: DNS, connect or TLS failed before any request byte was written. | Safe to retry; counted as a known failure when the budget ends. |
| `transport_unknown` |  | The request was written but the answer was lost (reset, timeout, GOAWAY): the provider may or may not have processed it. | Status outcome_unknown. Retried only for replaceable messages (unknown_outcome.retry); no fallback, no invalidation. |
| `response_unparseable` |  | The provider answered with something the package cannot read, so the outcome is not known. | Status outcome_unknown; treated like a lost answer. |
| `expired` |  | The logical expiry of the message passed before an attempt could be made. Nothing was sent. | Final: no more attempts. |
| `simulated` |  | Simulation mode: no request was sent. Set MOBILE_PUSH_MODE=live (outside production also live.allow=true and the device in live.only_to) to send for real. | Nothing is sent; the result is final. |
| `validated` |  | FCM validate_only: Google validated the request and did not deliver it. It is not a delivery test. | Final: nothing is delivered. |
| `policy_ineligible` |  | The application consent or eligibility check said this destination must not receive the push now. Nothing was sent. | Skipped: no attempt, no fallback. |
| `policy_frequency_capped` |  | The frequency cap of the message policy was reached for this person. Nothing was sent. | Skipped: no attempt. |
| `policy_safe_mode` |  | Safe mode blocked a live send outside production (live.allow is off, the destination is not in live.only_to, or topics/broadcast need explicit authorisation). Nothing was sent. | Skipped: no connection was opened. |
| `circuit_open` |  | A circuit breaker is open (repeated credential failures, or too many bad destinations in a bulk send). Run mobile-push:circuit to see it. | Skipped or deferred until the cooldown ends, then probed with a few requests. |
| `binding_mismatch` |  | The connection name no longer points to the app, provider or environment the push was prepared for (for example after a reconfiguration). Nothing was sent and no other connection was used. | Skipped: re-create the push for a configured connection. |
| `cancelled` |  | The send was cancelled before an attempt was made. | Final: no more attempts. |
| `retry_budget_exhausted` |  | The retry budget (attempts or time) ended while the provider was still failing. | Final: the destination is reported as failed. |
| `unknown` |  | The provider answered with something this version does not know. Read the raw reason shown next to it. | No automatic action beyond the status; please report the raw reason. |
