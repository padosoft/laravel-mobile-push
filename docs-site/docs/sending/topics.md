---
title: "FCM topics"
description: "Two different things, often confused."
---

# FCM topics

Two different things, often confused:

1. **Publishing** one message to a topic or to a condition (a combination of topics): one request to FCM, FCM fans it out.
2. **Managing subscriptions**: subscribing and unsubscribing a *specific installation* to a topic.

| | |
|---|---|
| Enable | nothing to enable: both are part of the FCM driver. Outside production they need `live.allow_topics` on the connection (see [safe mode](/sending/topics#fcm-topics-safe-mode-rules)) |
| Migrations | none. The package keeps **no local record** of who is subscribed to which topic: the subscription lives at Google |
| Provider | FCM only. APNs has no topics; for a fan-out on iOS use broadcast channels ([live activities](/modules/live-activities)) or a [campaign](/modules/campaigns) |

Related pages: [sending](/sending/sending), [messages](/sending/messages), [safe mode](/reliability/safe-mode), [outcomes](/reliability/outcomes), [credentials for FCM](/get-started/credentials-fcm), [commands](/operations/commands).

What was not verified: nothing here has run against real FCM. The subscription client follows Google's Discovery document (revision 20261112) and is tested against a mock server. Whether the 2048-byte payload rule counts exactly as the package counts it is **unverified** (below).

## Publishing to a topic or a condition

```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toTopic('breaking-news')
    ->send(PushMessage::make('Breaking', 'Something happened'));

MobilePush::toCondition("'sports' in topics && 'it' in topics")
    ->send(PushMessage::make('Derby tonight', 'Kick-off at 21:00'))
    ->counts();

$fake->assertSentToTopic('breaking-news');
```

- `toTopic($name)` builds an `FcmTopic`: 1 to 900 characters of `[a-zA-Z0-9-_.~%]`; a leading `/topics/` is removed for you.
- `toCondition($expression)` builds an `FcmCondition`: it must contain `in topics` and be at most 2048 characters. An FCM condition combines **at most 5 topics** (the compiler counts `in topics` occurrences and refuses more, before the network).
- The destination is typed. A string is never guessed to be a topic or a token: `to('news')` throws.
- The connection is the FCM route of your configuration (`mobile-push.routes` / `default`), or name one with `->connection('android')`.

### What you get back

**One result for the whole publication**, not one per recipient. `Accepted` means FCM accepted the publication request; it says nothing about how many phones received it, whether any did, or when. There are no per-recipient results, no counts of recipients, no receipts (no marker is issued for topics) and no notification fallback (a topic is an unknown audience).

What does *not* apply to a topic or a condition: the per-person frequency cap, the device registry's eligibility check, the badge counter (there is no owner), quarantine and invalidation (a topic is not an installation). Your `MobilePush::eligibleUsing()` callback does see the typed destination (so you can allow or deny topics by name).

In a [campaign](/modules/campaigns), a topic or condition audience entry counts as **one delivery per publication**, and the campaign progress says that the number of recipients is not known.

### Limits

| Limit | Value | Status |
|---|---|---|
| Payload for a topic or condition message | 2048 bytes (4096 for a token message) | Google publishes the numbers. The **counting rule** (sum of `strlen(key) + strlen(value)` over `notification`, `data`, the Android `data` maps and the `title`/`body`/`image` of the Android notification; no JSON punctuation, no envelope; the receipt marker and `deep_link` count) is the package's own conservative rule and is **unverified** against Google's behaviour |
| Topics in a condition | 5 | enforced by the compiler |
| iOS-only message capabilities | dropped (lenient mode) or refused | an FCM message has no equivalent for them; see [messages](/sending/messages) |

A message over the limit is refused before the network with a `PayloadTooLargeException`, unless you opted in to `->truncate()`, which only shortens the title and body.

## Managing subscriptions

The package uses the FCM **topic subscriptions** resource (`projects.registrations.topicSubscriptions`), not the legacy Instance ID batch API. The consequences are real:

- **One installation per call.** There is no bulk operation. The `*Many()` helpers multiplex single calls inside one transport send and return one result per input, in the same order, without exceptions.
- The registration is an **FCM registration token or an FID** (`FcmToken` / `FcmFid`).
- Calls are authenticated with the connection's OAuth credential; a 401 refreshes the token **once** and retries once.
- Errors never copy Google's `error.message` (it can echo the registration token): only enum-like `error.status` and `details[].errorCode` are read, and mapped to reason codes (`DestinationUnregistered`, `DestinationMismatch`, `QuotaExceeded`, `AuthForbidden`, ...).

### Idempotency

| Operation | Method | Idempotent how |
|---|---|---|
| subscribe | `subscribe($installation, $topic)` | "already exists" (HTTP 409 / `ALREADY_EXISTS`) is **success**; `TopicSubscription::$alreadyExisted` tells you which |
| unsubscribe | `unsubscribe($installation, $topic)` | always sent with `allowMissing=true`, so "already unsubscribed" is success; the server cannot say which case it was |
| upsert | `patch($installation, $topic, $allowMissing = true)` | returns the existing subscription or creates it |
| read one | `get($installation, $topic)` | a missing subscription is HTTP 404: `TopicApiException::isNotFound()` |
| list | `list($installation, ?$pageSize)` | a generator over pages (default 1000, maximum 2000 per page); the next request is sent only when you advance it |
| many | `subscribeMany($installations, $topic)`, `unsubscribeMany(...)` | `list<TopicBatchResult>` (`ok`, `alreadyInState`, HTTP `status`, `reason`; never the registration id) |

Single calls throw `TopicApiException` (with `reason`, `status`, `isNotFound()`); the batch helpers do not.

### From the command line

```bash
php artisan mobile-push:fcm:topic-subscription subscribe breaking-news --token=TOKEN1 --token=TOKEN2
php artisan mobile-push:fcm:topic-subscription unsubscribe breaking-news --fid=FID1
php artisan mobile-push:fcm:topic-subscription list --token=TOKEN1 --json
printf '%s\n' TOKEN1 'fid=FID2' | php artisan mobile-push:fcm:topic-subscription subscribe breaking-news --stdin
```

Arguments and options: `{action}` (`subscribe`, `unsubscribe`, `list`), `{topic?}` (required except for `list`), `--connection=` (default: the only FCM connection), `--token=*` and `--fid=*` (repeatable), `--stdin` (one installation per line, a token, or `fid=<id>`), `--scope=`, `--json`. The table shows masked installations; an invalid value is reported without being repeated. Exit code 1 when any installation failed, 0 otherwise; in simulation mode it prints that nothing was sent.

### From code

The package does **not** bind a `TopicSubscriptionClient` in the container: the command builds one on demand, and so must you. The following mirrors what the command does (illustrative; it needs a real FCM connection):

```php
use Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscriptionClient;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$binding = MobilePush::binding('android');
$driver = MobilePush::driver($binding);
$options = MobilePush::defaultOptions($binding);

$client = new TopicSubscriptionClient(
    app(Transport::class),
    $driver->connectionProfile(EndpointRole::FcmApi, 0),
    new EndpointCatalog($options->applicationEnvironment ?? ''),
    $binding->appId,                      // the Firebase project id
    $driver->tokenProvider(0),
    // optional gate: a Closure(OperationKind, Destination): SafeModeDecision (what the command passes);
    // without it the caller has already decided that the call may be made
);

$subscription = $client->subscribe(FcmToken::of($token), FcmTopic::of('breaking-news'));
```

Pass a gate built on `SafeModeGuard` if you want safe mode and simulation to apply as they do for the command (the command asks the guard with `OperationKind::TopicSubscription` and the installation as destination, using the binding's default send options); without a gate **real requests are made even in simulation mode**.

## Safe mode rules

- In **simulate** mode nothing is sent: single calls throw a `TopicApiException` with a simulated reason, batch helpers return failed results with that reason, the command says "Simulation mode".
- Outside production, publishing to a topic or condition and subscription calls are operations that reach many devices or manage provider state, so they need explicit authorisation on the connection: `live.allow = true` **and** `live.allow_topics = true` (`mobile-push.connections.<name>.live.allow_topics`). Without them the operation is blocked with a message that names the missing flag.
- Subscription calls name an installation (a token or FID): outside production that installation also has to be in the connection's `live.only_to` allowlist of test devices. A topic publication is a group destination and relies on `allow_topics` alone.
- In production, execution mode `live` allows both. See [safe mode](/reliability/safe-mode) for the full table.

---

*This page is generated from [`docs/topics.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/topics.md). To change it, edit that file in the repository, not this site.*
