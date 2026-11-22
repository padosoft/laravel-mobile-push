# Policies: frequency caps and the server-side badge

Two optional behaviours that decide *how often* and *with what number* a person is pushed. Both are about the **person**,
not the device or the retry.

| | Frequency cap | Server-side badge counter |
|---|---|---|
| Switch | `mobile-push.frequency.enabled` (env `MOBILE_PUSH_FREQUENCY_ENABLED`), **off by default** | `mobile-push.badge.enabled` (env `MOBILE_PUSH_BADGE_ENABLED`, default `true`), but it acts only on messages that ask for `->incrementBadge()` |
| Migrations | none | none |
| Storage | the package cache store (`mobile-push.cache.store`): it needs a **shared, lock-capable store** (redis, memcached, database, dynamodb) to be correct across workers. `array` works inside one process only | same |

Related pages: [messages](messages.md), [sending](sending.md), [notifications and fallback](notifications-and-fallback.md),
[devices](devices.md), [configuration](configuration.md), [outcomes](outcomes.md).

What was verified under concurrency: eight real processes hitting the cap and the badge counter of one person at
the same instant, on Redis, the `database` store (a shared SQLite file, and MySQL 8.4, MariaDB 11.4 and PostgreSQL 17
in CI) and the `file` store, plus memcached and DynamoDB Local in the `backends` workflow
(`PolicyCoordinationTest`): the pushes allowed never exceed the cap, no badge number is handed out twice and the
badge equals the increments that succeeded. What was not: a production cache under production traffic.

## `policy` is not `actionCategory`

Two different message fields, easy to confuse:

| | Where it lives | What it does |
|---|---|---|
| `->policy('marketing')` | the **server**; never sent to the device | names the **bucket** the frequency cap counts this message in |
| `->actionCategory('order')` | the **device**; sent in the push | the app-side action set (iOS `category`, Android click action) that decides which buttons the notification shows |

<!-- example: policies-policy-vs-action-category -->
```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$message = PushMessage::make('Flash sale', 'Ends tonight')
    ->policy('marketing')            // server side: counted in the "marketing" bucket
    ->actionCategory('sale');        // device side: the app's notification category

expect($message->policy)->toBe('marketing')
    ->and($message->actionCategory)->toBe('sale');

MobilePush::to($user)->send($message)->counts();

$fake->assertSentTimes(2);
```

## Frequency caps

### What it does

- **Subject = the person.** The destinations of one send are grouped by subject: a registry device with an owner counts as
  that owner (type + id), so the three phones of one user are **one subject**; a destination without an owner (a raw
  token) is its own subject, identified by its keyed index, never by the raw token.
- **Once per logical effect.** One decision is taken per subject for `(scope, effect key, bucket)`: all devices of a user
  are allowed together or capped together. A retry or a continuation of the same effect gets the **stored decision** and
  consumes nothing, even if the window would now have room (or no longer has).
- **Without an effect key** there is no stable identity: every call is its own effect. `PendingSend::send()` generates one
  per call, so the decision is still once per send; a retry of a send that carried no key of its own can consume again.
- **Buckets.** A message picks its bucket with `->policy('marketing')`. A message without a policy (or a message that is not a
  `PushMessage`) uses `frequency.no_policy_bucket` (default `default`); `null` or an empty value means *not capped*.
- **Rolling windows.** Limits are `per_hour` (3600 s) and/or `per_day` (86400 s): "at most N effects in the last 3600
  seconds". An effect is recorded only when every window had room; a capped effect consumes nothing.
- **Never applied** to topics, conditions, broadcast channels, or Live Activity transitions: they are not one person.
  Registry eligibility and the policy gates only look at installation destinations (APNs and VoIP device tokens, FCM
  tokens and FIDs).
- **Order.** The cap runs *after* registry eligibility and your consent check, so a destination that is not eligible (or that
  opted out) never consumes a unit of the cap.
- A capped destination is a `Skipped` result with reason `PolicyFrequencyCapped` and a hint such as `Frequency cap for
  policy "marketing": at most 2 per day.`. It is never an error and never retried. Per the [fallback rules](notifications-and-fallback.md),
  a capped push never triggers a fallback channel.

### Bypass versus consent

`->bypassFrequencyCap()` on a `PushMessage` skips the cap (for example a security alert). It has **nothing to do with
consent**: the registry eligibility gate and your `MobilePush::eligibleUsing()` callback still run, and an opted-out user
still receives nothing. A cap is a courtesy limit; consent is a rule.

### Which destinations the gates see

