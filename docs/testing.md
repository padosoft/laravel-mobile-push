# Testing your application

Two fakes, for two different questions. Neither needs credentials, a network or a device.

| | `MobilePush::fake()` | `MobilePush::fakeTransport()` |
|---|---|---|
| Question it answers | "Did my code ask to send the right push, to the right people?" (**intent**) | "What exactly reaches APNs/FCM, and how does the package react to each answer?" (**bytes and behaviour**) |
| What runs | The real manager, routing, gates and report. Each message is **validated** by the real APNs / FCM compiler, but nothing is kept, no request is built and no receipt marker is issued | The whole real pipeline: compilers, safe-mode guard, guards, classifier, retry planner, events, log sinks, device registry updates. Only the HTTP/2 transport is replaced |
| Answers | Scripted outcomes (default `Accepted`) | Scripted HTTP answers (default: APNs 200 / FCM 200) |
| Assertions | `assertSent()`, `assertSentTo()`, `assertQueued()`, ... | `assertApnsRequest()`, `assertFcmRequest()`, `assertRequestCount()`, ... |

They are deliberately separate: the intent fake is not an oracle for the bytes, and the transport fake is not a convenient way to check who received what. Activating one switches the other off.

While a fake is active, every send runs **live** with every destination allowed (the `auto` mode does not simulate and no `live.only_to` allowlist is needed), because nothing can reach a provider anyway. The application environment is **not** touched: `app()->environment()` and `app()->isProduction()` keep their real values inside a faked test, so code of yours that branches on the environment behaves as it does in the test environment. The package gets there through the manager's own seams (an execution override and installed drivers, see [Extension and testing seams](#extension-and-testing-seams-on-the-manager)), not by pretending to be production.

The fakes refuse to start outside `APP_ENV=testing` (`FakeNotAllowedException`) unless you pass `true` (`MobilePush::fake(true)`, `MobilePush::fakeTransport(true)`) or set `mobile-push.testing.allow_fakes` (a config key with no environment variable, default `false`). Do not switch it on in a real deployment: while a fake is active nothing reaches a provider.

**When your application configures no connection** (`mobile-push.connections` is empty), the fakes provide two: `ios` (APNs, bundle `com.example.fake`, sandbox) and `android` (FCM, project `fake-project`). `fake()` installs fake drivers under those names; `fakeTransport()` writes two complete test connections with keys generated at runtime into `mobile-push.connections`. When the application configures connections of its own, the intent fake installs a fake driver for **each of them** (so your own names, `shop-ios` or whatever, work) and `fakeTransport()` leaves your config alone and runs your connections through the real pipeline: they must carry credentials that look real (keys can be throw-away ones generated with `openssl_pkey_new()`; no secret belongs in a test).

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

<!-- example: testing-assert-sent -->
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

### Validation: the real compilers still run

By default every faked destination is compiled by the same APNs / FCM compiler a real send would use, **for validation only**: the result is thrown away, nothing touches the network, no request exists and no receipt marker is issued. So a message the real path would refuse fails your test as it would fail in production:

