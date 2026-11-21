---
title: "Testing your application"
description: "Two fakes, for two different questions. Neither needs credentials, a network or a device."
---

# Testing your application

Two fakes, for two different questions. Neither needs credentials, a network or a device.

| | `MobilePush::fake()` | `MobilePush::fakeTransport()` |
|---|---|---|
| Question it answers | "Did my code ask to send the right push, to the right people?" (**intent**) | "What exactly reaches APNs/FCM, and how does the package react to each answer?" (**bytes and behaviour**) |
| What runs | The real manager, routing, gates and report; nothing is compiled for the wire | The whole real pipeline: compilers, safe-mode guard, guards, classifier, retry planner, events, log sinks, device registry updates. Only the HTTP/2 transport is replaced |
| Answers | Scripted outcomes (default `Accepted`) | Scripted HTTP answers (default: APNs 200 / FCM 200) |
| Assertions | `assertSent()`, `assertSentTo()`, `assertQueued()`, ... | `assertApnsRequest()`, `assertFcmRequest()`, `assertRequestCount()`, ... |

They are deliberately separate: the intent fake is not an oracle for the bytes, and the transport fake is not a convenient way to check who received what. Activating one switches the other off.

While a fake is active, every send runs **live** with every destination allowed (the `auto` mode does not simulate and no `live.only_to` allowlist is needed), because nothing can reach a provider anyway. The fakes refuse to start outside `APP_ENV=testing` (`FakeNotAllowedException`) unless you pass `true` (`MobilePush::fake(true)`) or set `mobile-push.testing.allow_fakes`. When your application configures no connection, the fakes provide two: `ios` (APNs, bundle `com.example.fake`) and `android` (FCM, project `fake-project`); `fakeTransport()` gives them keys generated at runtime.

Every example on this page is executed by the package's documentation test, with a `$user` that has one iPhone and one Android phone.

## Setting up

In a Pest test or a PHPUnit test case that extends Laravel's `TestCase`, call the facade:

```php
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

it('notifies the customer when the order ships', function () {
    $push = MobilePush::fake();

    // act: run your code
    // assert: $push->assertSentTo($customer, ...)
});
```

The trait `Padosoft\MobilePush\Laravel\Testing\InteractsWithMobilePush` adds `$this->fakeMobilePush()` and `$this->fakeMobilePushTransport()` and **switches the fakes off after every test** (an `#[After]` hook). Use it in PHPUnit (`use InteractsWithMobilePush;`) or in Pest (`uses(InteractsWithMobilePush::class)->in('Feature');`). `Padosoft\MobilePush\Laravel\Testing\MobilePushTestCase` is an optional abstract base class: Laravel's `TestCase` with the trait applied.

## The intent fake: `MobilePush::fake()`

Every destination of every send is recorded as a `SentPush` and answered with a scripted outcome. Your code still receives a real `SendReport`, so code that reads the report keeps working.

```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;

$push = MobilePush::fake();

// the code under test
MobilePush::to($user)->send(PushMessage::make('Order shipped', 'Arrives Friday')->data(['order_id' => '42']));

$push->assertSentTo($user, fn (SentPush $p) => $p->message instanceof PushMessage
    && $p->message->title === 'Order shipped'
    && $p->message->data['order_id'] === '42');
$push->assertSent(PushMessage::class, 2);        // two destinations: an iPhone and an Android phone
$push->assertSentOnConnection('ios');
$push->assertSentOnConnection('android');
$push->assertNotQueued();
```

### Assertions

| Method | Passes when |
|---|---|
| `assertSent(string\|callable $classOrCallback, ?int $times = null)` | A push matching the message class or `fn (SentPush $p): bool` was sent now (exactly `$times` when given). Counts destinations: one message to two devices is two pushes. |
| `assertNotSent(string\|callable $classOrCallback)` | No sent push matches. |
| `assertNothingSent()` | Nothing was sent now. |
| `assertSentTimes(int $times)` | Exactly this many pushes (destinations) were sent now. |
| `assertSentTo(mixed $notifiableOrDestination, ?callable $callback = null)` | A push went to this destination, notifiable or owner model (any of its destinations), optionally matching the callback. |
| `assertSentOnConnection(string $connection)` | A push was sent through this connection. |
| `assertSentInScope(string $scope)` | A push was sent in this tenant scope. |
| `assertSentToTopic(string $topic)` | A push was sent to this FCM topic. |
| `assertQueued(string\|callable\|null $classOrCallback = null, ?int $times = null)` | A push was queued with `queue()` (anything, when null). |
| `assertNotQueued(string\|callable\|null $classOrCallback = null)` | Nothing matching (or nothing at all, when null) was queued. |

Failure messages list the pushes that were recorded, with masked destinations.

### Reading what was recorded