Checks registered with `MobilePush::eligibleUsing()` receive **every destination kind**, Live Activity tokens, topics and
conditions included: the callback gets the typed destination and decides (for a Live Activity transition, a topic or a
broadcast, return `true` unless you have a rule for it). The device-registry eligibility gate and the frequency cap, on the
other hand, apply to registry device kinds only (APNs and VoIP device tokens, FCM tokens and FIDs), because only those can
be looked up in the devices table or belong to a person.

### Configuration

```php
// config/mobile-push.php
'frequency' => [
    'enabled' => true,
    'limits' => [
        'default'   => ['per_hour' => 5, 'per_day' => 20],
        'marketing' => ['per_day' => 2],
        'security'  => 'unlimited',
    ],
    'no_policy_bucket' => 'default',   // null = messages without ->policy() are not capped
    'on_store_failure' => 'allow',     // allow | deny
],
```

| Key | Env | Default | Notes |
|---|---|---|---|
| `frequency.enabled` | `MOBILE_PUSH_FREQUENCY_ENABLED` | `false` | |
| `frequency.limits` | | `default` 5/hour and 20/day; `marketing` 2/day; `security` unlimited | bucket => `['per_hour' => n, 'per_day' => n]` (either or both) or `'unlimited'`. Values must be positive integers (numeric strings from `env()` are accepted); an empty map, a zero or negative number, `null` (what `env()` gives for a variable that is not set), a bucket that is `null`, or an unknown key is a `ConfigurationException`, so neither a typo nor a missing environment variable can silently remove a cap. To leave a window out, leave its key out. Without a `default` entry, unlisted buckets are unlimited |
| `frequency.no_policy_bucket` | `MOBILE_PUSH_FREQUENCY_NO_POLICY_BUCKET` | `default` | |
| `frequency.on_store_failure` | `MOBILE_PUSH_FREQUENCY_ON_STORE_FAILURE` | `allow` | what to do when the counters cannot be read or written |

The table and `on_store_failure` are validated only while the cap is enabled; a wrong value then throws a
`ConfigurationException` at the first send that names the wrong key.

### Store failure policy

The gate never throws. If the counter store is **unavailable** (it throws, or the package's cache adapter reports the
backend as unreachable; the adapter itself swallows cache errors and behaves like an empty store):

- `allow` (default) **fails open**: the destinations proceed. A cache outage never silences notifications.
- `deny` **fails closed**: the destinations are skipped with `PolicyFrequencyCapped` and the hint `Frequency counters
  unavailable; failing closed.` A cache outage never causes a flood.

### A busy store is not a failed store

The counter of one person is one value updated by compare-and-set. When several workers send to the same person at
the same instant, a worker can lose that write: it retries (up to 32 times, with a random pause of at most two
milliseconds). If every attempt loses while the store is alive, the cap **was not checked**, so the destination is
not let through under either policy: it comes back `Deferred` with reason code `quota_exceeded`, the hint `Frequency
counters are too contended to update; ...` and `nextAttemptAt` five seconds later. With an effect key the later
attempt gets the same decision the first would have had.

This rule comes from a **defect found and fixed**: contention used to be reported as a store failure, and with the
default `allow` the losing worker's push went through. Eight processes sending to one person got 6 pushes through a
cap of 5 and 61 through a cap of 60 on the `database` cache store of PostgreSQL 16 (and on the `file` store on a
loaded machine). After the fix the same runs allow exactly 5 and 60.

### Seeing it work

The counter and the table are plain classes. This example (in-memory store, no application needed) shows the rolling
window and the once-per-effect rule:

<!-- example: policies-frequency-cap -->
```php
use Padosoft\MobilePush\Core\Clock\SystemClock;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyLimits;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;

$clock = new SystemClock;
$counter = new FrequencyCounter(new InMemoryGuardStore($clock), $clock);
$limits = FrequencyLimits::fromArray([
    'default' => ['per_hour' => 5, 'per_day' => 20],
    'marketing' => ['per_day' => 2],
])->forBucket('marketing');

$person = Subject::owner('App\\Models\\User', '42');   // all of this user's phones share one subject

$first = $counter->decide('default', $person, 'marketing', $limits, 'campaign-a');
$second = $counter->decide('default', $person, 'marketing', $limits, 'campaign-b');
$third = $counter->decide('default', $person, 'marketing', $limits, 'campaign-c');   // the third in 24 hours
$again = $counter->decide('default', $person, 'marketing', $limits, 'campaign-a');   // a retry of the first effect

expect($first->allowed)->toBeTrue()
    ->and($second->allowed)->toBeTrue()
    ->and($third->allowed)->toBeFalse()
    ->and($third->describeLimit())->toBe('2 per day')
    ->and($again->allowed)->toBeTrue();   // the stored decision: a retry consumes nothing
```

