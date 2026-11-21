---
title: "Campaigns"
description: "One message to a large audience, planned in slices, sent through the durable ledger, with pause, resume, cancel and honest progress. Campaigns are optional, off by default, and need the durable delivery profile."
---

# Campaigns

One message to a large audience, planned in slices, sent through the durable ledger, with pause, resume, cancel and
honest progress. Campaigns are optional, **off by default**, and need the **durable delivery profile**.

| | |
|---|---|
| Enable | `mobile-push.campaigns.enabled` = `true` (env `MOBILE_PUSH_CAMPAIGNS_ENABLED=true`) **and** `mobile-push.delivery.profile` = `durable` (env `MOBILE_PUSH_DELIVERY_PROFILE=durable`) |
| Migration tags | the ledger: `php artisan vendor:publish --tag=mobile-push-migrations-ledger`; the campaigns table: `--tag=mobile-push-migrations-campaigns`; then `php artisan migrate`. For the device audience also the devices tag (`mobile-push-migrations-devices`) |
| Without them | creating a campaign throws a `ConfigurationException` that says which of the two is missing |
| Needs | a queue worker for the bulk queue (`queue.bulk_queue`, default `push-bulk`) and the scheduler (the sweeper) |

Related pages: [delivery profiles](/reliability/delivery-profiles), [queues and Horizon](/reliability/queues-and-horizon),
[retries and limits](/reliability/retries-and-limits), [devices](/modules/devices), [topics](/sending/topics), [receipts](/modules/receipts),
[policies](/modules/policies), [outcomes](/reliability/outcomes), [configuration](/get-started/configuration).

**State of the module.** The campaigns module is present and its tests pass (110 scenario and unit tests: lifecycle,
audiences, controls, sending rules, tenants, workers killed at exact points, and a Black-Friday-sized run). A 500,000
device run exists only in the `slow` test group, which was not part of the verification of this page. Everything was
exercised on SQLite with a fake transport: **MySQL and PostgreSQL were not exercised**, nothing was sent to a real
provider, and the queue was not run on Redis, SQS or Horizon.

The entry point is `MobilePush::campaign($message)` (the manager forwards to the campaigns module and throws a
`ConfigurationException` that says what to set when the module is off or the profile is not durable). Control and
progress go through `Padosoft\MobilePush\Laravel\Campaigns\CampaignManager`, resolved from the container
(`app(CampaignManager::class)`). A campaign starts when you `dispatch()` it (or call `start()` on a draft); there is no
built-in "start at 09:00".

What is **not** there when this page was written: no `mobile-push:campaign:*` console commands (another task was adding
them; run `php artisan list mobile-push` to see whether your version has them) and no "start later" option.

## The shape of a campaign

```php
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

$campaign = MobilePush::campaign(PushMessage::make('Black Friday', '-40% today')->policy('marketing'))
    ->name('black-friday-2026')            // a label for operators, not unique
    ->via('ios')                           // the push connection; default mobile-push.default
    ->audience(DeviceAudience::all()->onPlatform(Platform::Ios))
    ->expiresAt(now()->addHours(6))        // deliveries not sent by then end Expired, without a request
    ->rate(perMinute: 60_000)              // planner pacing
    ->onQueue('push-bulk')                 // optional; default queue.bulk_queue
    ->dispatch();                          // creates the campaign and starts planning: a Campaign
```

`CampaignBuilder` is immutable (every method returns a modified copy):

| Method | Meaning |
|---|---|
| `name(string)` | label (not unique); a blank name is refused |
| `via(string)` | the connection (app binding) to send through |
| `scope(string)` | the tenant; default the current scope |
| `audience(Audience)` | **required** |
| `expiresAt(DateTimeInterface)` | logical expiry of the whole campaign |
| `rate(?int $perMinute)` | planner pacing, 1 to 10,000,000, `null` = unpaced |
| `onQueue(?string)`, `onQueueConnection(?string)` | queue name and queue **connection** of the planner and relay jobs |
| `draft()` | create the campaign (status `draft`) without starting it |
| `dispatch()` | `draft()` then `CampaignManager::start()` |

The message is stored **encrypted in the ledger** (never in the campaign row); the audience description is stored encrypted
in the campaign row. The traffic is always **bulk**: the shared rate limiter keeps the transactional share free (see
[retries and limits](/reliability/retries-and-limits)). A campaign carries **one message for the whole audience**; there is no
per-recipient personalisation.

