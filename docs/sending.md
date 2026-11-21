# Sending

Every way to address a push, every option of a send, and how to read what happened. Message content (titles, native APNs/FCM fields, limits) is in [messages.md](messages.md); queues are in [queues-and-horizon.md](queues-and-horizon.md); what each status means in depth is in [outcomes.md](outcomes.md).

All examples marked as runnable on this page are executed by the documentation test suite under `MobilePush::fake()`, with `$iosToken`, `$androidToken` and `$user` (a notifiable with one iOS and one Android destination) in scope.

## The shape of a send

```
MobilePush::[scope()|connection()] -> to…() -> [options] -> send($message) | queue($message)
```

- The entry points (`MobilePush::to()`, `toToken()`, `toTokens()`, `toTopic()`, `toCondition()`, `connection()`, `scope()`) return a `Padosoft\MobilePush\Laravel\Sending\PendingSend`.
- A `PendingSend` is **immutable**: every method returns a modified copy, so you can keep one and reuse it.
- `send()` sends now and returns a `SendReport`. `queue()` hands the send to a queue worker and returns an effect id (see [Queueing](#queueing)).
- A failure of one destination is a result in the report, **never an exception**. Exceptions are reserved for input or configuration that is invalid as a whole (an unknown connection, a raw token without a platform, a malformed token, a message that cannot be compiled for the first destination of a connection), and they are thrown before that connection sends anything. A payload over the size limit is a per-destination `Rejected` result with `payload_too_large`, not an exception.
- A send whose targets span several connections runs them one after the other. If the message is invalid for the second connection only (for example a `subtitle()`, which FCM cannot carry, sent to a user with an iPhone and an Android phone), the first connection has already sent when the exception is thrown. Make a unified message valid for every platform you target (`->lenient()`, or the platform overlays: see [messages.md](messages.md)).

## Addressing

### `to()`: typed destinations, notifiables, iterables

`to()` accepts:

| You pass | What happens |
|---|---|
| A typed destination (`ApnsDeviceToken::of(...)`, `FcmToken::of(...)`, `FcmFid::of(...)`, `FcmTopic::of(...)`, `FcmCondition::of(...)`, `ApnsVoipToken`, `ApnsLiveActivityToken`, `ApnsPushToStartToken`, `ApnsBroadcastChannel`) | Sent to that destination. The provider comes from the class, never from the shape of the string. |
| An object with `routeNotificationForPush()` returning typed destinations | Its destinations are resolved when `send()`/`queue()` runs. Raw strings are refused. |
| A model using `HasPushDevices` (device registry) | Its **active** registered devices in the current scope, each on the connection it was registered on. Needs `MOBILE_PUSH_DEVICES_ENABLED=true` and the migration: see [devices.md](devices.md). |
| An on-demand notifiable (`Notification::route('push', ...)`) | Its `routeNotificationFor('push')` destinations. |
| An array, a `Collection` or any iterable of the above | Each item is added. |
| A `Generator` with a named connection | Streamed to the provider lazily (useful for very large lists). Without a named connection the generator is read up front, because routing has to see each destination. |
| A plain string | `InvalidDestinationException`: a raw token needs its platform (`toToken()`). |

<!-- example: sending-to-user -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::to($user)->send(PushMessage::make('Order shipped', 'Arrives Friday'));

// $user has one iPhone and one Android phone: two destinations, two results.
// $report->count() === 2
```

<!-- example: sending-typed-destinations -->
```php
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::to([
    ApnsDeviceToken::of($iosToken),
    FcmToken::of($androidToken),
])->send(PushMessage::make('Back in stock', 'The red sneakers are available again'))->counts();
```

Each destination class validates its value in `::of()` and throws `InvalidDestinationException` before the network when it is malformed (for example an APNs token that is not hex). The accepted formats are listed in [contracts/identity.md](contracts/identity.md#typed-destinations).

### `toToken()` and `toTokens()`: raw tokens, platform required

A device token your app sent you is just a string. The package never guesses from its shape whether it is an APNs or an FCM token, nor which APNs environment it belongs to, because a wrong guess sends a push to the wrong provider or quarantines a healthy device. So the platform is a required argument.

<!-- example: sending-to-token -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Your code', '482913'));

MobilePush::toTokens(Platform::Android, [$androidToken])
    ->send(PushMessage::make('Your code', '482913'));
```

`Platform::Ios` builds an `ApnsDeviceToken`, `Platform::Android` an `FcmToken`. For any other kind (VoIP, Live Activity, FID) use the typed class with `to()`.

### `toTopic()` and `toCondition()`: FCM topics

<!-- example: sending-topic -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::toTopic('news')->send(PushMessage::make('Breaking', 'Something happened'));

MobilePush::toCondition("'sport' in topics && 'italy' in topics")
    ->send(PushMessage::make('Goal!', 'Italy 1 - 0'));
```

- A leading `/topics/` is removed (`FcmTopic::of('/topics/news')` is `news`).
- Topic and condition messages have a **2048-byte** payload limit (4096 for single devices).
- Outside production, topics and conditions are blocked even with `live.allow` unless the connection sets `live.allow_topics`: see [safe-mode.md](safe-mode.md).
- Topics have no per-device result: an `Accepted` topic send means Google accepted the publication. No receipt, frequency cap, fallback or invalidation applies to topics.
- Subscribing devices to topics is a separate operation (`mobile-push:fcm:topic-subscription`, see [commands.md](commands.md)).

## Connections and scopes

A **connection** is one app on one provider (`config/mobile-push.php`, `connections.<name>`). Without a named connection:

- a typed destination or raw token goes to the **only** connection of its provider among `routes` (`MOBILE_PUSH_ROUTES`; null = every configured connection). Two APNs connections in the routes and no name = a `ConfigurationException` asking you to name one;
- a registry device goes to the connection it was registered on.

<!-- example: sending-connection -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::connection('ios')
    ->toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Hello', 'From the ios connection'));
```

Sending an APNs destination through an FCM connection (or the reverse) is an `InvalidDestinationException` before the network. A send whose targets span several connections (an iPhone and an Android phone) returns one report that streams the results of each connection in turn.

A **scope** is a tenant boundary (default `default`, `MOBILE_PUSH_SCOPE`). Connections, provider tokens, breakers, rate limits, device rows and ledger rows are all kept per scope. Set it per send with `->scope('acme')` (or `MobilePush::scope('acme')`), or globally with `MobilePush::scopeUsing(fn () => ...)`. It is never read from client payload. Runtime connections (`MobilePush::register()`) and on-demand resolution (`MobilePush::resolveConnectionUsing()`) are covered in [multi-tenancy.md](multi-tenancy.md).

<!-- example: sending-scope -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::scope('default')
    ->toToken(Platform::Android, $androidToken)
    ->send(PushMessage::make('Hi', 'Tenant-scoped send'))
    ->counts();

$fake->assertSentInScope('default');
```

`onlyProvider(Provider::Apns|Provider::Fcm)` keeps only the destinations of one provider (the others are left out of the report, not reported as skipped). The `apns` and `fcm` notification channels use it.

## Options of a send

| Method | Effect |
|---|---|
| `validateOnly(bool = true)` | FCM `validate_only`: Google authenticates and validates the request without delivering it. The result is `Validated`, never `Accepted`. APNs has no dry run: a validate-only send through an APNs connection throws `UnsupportedCapabilityException` before the network (use `mobile-push:preview` to see an APNs payload). |
| `bulk()` | Marks campaign / fan-out traffic: it never uses the share of the rate limit reserved for transactional pushes (`guards.rate.reserved_share`) and, when queued, goes to `queue.bulk_queue`. |
| `transactional()` | The default: one-to-one traffic a user is waiting for. |
| `effectKey(?string)` | The logical identity of this push. It makes provider message ids deterministic across retries, and in the durable profile it deduplicates (`ledger.dedupe_seconds`). Without one, `send()` generates a ULID per call. |
| `expiresAt(DateTimeInterface)` | Absolute logical expiry for this send; overrides the message's `expiresAt()`/`ttl()`. After it, nothing is sent (`Expired`). |
| `withGate(DestinationGate)` | An extra per-destination rule evaluated immediately before the network, after the built-in ones. Return a `GateDenial::skip()` or `GateDenial::defer()` for the positions to stop. |
| `forNotification(?Notification)` | Passes the notification to `routeNotificationForPush($notification)` (used by the notification channel). |
| `onlyProvider(?Provider)` | See above. |
| `onQueueConnection()`, `onQueue()`, `delay()`, `afterCommit()` | Only for `queue()`: see [Queueing](#queueing). |

<!-- example: sending-validate-only -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::toToken(Platform::Android, $androidToken)
    ->validateOnly()
    ->send(PushMessage::make('Dry run', 'Validated by Google, never delivered'));

foreach ($report as $result) {
    // $result->status === SendStatus::Validated
}
```

<!-- example: sending-options -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

MobilePush::to($user)
    ->bulk()
    ->effectKey('weekly-digest-2026-47-user-42')
    ->expiresAt(now()->addHours(6))
    ->send(PushMessage::make('Your weekly digest', '5 new articles')->policy('marketing'))
    ->counts();
```

### Gates: consent and your own rules

The package checks every destination immediately before the network. Register your consent check once with `MobilePush::eligibleUsing()`; return `false` (or a `GateDenial`) to stop a destination: it ends `Skipped` with `policy_ineligible`. A per-send rule goes through `withGate()`.

<!-- example: sending-gate -->
```php
use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$quietHours = new class implements DestinationGate
{
    public function check(GateContext $context, array $items): array
    {
        $denials = [];
        foreach ($items as $position => $item) {
            $denials[$position] = GateDenial::skip(ReasonCode::PolicyIneligible, 'Quiet hours');
        }

        return $denials;
    }
};

$report = MobilePush::to($user)->withGate($quietHours)->send(PushMessage::make('Sale', '20% off'));

// $report->countOf(SendStatus::Skipped) === 2
```

The application-wide consent hook, with the signature `fn (Destination $destination, GateContext $context): bool|GateDenial`:

```php
// app/Providers/AppServiceProvider.php, boot()
MobilePush::eligibleUsing(function (Destination $destination, GateContext $context): bool {
    // $context->message, $context->binding, $context->trafficClass, $context->effectKey
    return ! $this->optedOut($destination, $context);
});
```

With `eligibleUsing($check, batch: true)` the callback receives `(GateContext $context, list<SendItem> $items)` once per window and returns an array keyed by position. Consent is never bypassed by `PushMessage::bypassFrequencyCap()`. The frequency cap and the badge counter are gates of the policies module: see [policies.md](policies.md).

## Reading the report

`send()` returns a `Padosoft\MobilePush\Core\Outcome\SendReport`. It is a **streaming cursor**, not an array: results are pulled from the pipeline as you read them, and only the first 1000 are kept in memory, so a send to a million devices stays flat.

> **Consume the report.** The first window of requests leaves when `send()` is called, the rest as you read the report. A report you never read leaves later windows unsent. Call `counts()`, `count()`, iterate it, or call `each()`.

| Method | What it gives |
|---|---|
| `foreach ($report as $result)` / `results()` | Each `SendResult`, as it is produced. |
| `each(fn (SendResult $r) => ...)` | Same, with a callback; returns the report. |
| `counts()` | `['accepted' => n, 'rejected' => n, ...]` for every status (drains the cursor). |
| `countOf(SendStatus)`, `count()`, `accepted()` | Single counters (drain the cursor). |
| `hasUnknown()` | At least one `OutcomeUnknown`. |
| `allAccepted()` | At least one result and all of them `Accepted`. |
| `retained()`, `isTruncated()` | The results kept in memory (at most 1000) and whether there were more. |
| `throw()` | Throws `SendFailedException` when at least one destination is `Rejected`, `Failed`, `OutcomeUnknown` or `Expired`; returns the report otherwise. `Skipped`, `Simulated`, `Deferred` and `Validated` do not throw. |

**Single pass.** A counter drains the cursor; after that, iterating yields only the retained results. Iterate first and count afterwards (the counters stay exact), or ask only for counters.

Each `SendResult` has: `destination` (the typed destination: its `__debugInfo` is redacted), `status` (`SendStatus`), `reason` (`Reason` with `code` (`ReasonCode`), `rawReason` (the provider's text, sanitized) and `hint` (what to do)), `attempts`, `providerMessageId` (`apns-id` or the FCM message name), `httpStatus`, `nextAttemptAt` (for `Deferred`), `destinationAction` (`None`, `Quarantine`, `Invalidate`), `invalidatedAtMs` (APNs 410 timestamp), `deliveryId` (durable profile) and `meta`.

<!-- example: sending-report -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$report = MobilePush::to($user)->send(PushMessage::make('Order shipped', 'Arrives Friday'));

$report->each(function (SendResult $result): void {
    match ($result->status) {
        SendStatus::Accepted => logger()->debug('accepted by the provider', ['id' => $result->providerMessageId]),
        SendStatus::OutcomeUnknown => logger()->warning('unknown outcome: do not resend blindly'),
        default => logger()->info('not accepted', ['code' => $result->reason->code->value, 'hint' => $result->reason->hint]),
    };
});

$counts = $report->counts();          // exact, after the iteration
// $counts['accepted'] === 2
```

<!-- example: sending-throw -->
```php
use Padosoft\MobilePush\Core\Exceptions\SendFailedException;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$fake->failFor($user, ReasonCode::DestinationRejected);

try {
    MobilePush::to($user)->send(PushMessage::make('Hi', 'There'))->throw();
    $thrown = false;
} catch (SendFailedException $e) {
    $thrown = true;   // "2 of 2 push destinations were not accepted (rejected: 2, ...)"
}
// $thrown === true
```

The statuses, in one line each (the full semantics are in [outcomes.md](outcomes.md)):

| Status | Meaning |
|---|---|
| `Accepted` | The provider accepted the request. Not "delivered", not "seen". |
| `Rejected` | The provider refused it for good; resending it unchanged cannot succeed. |
| `Failed` | Known not accepted, and no attempt is left (provider unavailable or unreachable until the retry budget ended). |
| `Deferred` | Not accepted yet; a new attempt is planned at `nextAttemptAt`. |
| `OutcomeUnknown` | The request was written but no usable answer came back: the provider may or may not have taken it. Not a failure. |
| `Expired` | The logical expiry passed before a (further) attempt. |
| `Skipped` | A local rule stopped it: safe mode, consent, frequency cap, open circuit, binding mismatch. |
| `Simulated` | Simulation mode: no network was touched. |
| `Validated` | FCM `validate_only` passed; nothing was delivered. |

## Queueing

<!-- example: sending-queue -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$effectId = MobilePush::to($user)
    ->onQueueConnection('redis')
    ->onQueue('push')
    ->delay(now()->addMinute())
    ->queue(PushMessage::make('Reminder', 'Your table is booked for 8pm'));

$fake->assertQueued();
```

- `queue()` returns the effect id (several ids joined by `,` when the targets span several connections). There is no report: results are known only when a worker runs the job; read them through events, the send log or `mobile-push:health`.
- Set the queue options **before** `queue()`. Defaults: `queue.connection` (null = the app's default queue connection), `queue.queue` (`push`), `queue.bulk_queue` (`push-bulk`) for `bulk()` traffic, `afterCommit(true)`.
- The **light profile** (default) dispatches jobs carrying the encrypted message and the destinations: best effort, no tables; a crashed worker can lose or duplicate pushes. The **durable profile** (`MOBILE_PUSH_DELIVERY_PROFILE=durable`) records the intent in a ledger (inside your database transaction when it runs on the ledger connection) and a relay sends it, recording each outcome before acknowledging. `send()` never writes the ledger in either profile. Details and worker setup: [delivery-profiles.md](delivery-profiles.md) and [queues-and-horizon.md](queues-and-horizon.md).

## Helpers

Two global helpers exist (guarded by `function_exists`):

<!-- example: sending-helpers -->
```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;

mobile_push($user)->send(PushMessage::make('Order shipped', 'Arrives Friday'))->counts();

$report = mobile_push_to_token(Platform::Ios, $iosToken, 'Your code', '482913', ['kind' => 'otp']);
// $report->accepted() === 1
```

- `mobile_push()` without arguments returns the `PushManager` (the same object as the facade); with a target it returns `PendingSend` (`mobile_push($target)` = `MobilePush::to($target)`).
- `mobile_push_to_token(Platform $platform, string $token, string|Message $titleOrMessage, ?string $body = null, array $data = [], ?string $connection = null): SendReport` sends one push now. `$data` is ignored when a `Message` is passed.

## From the command line: `mobile-push:send`

```bash
# tokens from standard input (recommended: arguments are visible in the process list and shell history)
echo "$IOS_TOKEN" | php artisan mobile-push:send --platform=ios --stdin --title="Hello" --body="First push"

php artisan mobile-push:send --platform=android --file=tokens.txt --title="Hi" --data=order=42
php artisan mobile-push:send --topic=news --title="Breaking" --body="..."
php artisan mobile-push:send --user=42 --connection=ios --title="Hi"        # registry devices of user 42
php artisan mobile-push:send --platform=android --stdin --validate-only --title="Dry run" < tokens.txt
php artisan mobile-push:send --platform=ios --stdin --queue --title="Later" < tokens.txt
```

Options: `tokens*` (positional), `--platform=ios|android`, `--connection=`, `--scope=`, `--file=`, `--stdin`, `--user=` (needs the device registry and `auth.providers.users.model`), `--topic=`, `--title=`, `--body=`, `--data=key=value` (repeatable), `--badge=`, `--sound=`, `--type=alert|silent`, `--queue`, `--validate-only` (FCM only), `--json`.

One kind of destination per invocation (tokens, `--user` or `--topic`). Without `--platform` and `--connection`, an interactive run may *propose* a platform from the tokens and asks you to confirm; a non-interactive run (or `--stdin`) refuses. Tokens are always masked in the output.

| Exit code | Meaning |
|---|---|
| 0 | Everything accepted (or validated by FCM `validate_only`); also returned by `--queue` once queued |
| 1 | Partial: at least one destination was rejected, failed, skipped, deferred or expired |
| 2 | Invalid input or configuration: nothing was sent |
| 3 | Simulated: nothing was sent (the effective mode is `simulate`) |
| 4 | At least one unknown outcome (wins over every other code) |

The other commands (`preview`, `explain`, `doctor`, ...) are in [commands.md](commands.md).