`sent(?callable $filter)` and `queued(?callable $filter)` return a Laravel `Collection` of `SentPush`. A `SentPush` has `scope`, `connection`, `binding` (the resolved `BindingRef`), `destination` (typed), `message` (the very object you sent), `options` (`validateOnly`, `trafficClass`, `effectKey`, `expiresAt`), `queued`, `result` (the `SendResult` the fake answered with; null for queued pushes) and `queueOptions` (queue connection, queue, delay, after-commit; queued pushes only).

```php
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$push = MobilePush::fake();

MobilePush::to($user)->bulk()->onQueue('push-bulk')->queue(PushMessage::make('Weekly digest', '5 new articles'));

$queued = $push->queued();
expect($queued)->toHaveCount(2);
expect($queued->first()->options->trafficClass)->toBe(TrafficClass::Bulk);
expect($queued->first()->queueOptions->queue)->toBe('push-bulk');
$push->assertNothingSent();
```

### Scripting outcomes

| Method | Effect |
|---|---|
| `respondWith(SendStatus\|ScriptedOutcome\|callable)` | The default answer for every destination (initially `Accepted`). A callable receives the `SentPush` and returns a status, a `ScriptedOutcome` or null (= accepted). |
| `failFor(mixed $target, ReasonCode $reason, SendStatus $status = Rejected)` | Refuse the pushes to a destination, a notifiable (all its destinations) or the pushes a `fn (SentPush): bool` selects. `$status` may be `Rejected`, `Failed` or `Deferred`. With `ReasonCode::DestinationUnregistered` a registry device is invalidated, as after a real APNs 410. |
| `unknownFor(mixed $target)` | The provider "answered nothing usable": `OutcomeUnknown`. |
| `sequence(array $outcomes)` | Answers consumed in order, one per destination, after the `failFor()`/`unknownFor()` rules and before the default. |
| `connection(string $name, string $driver, ?string $appId = null, ?string $scope = null)` | Declare a connection of your own (`'apns'` or `'fcm'`), for a scope other than the current one or a name your config does not have. |

`Padosoft\MobilePush\Laravel\Testing\ScriptedOutcome` builds answers: `accepted()`, `validated()`, `rejected(ReasonCode)`, `failed(ReasonCode)`, `unknown()`, `deferred(int $afterSeconds, ReasonCode)`, `fromStatus(SendStatus)`. The fake cannot answer `Skipped`, `Simulated` or `Expired`: those are decided before a provider is asked (gates, execution mode, expiry), so produce them with the real cause instead (an `eligibleUsing()` check, an expired `expiresAt()`).

The answer goes through the real classification and retry planning, so a `deferred()` outcome gives a real `Deferred` result with `nextAttemptAt`.

```php
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$push = MobilePush::fake()
    ->failFor(FcmToken::of($androidToken), ReasonCode::DestinationUnregistered);

$counts = MobilePush::to($user)->send(PushMessage::make('Hi', 'There'))->counts();

expect($counts[SendStatus::Accepted->value])->toBe(1);   // the iPhone
expect($counts[SendStatus::Rejected->value])->toBe(1);   // the Android phone: unregistered
```

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// Make sure your code does not resend an alert whose outcome is unknown.
$push = MobilePush::fake();
$push->unknownFor(fn () => true);

$report = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Payment received', '25.00 EUR'));

expect($report->hasUnknown())->toBeTrue();
$push->assertSentTimes(1);
```

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\ScriptedOutcome;

$push = MobilePush::fake()->sequence([
    SendStatus::Accepted,
    ScriptedOutcome::deferred(120, ReasonCode::ProviderUnavailable),
]);

$results = iterator_to_array(
    MobilePush::toTokens(Platform::Ios, [$iosToken, str_repeat('cd', 32)])->send(PushMessage::make('Hi', 'There')),
    false,
);

expect($results[0]->status)->toBe(SendStatus::Accepted);
expect($results[1]->status)->toBe(SendStatus::Deferred);
expect($results[1]->nextAttemptAt)->not->toBeNull();
```

### Notifications

The `push`, `apns` and `fcm` notification channels go through the same manager, so the intent fake records them too. Do not combine it with `Notification::fake()`, which replaces every channel, push included.

```php
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;

$push = MobilePush::fake();

$user->notify(new class extends Notification
{
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return PushMessage::make('Order shipped', 'Arrives Friday');
    }
});

$push->assertSentTo($user, fn (SentPush $p) => $p->message->title === 'Order shipped');
```

## The transport fake: `MobilePush::fakeTransport()`

The real pipeline runs and the requests are captured instead of being written to a socket. Google's OAuth token exchange is answered automatically and never counted. Bearer tokens are redacted from failure messages and from the decoded headers.

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Order shipped', 'Arrives Friday')->collapse('order-42'))
    ->counts();