- a message invalid for **every** connection the send targets throws (`InvalidMessageException` or another validation exception), before anything is recorded;
- a message the real path rejects for **one destination** (for example a payload over the size limit) yields that destination's `Rejected` result (`PayloadTooLarge`), and the push is still recorded;
- a message invalid for **some connections only** (an iOS-only `subtitle()` sent to an iPhone and an Android phone) follows the multi-connection rule of [sending.md](sending.md#multi-connection-sends-and-the-pre-flight): the other connections are recorded and answered normally, the refused one gets `Rejected` / `PayloadInvalid`.

`$fake->withoutValidation()` switches the validation off for that fake (everything is then recorded and answered as scripted). Use it only when a test needs an arbitrary message; the validation is most of the value of the fake.

<!-- example: testing-validation -->
```php
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// An oversized payload is a per-destination rejection, exactly as with APNs.
$result = MobilePush::toToken(Platform::Ios, $iosToken)
    ->send(PushMessage::make('Hi', str_repeat('x', 6000)))
    ->retained()[0];
expect($result->status)->toBe(SendStatus::Rejected)
    ->and($result->reason->code)->toBe(ReasonCode::PayloadTooLarge);

// A message invalid everywhere throws, as in production.
expect(fn () => MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi')->badge(-1)))
    ->toThrow(InvalidMessageException::class);

// Opting out: the same message is now just recorded.
$fake->withoutValidation();
$accepted = MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi')->badge(-1))->retained()[0];
expect($accepted->status)->toBe(SendStatus::Accepted);
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

### The static style: `MobilePush::assertSent()`

Every assertion above, and `sent()` / `queued()`, can also be called **statically on the facade**, like `Mail::assertSent()`. The call goes to the intent fake that is active for the application, so you do not have to keep the object `fake()` returned. Both styles can be mixed in one test.

<!-- example: testing-static-assertions -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Testing\SentPush;

MobilePush::fake();

MobilePush::to($user)->send(PushMessage::make('Order shipped', 'Arrives Friday'));

MobilePush::assertSent(PushMessage::class, 2);   // an iPhone and an Android phone
MobilePush::assertSentTo($user, fn (SentPush $p) => $p->message instanceof PushMessage && $p->message->title === 'Order shipped');
MobilePush::assertSentOnConnection('ios');
MobilePush::assertNotQueued();
expect(MobilePush::sent())->toHaveCount(2);
```

Calling one of these statically when no intent fake is active (no `MobilePush::fake()` yet, or the fake was switched off) throws a `LogicException` saying to call `MobilePush::fake()` first; it never passes silently. After `MobilePush::fakeTransport()` the intent fake is off too: use the assertions of the object that call returns.

### Reading what was recorded

`sent(?callable $filter)` and `queued(?callable $filter)` return a Laravel `Collection` of `SentPush`. A `SentPush` has `scope`, `connection`, `binding` (the resolved `BindingRef`), `destination` (typed), `message` (the very object you sent), `options` (`validateOnly`, `trafficClass`, `effectKey`, `expiresAt`), `queued`, `result` (the `SendResult` the fake answered with; null for queued pushes) and `queueOptions` (queue connection, queue, delay, after-commit; queued pushes only).

<!-- example: testing-read-recorded -->
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

<!-- example: testing-scripting -->
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

<!-- example: testing-unknown-outcome -->
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

<!-- example: testing-sequence -->
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

<!-- example: testing-notification -->
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

### Your own connections, and `reset()`

`$fake->connection(string $name, string $driver, ?string $appId = null, ?string $scope = null)` declares a connection of your own (`'apns'` or `'fcm'`) for a name your config does not have or for a scope other than the current one. It installs a fake driver for it.

`MobilePush::fake()` and `MobilePush::fakeTransport()` call `reset()` first, so activating one switches the other off. `reset()` puts everything back: the transport, the queued-sender binding, the manager's forced execution, the fake drivers it installed (including those of `connection()`), the recording gate it registered with `eligibleUsing()` and, for `fakeTransport()`, the application's own `mobile-push.connections` (and the drivers built from the test credentials). Nothing stays between two tests; `connectionNames()`, the number of eligibility checks and `executionForced()` are exactly what they were before. Call it yourself with `app(TestingSession::class)->reset()`, or let `InteractsWithMobilePush` do it after each test (`resetMobilePushFakes()`).

## The transport fake: `MobilePush::fakeTransport()`

The real pipeline runs (compilers, guards, classifier, retries, events, log sinks, device registry updates) and only the transport is replaced: the requests are captured instead of being written to a socket, so the bytes your app would send are real. Answers are scripted (default: accepted). The package's `Transport` binding is wrapped, not replaced, so a test double you bound yourself keeps working underneath. Google's OAuth token exchange is answered automatically and never counted. Bearer tokens are redacted from failure messages and from the decoded headers.

<!-- example: testing-wire -->
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

<!-- example: testing-wire-unregistered -->
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

<!-- example: testing-wire-lost-response -->
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

<!-- example: testing-wire-fcm-validate -->
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

## Extension and testing seams on the manager

The fakes are built on public methods of the manager (`MobilePush::...`, the facade documents them). You can use them directly for your own test doubles or extension points; the ones marked `@internal` in the facade (`forceExecution()`, `resolve()`, `preflight()`, ...) are not part of the API.

| Method | Effect |
|---|---|
| `useDriver(string $name, Driver $driver, ?string $scope = null, ?LiveSettings $live = null)` | Send through this driver for the connection `$name` in the scope (`null` = current), instead of building one from configuration. It wins over a runtime `register()`, the resolver and `mobile-push.connections`, is listed by `connectionNames()` while installed, is never cached and owns no transport pool. The driver's binding must carry the same scope and name (`ConfigurationException` otherwise). `$live` defaults to no live settings, so outside production the safe-mode guard blocks a live send through it |
| `forgetDriver(string $name, ?string $scope = null)` | Remove an installed driver; the connection resolves from configuration again. A no-op when none is installed |
| `hasInstalledDriver(string $name, ?string $scope = null): bool` | Whether `useDriver()` installed a driver for this connection |
| `unregister(string $name, ?string $scope = null)` | Undo `register()`: the runtime connection is removed and its cached driver closed. A configured connection of the same name, or one the resolver supplies, is used again from the next send. A no-op when nothing was registered |
| `forgetEligibility(callable $check): int` | Undo `eligibleUsing()` for this very callable (the same closure instance, or an identical string/array callable); returns how many checks were removed |
| `eligibilityChecks(): int` | Number of checks registered with `eligibleUsing()` |

<!-- example: testing-manager-seams -->
```php
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// Eligibility checks can be added and removed again, and counted.
$before = MobilePush::eligibilityChecks();
$check = fn ($destination, $context): bool => true;
MobilePush::eligibleUsing($check);
expect(MobilePush::eligibilityChecks())->toBe($before + 1);
expect(MobilePush::forgetEligibility($check))->toBe(1);
expect(MobilePush::eligibilityChecks())->toBe($before);

// A runtime connection can be registered and removed again.
MobilePush::register('ios-eu', ['driver' => 'apns', 'bundle_id' => 'com.example.eu', 'team_id' => 'TEAM123456', 'environment' => 'sandbox']);
expect(MobilePush::connectionNames())->toContain('ios-eu');
MobilePush::unregister('ios-eu');
expect(MobilePush::connectionNames())->not->toContain('ios-eu');

// The fake installs its drivers with useDriver(); you can see and drop them.
$fake->connection('ios-us', 'apns', 'com.example.us');
expect(MobilePush::hasInstalledDriver('ios-us'))->toBeTrue();
MobilePush::forgetDriver('ios-us');
expect(MobilePush::hasInstalledDriver('ios-us'))->toBeFalse();
```

`useDriver()` takes any `Padosoft\MobilePush\Core\Driver\Driver`: a fake of your own, or a decorator around a real one. It is illustrative only here, because building a driver by hand needs a binding, a compiler, a classifier and a transport; for most tests `fake()` and `fakeTransport()` are enough.

## Tips

- **Assert the outcome you care about, not the mechanism.** With the intent fake, check who got what; with the transport fake, check the headers and the JSON your app depends on (topic, collapse id, data keys).
- **Test the unknown outcome.** It is the case most code handles wrongly. `unknownFor()` and `connectionLost()` make it a one-liner.
- **Consume the report** in your code under test (`counts()`, `each()`): an unread report leaves later windows unsent, in tests as in production.
- **Queued pushes**: with the intent fake, `queue()` is recorded (`assertQueued()`) and no job is dispatched. The pre-flight validation applies to `queue()` too. To test the job itself, run it with Laravel's queue tooling against `fakeTransport()`.
- **Live Activities and campaigns** write to the database: use `RefreshDatabase` and the migrations of those modules (see [live-activities.md](live-activities.md), [campaigns.md](campaigns.md)).