## Audiences

An audience is a serialisable description that yields destinations lazily, in a stable order, from a resumable cursor, up to
an **upper bound fixed once when the campaign starts planning**: what is added to the source afterwards (a device that
registers later, a line appended to a file) is not part of the campaign. An audience never decides consent or eligibility:
the gates do, immediately before the network.

| Audience | Source | Notes |
|---|---|---|
| `DeviceAudience` (`devices`) | the active devices of the registry on the campaign's binding | filters: `onPlatform(Platform)`, `ofKind(DestinationKind)`, `where('scopeName', ...$params)` (a named local scope of the device model), `idsBetween($from, $to)`. Keyset pagination on the integer primary key. It reads only key, kind, generation and owner: **no token is decrypted or copied**; the ledger stores the device id and generation and the relay reads the token from the registry right before sending, re-checking state, generation and owner |
| `DestinationListAudience` (`list`) | an explicit list of typed destinations | `DestinationListAudience::of($destinations)`, at most **50,000** entries (`InvalidArgumentException` beyond that). Duplicates are allowed: the ledger keeps one delivery per logical destination |
| `DestinationFileAudience` (`file`) | an import file read lazily | one destination per line, `<kind>,<value>` with an **explicit** kind (`apns_device_token`, `fcm_token`, `fcm_topic`, ...); empty lines and `#` comments ignored; a malformed line is counted as invalid and skipped. The cursor is a byte offset and the bound is the file size at start. The file must stay readable at the same path by **every worker** until planning ends (shared storage) and must not be rewritten in place |
| your own | a class implementing `Audience` | register it with `AudienceRegistry::register('vip', VipAudience::class)` (alias: 1-64 characters `[a-z0-9_.-]`) or in `mobile-push.campaigns.audiences` (`alias => class`). Parameters must be plain JSON data; stored names are the alias, so you can rename the class |

`reach()` says what a delivery is: `individual` (every destination is one installation), `fan_out` (topics, conditions,
broadcast channels: one delivery is one **publication**, recipients unknown) or `mixed` (an import file, or a list with both).

```php
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DestinationListAudience;

$tokens = DestinationListAudience::of([ApnsDeviceToken::of($iosToken)]);
$topic = DestinationListAudience::of([FcmTopic::of('breaking-news')]);
$both = DestinationListAudience::of([ApnsDeviceToken::of($iosToken), FcmTopic::of('breaking-news')]);

expect($tokens->reach()->value)->toBe('individual')
    ->and($tokens->reach()->recipientsKnown())->toBeTrue()
    ->and($topic->reach()->value)->toBe('fan_out')       // one publication: the number of recipients is not known
    ->and($topic->reach()->recipientsKnown())->toBeFalse()
    ->and($both->reach()->value)->toBe('mixed');
```

A host audience (illustrative):

```php
use Padosoft\MobilePush\Laravel\Campaigns\Audience\Audience;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceContext;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceReach;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\AudienceSlice;

final readonly class VipAudience implements Audience
{
    public function __construct(public int $minOrders = 10) {}

    public function toArray(): array { return ['min_orders' => $this->minOrders]; }
    public static function fromArray(array $parameters): static { return new static((int) ($parameters['min_orders'] ?? 10)); }
    public function upperBound(AudienceContext $context): string { /* highest id, fixed once at start */ }
    public function slice(AudienceContext $context, ?string $cursor, string $upperBound, int $limit): AudienceSlice { /* keyset page */ }
    public function reach(): AudienceReach { return AudienceReach::Individual; }
}
```

## How it works

1. `dispatch()` creates the campaign row **and its ledger effect** (an encrypted message, no delivery yet; effect key
   `campaign:<id>`, dedupe window = `retention_days`). Status `draft`.
2. `start()` fixes the audience's upper bound and moves to `planning`; the first planner slice is queued once the
   campaigns connection commits.
3. Each **planner job** (`PlanCampaignJob`, one per slice, `tries` 3) takes a lease, reads one slice from the cursor (never
   past the bound), writes it to the ledger **idempotently** (one delivery per logical destination; rows already there are
   left alone) and only then advances the checkpoint (cursor, `planned`, `slices`). It queues a relay for the effect, then
   the next planner job, or marks planning finished (`running`).