$wire->assertRequestCount(1);
$wire->assertSentToHost('api.sandbox.push.apple.com');
$wire->assertApnsRequest(fn (array $headers, array $payload, string $url) => $headers['apns-push-type'] === 'alert'
    && $headers['apns-collapse-id'] === 'order-42'
    && $headers['apns-topic'] === 'com.example.fake'
    && $payload['aps']['alert']['title'] === 'Order shipped'
    && str_ends_with($url, '/3/device/'.$iosToken));
```

### Reading and asserting

| Method | Gives / checks |
|---|---|
| `requests()` | Every `Core\Transport\Request` sent to a provider, in order (the OAuth exchange excluded). |
| `apnsRequests()` | Each APNs request decoded: `['headers' => ..., 'payload' => ..., 'url' => ...]` (header names lowercase, `authorization` redacted). |
| `fcmRequests()` | Each FCM request decoded: `['message' => ..., 'validateOnly' => bool]`. |
| `assertRequestCount(int)`, `assertNoRequests()` | How many requests went on the wire. |
| `assertSentToHost(string $host)` | At least one request reached this host. |
| `assertApnsRequest(fn (array $headers, array $payload, string $url): bool, ?int $times = null)` | An APNs request matches (exactly `$times` when given). |
| `assertFcmRequest(fn (array $message, bool $validateOnly): bool, ?int $times = null)` | An FCM request matches. |

### Scripting provider answers

The answer helpers only **build** an answer; pass it to `sequence()`, `always()` or return it from `respondUsing()`. With no script, every request is accepted.

| Method | Builds |
|---|---|
| `apnsAccepted()` | HTTP 200 with an `apns-id` |
| `apnsError(int $status, string $reason, ?int $timestampMs = null)` | An APNs error (`410, 'Unregistered', $ms` carries the "invalid since" time) |
| `fcmAccepted()` | HTTP 200 with a message name |
| `fcmError(int $status, string $errorCode, ?int $retryAfter = null)` | An FCM v1 error with its `FcmError` detail (`UNREGISTERED`, `INVALID_ARGUMENT`, `UNAVAILABLE`, `QUOTA_EXCEEDED`, `SENDER_ID_MISMATCH`, `THIRD_PARTY_AUTH_ERROR`, `INTERNAL`, ...) and an optional `Retry-After` |
| `connectionLost()` | The connection dropped after the request was written (the provider may have processed it) |
| `notSent()` | The connection could not be opened (proven not sent) |

| Method | Effect |
|---|---|
| `sequence(array $answers)` | Answers consumed in order, one per request; an element may be a `fn (Request): ?answer` (null falls through) |
| `always($answer)` | The answer to every request the script does not cover |
| `respondUsing(fn (Request $request): ?answer)` | Decide per request; null falls through to `sequence()` / `always()` / the default |

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();
$wire->sequence([$wire->apnsError(410, 'Unregistered', (int) (microtime(true) * 1000))]);

$result = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi', 'There'))->retained()[0];

expect($result->status)->toBe(SendStatus::Rejected);
expect($result->reason->code)->toBe(ReasonCode::DestinationUnregistered);
expect($result->destinationAction)->toBe(DestinationAction::Invalidate);
```

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// APNs may have taken the push, but the answer was lost with the connection.
$wire = MobilePush::fakeTransport();
$wire->always($wire->connectionLost());

$result = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Payment received', '25.00 EUR'))->retained()[0];

expect($result->status)->toBe(SendStatus::OutcomeUnknown);
$wire->assertRequestCount(1);   // an alert is not replaceable: it is not resent blindly
```

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$wire = MobilePush::fakeTransport();

MobilePush::toToken(Platform::Android, $androidToken)
    ->validateOnly()
    ->send(PushMessage::make('Dry run', 'Never delivered')->data(['order_id' => 42]))
    ->counts();

$wire->assertFcmRequest(fn (array $message, bool $validateOnly) => $validateOnly
    && $message['token'] === $androidToken
    && $message['data']['order_id'] === '42');   // FCM data values are strings
```

## Tips

- **Assert the outcome you care about, not the mechanism.** With the intent fake, check who got what; with the transport fake, check the headers and the JSON your app depends on (topic, collapse id, data keys).
- **Test the unknown outcome.** It is the case most code handles wrongly. `unknownFor()` and `connectionLost()` make it a one-liner.
- **Consume the report** in your code under test (`counts()`, `each()`): an unread report leaves later windows unsent, in tests as in production.
- **Queued pushes**: with the intent fake, `queue()` is recorded (`assertQueued()`) and no job is dispatched. To test the job itself, run it with Laravel's queue tooling against `fakeTransport()`.
- **Live Activities and campaigns** write to the database: use `RefreshDatabase` and the migrations of those modules (see [live-activities.md](/modules/live-activities), [campaigns.md](/modules/campaigns)).

---

*This page is generated from [`docs/testing.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/testing.md). To change it, edit that file in the repository, not this site.*
