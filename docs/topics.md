# FCM topics

Two different things, often confused:

1. **Publishing** one message to a topic or to a condition (a combination of topics): one request to FCM, FCM fans it out.
2. **Managing subscriptions**: subscribing and unsubscribing a *specific installation* to a topic.

| | |
|---|---|
| Enable | nothing to enable: both are part of the FCM driver. Outside production they need `live.allow_topics` on the connection (see [safe mode](#safe-mode-rules)) |
| Migrations | none. The package keeps **no local record** of who is subscribed to which topic: the subscription lives at Google |
| Provider | FCM only. APNs has no topics; for a fan-out on iOS use broadcast channels ([live activities](live-activities.md)) or a [campaign](campaigns.md) |

Related pages: [sending](sending.md), [messages](messages.md), [safe mode](safe-mode.md), [outcomes](outcomes.md),
[credentials for FCM](credentials-fcm.md), [commands](commands.md).

What the test suite does not cover: real FCM. The subscription client follows Google's Discovery document
(revision 20261112) and is tested against a mock server; without credentials the real route answers `401
UNAUTHENTICATED` over HTTP/2 while a made-up sibling route answers 404 (`providers` workflow, 2026-11-22). Whether FCM counts the 2048 bytes exactly as the package
counts them was **not measured** (below).

## Publishing to a topic or a condition

<!-- example: topics-publish -->
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
- `toCondition($expression)` builds an `FcmCondition`: it must contain `in topics` and be at most 2048 characters. An FCM
  condition combines **at most 5 topics** (the compiler counts `in topics` occurrences and refuses more, before the
  network).
- The destination is typed. A string is never guessed to be a topic or a token: `to('news')` throws.
- The connection is the FCM route of your configuration (`mobile-push.routes` / `default`), or name one with
  `->connection('android')`.

### What you get back

**One result for the whole publication**, not one per recipient. `Accepted` means FCM accepted the publication request; it
says nothing about how many phones received it, whether any did, or when. There are no per-recipient results, no counts of
recipients, no receipts (no marker is issued for topics) and no notification fallback (a topic is an unknown audience).

What does *not* apply to a topic or a condition: the per-person frequency cap, the device registry's eligibility check,
the badge counter (there is no owner), quarantine and invalidation (a topic is not an installation). Your
`MobilePush::eligibleUsing()` callback does see the typed destination (so you can allow or deny topics by name).

In a [campaign](campaigns.md), a topic or condition audience entry counts as **one delivery per publication**, and the
campaign progress says that the number of recipients is not known.

### Limits

| Limit | Value | Status |
|---|---|---|
| Payload for a topic or condition message | 2048 bytes (4096 for a token message) | Google documents the numbers (2048 bytes "in the case of messages to topics") and that keys and values both count. The rest of the **counting rule** (sum of `strlen(key) + strlen(value)` over `notification`, `data`, the Android `data` maps and the `title`/`body`/`image` of the Android notification; no JSON punctuation, no envelope; the receipt marker and `deep_link` count; conditions treated like topics) is the package's conservative reading and was **not measured** against FCM |
| Topics in a condition | 5 | enforced by the compiler |
| iOS-only message capabilities | dropped (lenient mode) or refused | an FCM message has no equivalent for them; see [messages](messages.md) |

A message over the limit is refused before the network with a `PayloadTooLargeException`, unless you opted in to
`->truncate()`, which only shortens the title and body.

## Managing subscriptions

The package uses the FCM **topic subscriptions** resource (`projects.registrations.topicSubscriptions`), not the legacy
Instance ID batch API. Google's Instance ID pages (read on 2026-11-22) say the Instance ID server-side API is turned
down on September 29, 2027, that new projects cannot onboard it after October 1, 2026, and that topic subscriptions
move to "the new topic subscription API", which is this resource. The consequences are real:

- **One installation per call.** There is no bulk operation. The `*Many()` helpers multiplex single calls inside one
  transport send and return one result per input, in the same order, without exceptions.
- The registration is an **FCM registration token or an FID** (`FcmToken` / `FcmFid`).
- Calls are authenticated with the connection's OAuth credential; a 401 refreshes the token **once** and retries once.
- Errors never copy Google's `error.message` (it can echo the registration token): only enum-like `error.status` and
  `details[].errorCode` are read, and mapped to reason codes (`DestinationUnregistered`, `DestinationMismatch`, `QuotaExceeded`,
  `AuthForbidden`, ...).

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

Arguments and options: `{action}` (`subscribe`, `unsubscribe`, `list`), `{topic?}` (required except for `list`),
`--connection=` (default: the only FCM connection), `--token=*` and `--fid=*` (repeatable), `--stdin` (one installation per
line, a token, or `fid=<id>`), `--scope=`, `--json`. The table shows masked installations; an invalid value is reported
without being repeated. The command is a thin layer over `MobilePush::topicSubscriptions()` (below), so it goes through the same connection choice and the same safe-mode gate. Exit code 1 when any installation failed, 0 otherwise; in simulation mode it prints that nothing was
sent.

### From code

`MobilePush::topicSubscriptions(?string $connection = null, ?string $scope = null)` returns a `TopicSubscriptionClient`
(the same one the command uses). It is built with the connection's OAuth credential, the package transport, the endpoint
catalog and the **same safe-mode gate as a send**.

- The connection is the FCM route of the scope when `$connection` is null (`mobile-push.routes` / `default`; the only FCM
  connection is picked, and several FCM connections without a route throw a `ConfigurationException` that says how to
  choose), or the name you pass. An APNs connection throws a `ConfigurationException` ("is not an FCM connection"): topic
  subscriptions exist only on FCM. An unknown name throws `UnknownConnectionException`. Both are raised before any request.
- `$scope` is the [multi-tenancy](multi-tenancy.md) scope; null means the current one.

```php
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$topics = MobilePush::topicSubscriptions('android');

$topics->subscribe(FcmToken::of($token), FcmTopic::of('breaking-news'));

// $installations: a list of FcmToken (or FcmFid) objects

foreach ($topics->subscribeMany($installations, FcmTopic::of('breaking-news')) as $i => $result) {
    if (! $result->ok) {
        logger()->warning('Topic subscription failed', ['position' => $i, 'reason' => $result->reason->code->value]);
    }
}
```

This example is not run by the documentation tests: `MobilePush::fake()` does not replace the topic client, so it would
need a real connection and transport.

The result objects:

- `subscribe()`, `get()` and `patch()` return a `TopicSubscription` (the topic name, and for `subscribe()` the
  `alreadyExisted` flag); `unsubscribe()` returns nothing; `list()` yields `TopicSubscription`s page by page.
- `subscribeMany()` / `unsubscribeMany()` return a `list<TopicBatchResult>` in the order of the input: `ok`,
  `alreadyInState` (the subscription already existed, for subscribe), `status` (HTTP status, `0` when no response exists:
  transport failure, simulation or a block) and `reason` (a `Reason`, `ReasonCode::None` when ok). They never carry the
  registration token and never throw per installation.
- Single calls throw `TopicApiException` on failure, including when safe mode simulated or blocked the call (its `reason`
  says which); `isNotFound()` is true for HTTP 404.

**Safe mode.** Every call asks the safe-mode guard first, with the operation `TopicSubscription` and the installation as
destination, using the connection's default send options. In `simulate` mode nothing is sent and the result carries
`ReasonCode::Simulated`. Outside production the call is blocked (`ReasonCode::PolicySafeMode`, no request made) unless the
connection has `live.allow`, `live.allow_topics` **and** the installation in `live.only_to`. In production with the
effective mode `live` it goes through. A blocked or simulated call is therefore not a failure of FCM: check the reason
before suspecting the provider. The pages [safe mode](safe-mode.md) and the table above give the full rules.

The package does not bind a `TopicSubscriptionClient` in the container: ask the manager for one each time (it is cheap,
and it follows the connection's current credentials). It also makes no local record of subscriptions.

## Safe mode rules

- In **simulate** mode nothing is sent: single calls throw a `TopicApiException` with a simulated reason, batch helpers
  return failed results with that reason, the command says "Simulation mode".
- Outside production, publishing to a topic or condition and subscription calls are operations that reach many devices or
  manage provider state, so they need explicit authorisation on the connection: `live.allow = true` **and**
  `live.allow_topics = true` (`mobile-push.connections.<name>.live.allow_topics`). Without them the operation is blocked
  with a message that names the missing flag.
- Subscription calls name an installation (a token or FID): outside production that installation also has to be in the
  connection's `live.only_to` allowlist of test devices. A topic publication is a group destination and relies on
  `allow_topics` alone.
- In production, execution mode `live` allows both. See [safe mode](safe-mode.md) for the full table.