4. The **relay** (the same one as for `queue()`) sends the deliveries, with the gates re-checked right before the network
   (device eligibility, your consent callback, the frequency cap by `->policy()`).
5. When planning is finished and every delivery is terminal, the campaign is `completed`.

A worker killed at any point is recovered: an expired lease means the earlier run died, and the next run recounts the
checkpoint from the ledger. The tests kill workers on exact statements (after the slice is written and before the
checkpoint, among others) and assert that nothing is duplicated and nothing is lost.

### Pacing

`rate(perMinute: N)` paces the **planner**: a slice holds at most N destinations and the next slice may start
`ceil(written * 60000 / N)` ms after the previous one was **committed**. Time a slow slice spent is never caught up with a
burst. This is on top of the shared rate limiter, which paces the network (and keeps a share for transactional traffic:
[retries and limits](/reliability/retries-and-limits)). Defaults: `campaigns.slice` (1000 destinations per planner job, 1 to 5000) and
`campaigns.default_rate_per_minute` (`null` = as fast as the planner can write).

```php
use Padosoft\MobilePush\Laravel\Campaigns\Pacing;

// 1000 destinations written at a pace of 60,000 per minute: the next slice may start 1000 ms after the commit.
expect(Pacing::nextSliceAt(committedAtMs: 10_000, written: 1_000, perMinute: 60_000))->toBe(11_000)
    ->and(Pacing::nextSliceAt(10_000, 1_000, null))->toBeNull()          // not paced
    ->and(Pacing::sliceSize(slice: 1_000, perMinute: 300))->toBe(300)    // a slice never holds more than the rate
    ->and(Pacing::delaySeconds(11_000, 10_000))->toBe(1);                // rounded up to whole seconds
```

## Controls

Every method is scoped: a campaign id from another scope is "not found". Controls are explicit state transitions applied
with compare-and-set and are **idempotent**: pausing a paused campaign or cancelling a cancelled one changes nothing and
raises no event. An impossible transition throws a `CampaignStateException`.

| Call | From | To | What happens |
|---|---|---|---|
| `start($scope, $id)` | `draft` | `planning` | fixes the bound, queues the first slice. Already started: no-op |
| `pause($scope, $id)` | `planning` | `paused` | no further slice is written; deliveries already in the ledger **keep being sent** |
| `resume($scope, $id)` | `paused`, `failed` | `planning` | continues from the **stored cursor**, never from zero; also restarts a stalled planner |
| `cancel($scope, $id)` | `draft`, `planning`, `paused`, `running`, `failed` | `cancelled` | nothing further is planned and the ledger cancels every delivery that is not in flight. What was already accepted, or is in flight, **cannot be retracted** |

```
draft --start--> planning --(last slice written)--> running --(every delivery terminal)--> completed
                  |  ^                                |
             pause|  |resume                          |
                  v  |                                |
                 paused --(last slice already written)--> running
planning --(permanent planner error)--> failed --resume--> planning
draft | planning | paused | running | failed --cancel--> cancelled
```

`completed` and `cancelled` are final. `failed` means planning stopped on an error (the class name is kept in `last_error`)
and is resumable from the cursor. Events (namespace `Padosoft\MobilePush\Laravel\Campaigns\Events`): `CampaignStarted`,
`CampaignPlanned`, `CampaignPaused`, `CampaignResumed`, `CampaignCancelled`, `CampaignCompleted`; each is dispatched once,
only when the status really changed.

```php
use Padosoft\MobilePush\Laravel\Campaigns\CampaignControl;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;

expect(CampaignStatus::Planning->after(CampaignControl::Pause))->toBe(CampaignStatus::Paused)
    ->and(CampaignStatus::Paused->after(CampaignControl::Pause))->toBe(CampaignStatus::Paused)   // idempotent
    ->and(CampaignStatus::Completed->after(CampaignControl::Cancel))->toBeNull()                 // not allowed: final
    ->and(CampaignStatus::Failed->after(CampaignControl::Resume))->toBe(CampaignStatus::Planning)
    ->and(CampaignStatus::Cancelled->isFinal())->toBeTrue();
```

## Progress: numbers come from the ledger

```php
$progress = app(CampaignManager::class)->progress($scope, $campaignId);
```