## The server-side badge counter

The iOS badge is a number on the icon. If your server computes it, every device of a person must show the **same** number,
and a retry must not add one more.

- Ask for it on the message: `->incrementBadge()`. The counter is per **(scope, owner)**: one counter for the person, not for
  each phone. Set `badge.per_binding = true` for one counter per connection.
- It is incremented **once per effect**: the value each effect received is remembered with the counter, so every device of
  the owner and every retry or continuation of the same effect gets the **same number**; two workers incrementing at once
  are serialised by a compare-and-set (no lost update).
- An explicit `->badge(n)` wins and never touches the counter.
- Only registry devices with an owner take part. Destinations without an owner (raw tokens) keep the message exactly as it is.
- **When it is incremented.** Once per owner per send, and **only if at least one of the owner's devices passes the
  device-registry eligibility check and every check registered with `MobilePush::eligibleUsing()`**. An owner whose devices
  are all refused by those two does not consume an increment (their devices come back `Skipped`, and the counter stays as it
  was). With the registry off, only the `eligibleUsing()` checks are asked.
- **What is not asked at that point.** The frequency cap, safe mode, the guards (rate limiter, breakers) and gates added
  with `withGate()` consume budget or depend on the moment of the send, so they are not evaluated before the increment: a
  device that is later skipped by the frequency cap, by safe mode or by the guards **has already counted**. The counter is
  the server's idea of "unread", not a count of delivered pushes.
- **Your checks run twice.** For a badge send the `eligibleUsing()` callbacks are called once to decide the increment and
  again when the send itself passes the gates, so they must be **free of side effects** (no counters, no writes, no
  "consent asked" markers). A callback that throws counts as "may pass" for the increment, and the send then decides and
  reports it.
- If the counter store is unavailable, or stays too contended to update after 32 attempts (several workers counting
  for the same person at the same instant), the message is sent **unchanged** (no badge change on the device is
  better than a wrong number). Nothing is counted for that message, and the number is never handed out twice.
- The markers that make the increment idempotent expire after 3 days and only the newest 256 per owner are kept: a retry
  later than that, or after more than 256 other effects of the same owner, would increment again. Retries happen within
  minutes, so this is the documented trade-off.
- The counter itself is forgotten after `badge.ttl_days` (default 90) without an increment or a set.

| Key | Env | Default |
|---|---|---|
| `badge.enabled` | `MOBILE_PUSH_BADGE_ENABLED` | `true` |
| `badge.ttl_days` | `MOBILE_PUSH_BADGE_TTL_DAYS` | `90` (a positive number) |
| `badge.per_binding` | `MOBILE_PUSH_BADGE_PER_BINDING` | `false` |

<!-- example: policies-badge-increment -->
```php
use Padosoft\MobilePush\Core\Clock\SystemClock;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;

$clock = new SystemClock;
$resolver = new BadgeResolver(new BadgeCounter(new InMemoryGuardStore($clock), $clock));
$owner = Subject::owner('App\\Models\\User', '42');

$message = PushMessage::make('New message', 'Ada wrote to you')->incrementBadge();

$one = $resolver->resolve($message, 'default', $owner, 'effect-1');        // first effect: 1
$retry = $resolver->resolve($message, 'default', $owner, 'effect-1');      // same effect again: still 1
$two = $resolver->resolve($message, 'default', $owner, 'effect-2');        // next effect: 2

expect($one->badge)->toBe(1)
    ->and($retry->badge)->toBe(1)
    ->and($two->badge)->toBe(2)
    ->and($message->badge)->toBeNull();   // the original message is never modified
```

### Reset when the app opens

The package cannot know when the person opened the app. Write one endpoint and call it from the app at launch and
whenever it comes to the foreground, and set the icon badge to 0 on the device at the same time:

```php
// routes/api.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

Route::post('/push/badge/reset', function (Request $request) {
    MobilePush::resetBadge($request->user());                 // the owner model: the same one the devices belong to
    // MobilePush::resetBadge($request->user(), 'ios');       // with badge.per_binding: reset one connection's counter

    return response()->noContent();
})->middleware('auth:sanctum');
```

`MobilePush::currentBadge($owner, ?$connection, ?$scope)` returns the stored number (0 when there is none). `resetBadge()`
and `currentBadge()` take an Eloquent owner (other objects throw a `ConfigurationException`) and the current scope unless
you pass one. After a reset the counters remembered per effect are kept, so a retry of an effect that already counted still
reports the number it was given.
