# Safe mode: nothing leaves your machine by accident

A push notification is the one side effect you cannot take back. A developer running a seed script against a copy of the
production database must not wake up thousands of phones. So the package decides **before it opens a connection** whether
a send may touch the network at all, and the default answer outside production is **no**.

Related pages: [outcomes](outcomes.md), [commands](commands.md), [testing](testing.md),
[getting started](getting-started.md), [troubleshooting](troubleshooting.md#nothing-arrives-and-the-status-is-simulated).

## The three modes

`mode` (`config/mobile-push.php`, env `MOBILE_PUSH_MODE`, default `auto`):

| `mode` | Network | Result for a valid message |
|---|---|---|
| `auto` | **Live only when `APP_ENV` is exactly `production`**, otherwise simulated | The provider's answer, or `Simulated` |
| `live` | Yes, subject to the rules below | The provider's answer, or `Skipped` (blocked) |
| `simulate` | Never | `Simulated` |

Any other value throws a `ConfigurationException` ("expected auto, live or simulate"). The comparison with
`production` is exact: `APP_ENV=prod` or `Production` counts as **not** production, so a misspelled environment
simulates (safe, but nothing is sent: see [troubleshooting](troubleshooting.md#nothing-arrives-and-the-status-is-simulated)).

The check happens in the single send pipeline, for **every** destination, before any external effect, and it covers the
CLI, queue workers, credential failover, fallback sends and channel management alike. There is no path around it.

## What happens outside production

With `mode = auto` and a local or staging `APP_ENV`, every destination ends as `Simulated`:

- no DNS lookup, no connection, no token signing request, **no byte on the wire**;
- the message is still compiled, so an invalid message is reported exactly as it would be live;
- the result carries a hint that says how to send for real.

```text
$ php artisan mobile-push:send --platform=ios --title=Hello aabbccdd...
 SIMULATED: NOTHING WAS SENT
No request reached APNs or FCM: the effective mode is "simulate".
...
1 destination(s): 1 simulated. Exit code 3 (simulated: nothing was sent).
```

`Simulated` is **not** `Validated` and **not** `Accepted`:

| Status | Network | Means | Does not mean |
|---|---|---|---|
| `Simulated` | none | Nothing was attempted, by design. | Not sent, not checked, not validated. |
| `Validated` | an authenticated call to FCM | Google parsed the request (`validate_only`) and found it valid. | Not delivered to a device. A real call: it needs live mode too. |
| `Accepted` | a real send | The provider accepted the request. | Not delivered or seen. |

An expired message is `Expired` even in simulation (the expiry check comes first).

Reading it in code (the statuses are the same on every path, so one `match` serves a send, a job and a command):

<!-- example: safe-mode-branch -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$notes = [];
MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hello'))
    ->each(function (SendResult $result) use (&$notes): void {
        if ($result->status === SendStatus::Simulated) {
            $notes[] = 'simulation mode: nothing was sent';
        } elseif ($result->status === SendStatus::Skipped && $result->reason->code === ReasonCode::PolicySafeMode) {
            $notes[] = $result->reason->hint;               // names the setting that is missing
        }
    });
```

## Sending for real from a non-production environment

Sometimes you do want to push to **your own phone** from staging. Two things must both be true, per connection:

1. `MOBILE_PUSH_MODE=live` (or `mode = live`);
2. the connection says `live.allow = true` **and** the destination is in `live.only_to`.

```php
// config/mobile-push.php
'connections' => [
    'ios' => [
        'driver' => 'apns',
        // ... team_id, key_id, key, bundle_id, environment ...
        'live' => [
            'allow' => (bool) env('MOBILE_PUSH_APNS_LIVE_ALLOW', false),
            'only_to' => array_filter(explode(',', (string) env('MOBILE_PUSH_APNS_LIVE_ONLY_TO', ''))),
        ],
    ],
],
```

```bash
# .env on the staging server
APP_ENV=staging
MOBILE_PUSH_MODE=live
MOBILE_PUSH_APNS_LIVE_ALLOW=true
MOBILE_PUSH_APNS_LIVE_ONLY_TO=<your own device token>
```

| `live.*` key | Meaning |
|---|---|
| `allow` | `true` allows live sends from this non-production app. Default off. |
| `only_to` | The test destinations that may receive them. A string is a device token of the connection's default kind (APNs device token, FCM registration token); `['kind' => 'apns_voip_token', 'token' => '...']` names another individual kind. They are converted to **keyed HMAC indexes at load time** and are never stored, logged or printed raw. |
| `allow_topics` | Also allow FCM topics and conditions (and topic subscription). |
| `allow_broadcast` | Also allow publishing to APNs broadcast channels. |
| `allow_channel_management` | Also allow creating and deleting broadcast channels. |

Every other destination is **blocked**: `Skipped` with the reason code `policy_safe_mode` and a hint that names the
missing setting. Examples of hints: *"Blocked: live sending outside production is not enabled for this binding."*,
*"Blocked: the destination is not in this binding's live.only_to allowlist of test devices."*,
*"Blocked: this operation reaches many devices or manages provider state and needs explicit authorisation
(live.allow_topics=true)."*

### What stays blocked unless you ask

| Operation | Needs |
|---|---|
| A push to one installation (device token, FID, VoIP, Live Activity update or push-to-start token) | `live.allow` **and** the destination in `live.only_to` |
| An FCM topic or condition, topic subscription | `live.allow` and `live.allow_topics` (no allowlist: the audience is the topic) |
| An APNs broadcast channel publication | `live.allow` and `live.allow_broadcast` |
| Creating or deleting a broadcast channel (`channels:create`, `channels:delete`, sync clean-up) | `live.allow` and `live.allow_channel_management` |

A group that can reach many people is therefore never unlocked by an allowlist of one phone.

### An inconsistency blocks; it never degrades to a real send

The guard refuses (in every environment, production included, `Skipped` / `policy_safe_mode`) when the pieces
contradict each other: a destination of another provider than the connection (an FCM token on an APNs connection) or a
destination kind that does not fit the operation (a device token published as a broadcast). The guard in the core
library also refuses an operation that asks for a provider environment other than the connection's (the environment
probe passes the environment of the connection it uses, so that check always agrees there; ordinary sends pass none).

### In production

With `APP_ENV=production` and `mode` `auto` or `live`, an operation that is consistent with its connection is allowed:
no allowlist applies. The `live` block is only about non-production. `mobile-push:doctor` warns about the two risky
combinations: **live outside production** ("real requests can leave this machine") and, in `mobile-push:health`,
**simulate in production** ("SIMULATE MODE IN PRODUCTION: no push is being sent to any provider").

## Official endpoints only

The package never sends to an address you give it, and a message cannot name a host. Every URL comes from a fixed
catalogue by **role**:

| Role | Host (production / sandbox) |
|---|---|
| APNs send | `api.push.apple.com` / `api.sandbox.push.apple.com`, port 443 (default) or 2197 (`port` on the connection) |
| APNs channel management | `api-manage-broadcast.push.apple.com:2196` / `api-manage-broadcast.sandbox.push.apple.com:2195` |
| APNs broadcast publication | `standard` (default): the APNs send host; `api-broadcast`: `api-broadcast.push.apple.com` / `api-broadcast.sandbox.push.apple.com` (the `broadcast_host` key of the connection) |
| FCM | `fcm.googleapis.com` |
| Google token exchange | `oauth2.googleapis.com` |

Path segments (a token, a bundle id, a project id) are validated against a strict pattern and percent-encoded, so a value
cannot inject a path, a query or a fragment. TLS verification of the certificate chain and the host name is always on;
there is no option to turn it off. The `proxy` and `ca_bundle` settings of a connection exist for corporate networks;
a proxy is used only when you set it explicitly (the environment's `HTTPS_PROXY` is ignored on purpose).

The one exception is an **endpoint override** for a local mock server. It exists in the core library, is accepted only
with test credentials and never when `APP_ENV` is `production`, and **there is no configuration key for it**: it is
meant for the package's own tests.

Which of the two Apple hosts accepts broadcast publications for your app is something to verify by integration; Apple's
documentation names both. That is why `broadcast_host` is a choice and not a guess.

## What the command line does

| Command | Simulate | Blocked (live outside production, not allowed) |
|---|---|---|
| `mobile-push:send` | Prints a banner `SIMULATED: NOTHING WAS SENT`, the results as `simulated`, **exit code 3** | Results `skipped` / `policy_safe_mode`, exit code 1 |
| `mobile-push:send --validate-only` | `simulated` (nothing sent), exit 3 | `skipped`, exit 1 |
| `mobile-push:doctor --live` | **Refused, exit 2**: "the effective mode is simulate ... the probe would prove nothing". Plain `doctor` always works: it sends nothing. | With `live.allow = true` on the connection the probe is sent (its destination cannot reach a device, so it needs no entry in `live.only_to`); without it the probe reports "was blocked before the network" as a warning |
| `mobile-push:preview`, `mobile-push:simulator`, `mobile-push:explain` | Always local; never send; unaffected by the mode | - |
| `mobile-push:devices:probe-environment` | Rows `not_sent`; no request | Rows `blocked` |
| `mobile-push:fcm:topic-subscription` | Reported as simulated, exit 0 | Failed rows, exit 1 |
| `mobile-push:live-activity:*`, `mobile-push:channels:*` | "Simulation mode: nothing was sent and nothing was written." exit 0 | "Blocked by safe mode: ... was not attempted." exit 1 |
| `mobile-push:relay`, `mobile-push:sweep` | They run the normal pipeline: sends are simulated, rows end `simulated` | `skipped` |

Two rules that the Live Activity and channel commands follow, because "nothing was sent" must also mean "nothing was
written": the mode is asked **before** the first database write, so a simulated or blocked start leaves no row in the
Live Activity tables and no transition number is spent; a blocked channel creation writes no `creating` row.

`mobile-push:send`'s exit code 3 means "simulated, nothing was sent". It is a deliberate non-zero: a script that checks
`$? -eq 0` will not mistake a simulated send for a real one. A **mixed** result still gives the highest-priority code:
unknown outcome 4, then partial 1, then simulated 3.

## Testing code that sends

Your test suite should not depend on the mode at all: use `MobilePush::fake()` (intent) and `MobilePush::fakeTransport()`
(the bytes). They force a live pipeline against a fake transport, so a test cannot reach Apple or Google. See
[testing](testing.md). The fakes refuse to start outside `APP_ENV=testing` unless `testing.allow_fakes` is true.

## Not verified

The guard is covered by unit and scenario tests with a counting mock server (a sandbox command with production-like
configuration opens **no** connection). It has not been exercised against the real APNs and FCM services from a
non-production environment: the allowlist logic is local, but whether the providers accept your staging key and
sandbox tokens is the question `doctor --live` and a canary push answer.