`CampaignProgress` reads the **ledger**, never a counter kept elsewhere (and detects completion). Fields: `waiting`,
`inFlight`, `accepted`, `rejected`, `failed`, `expired`, `unknown`, `skipped`, `cancelled`, `simulated`, `validated`,
`terminal`, `total()`, `percent()`, `notAccepted()` (rejected + failed + expired; unknown outcomes are not included),
`byState()`, `recipientsKnown`, `funnel` (the [receipts](/modules/receipts) funnel, only when recipients are known), `estimate`
and `notes` (plain statements to show next to the numbers).

Reading rules the numbers obey:

- `accepted` means the provider accepted the request, not that a phone showed it.
- `unknown` is reported apart: the response was lost (or the worker died after sending); it may have been accepted. It is
  never counted as failed.
- While planning is not finished, totals cover the destinations planned **so far**, not the whole audience, and
  `percent()` is the share of what is planned, not of the audience (the notes say so).
- For topics, conditions and broadcast channels, one delivery is one publication; `recipientsKnown` is false and no number
  is a number of recipients.
- Audience entries that were not valid destinations are counted in `invalid` and skipped.
- `estimate` (`ThroughputEstimate`) is **an estimate** of the time left from the recent pace; it does not know about rate
  limits that will open, deferrals that will come due or slices not yet planned. Never use it for a decision.

Other calls: `find($scope, $id)`, `reconcile($scope, $id)` (verifies the invariant *planned = deliveries in the ledger =
deliveries of the campaign's effect* and reports a `ReconcileReport`; it reports, it never corrects; a difference while a
slice is being written is expected), `sweep()` (restart stalled planners and detect completion, every scope), and
`prune(?int $retentionDays, ?string $scope)`.

## Guarantees and limits

What the tests establish (SQLite, fake transport):

- **No duplicate delivery** from reruns, crashed workers or a second planner: the slice write is idempotent per logical
  destination and the checkpoint advances only after it.
- **Resume continues, never restarts**: a paused or failed campaign resumes from the stored cursor.
- **Consent and eligibility are checked at send time**, not at planning time: a device that changed owner, was invalidated
  or opted out between planning and sending is skipped.
- **Tenants are isolated**: ids from another scope are not found.
- **Memory is bounded** by the slice size, whatever the audience.

What to know:

- The audience bound is fixed at start; late arrivals are not included.
- The relay marks an attempt as started when the item enters its sending window, not when the bytes leave. If a worker
  dies while a window of, say, 100 items is being filled, up to that many deliveries end `OutcomeUnknown` with **zero
  requests actually sent**. This is deliberately honest (the absence of a request cannot be proved), but pessimistic.
- The relay does not signal the end of an effect: completion is detected by the scheduled sweeper (a job every minute while
  the module is usable and `campaigns.sweeper.schedule` is true, named `mobile-push:sweep-campaigns`) or by reading
  `progress()`.
- `DeviceAudience` needs an integer primary key on the device model (the default one has).
- A database index on the devices table for `(binding_identity, id)` was identified as useful for very large device
  tables and is not part of the shipped migration.
- Measured on SQLite in memory with the fake transport: about 1.2 ms per delivery (5,000 deliveries in about 6 seconds;
  about 23 seconds for 20,000). The 500,000-device variant in the `slow` group passed but took about 1,436 seconds, **more
  than linear** against the 20,000 run, and scalability work on the ledger, relay and planner was still in progress when this
  page was written. These numbers describe this implementation on that setup, not a provider and not your database: size
  and test your own campaigns before relying on them.

## Configuration

| Key | Env | Default | Notes |
|---|---|---|---|
| `campaigns.enabled` | `MOBILE_PUSH_CAMPAIGNS_ENABLED` | `false` | |
| `campaigns.table` | | `mobile_push_campaigns` | |
| `campaigns.connection` | | `null` | database connection of the table |
| `campaigns.slice` | | `1000` | destinations per planner job, 1 to 5000 |
| `campaigns.default_rate_per_minute` | | `null` | 1 to 10,000,000 |
| `campaigns.retention_days` | | `30` | finished campaigns are pruned after this long; also the dedupe window of the effect |
| `campaigns.sweeper.schedule` | | `true` | |
| `campaigns.audiences` | | `[]` | alias => class implementing `Audience` |

Queue names and timeouts are the package's own (`queue.bulk_queue`, `queue.timeout`, ...): see
[queues and Horizon](/reliability/queues-and-horizon).

---

*This page is generated from [`docs/campaigns.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/campaigns.md). To change it, edit that file in the repository, not this site.*
