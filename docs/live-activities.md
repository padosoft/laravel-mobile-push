# Live Activities

Server side and app side, including broadcast channels. Live Activities are optional and **off by default**.

| | |
|---|---|
| Enable | `mobile-push.live_activities.enabled` = `true` (env `MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED=true`) |
| Migration tag | `php artisan vendor:publish --tag=mobile-push-migrations-live-activities` then `php artisan migrate` (or `php artisan mobile-push:install`; with `MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true` nothing is published and `migrate` loads the package files) (three tables: activities, push-to-start tokens, broadcast channels) |
| Needs | the privacy keys (derived from `APP_KEY` unless set), an APNs connection, a database |
| Commands | registered only while the module is enabled: `mobile-push:live-activity:*` and `mobile-push:channels:*` ([commands](commands.md)) |

Read first: `Accepted` means **accepted by APNs**, never that an activity changed on a screen. An accepted broadcast
publication is **one publication**, not N deliveries: there is no per-device result, count or receipt behind it.

Nothing on this page has run against real APNs or a real device. The Swift snippets were not compiled. The server logic
is covered by scenario tests on SQLite with a fake transport; MySQL and PostgreSQL were not exercised.

Related pages: [sending](sending.md), [outcomes](outcomes.md), [retries and limits](retries-and-limits.md),
[safe mode](safe-mode.md), [credentials for APNs](credentials-apns.md), [troubleshooting](troubleshooting.md).

## Concepts

| Thing | Where it comes from | Used for |
|---|---|---|
| **Push-to-start token** | one per device and Swift attributes type; reported by the app (iOS 17.2+) | the server **starts** an activity remotely |
| **Activity update token** | one per running activity; reported by the app | **update** and **end** that one activity |
| **Broadcast channel** | created by the server through the APNs channel-management API (iOS 18+) | one publication updates or ends every activity subscribed to the channel; a channel can never start an activity |

Versions as stated by the code and by the Apple pages it was written from (not tested on devices): push-to-start needs
iOS 17.2; asking the device for an update token after a remote start (`inputPushToken`) and subscribing to a channel
(`inputPushChannel`) need iOS 18; broadcast channels need iOS 18.

The server keeps its own view of each activity (`mobile_push_live_activities` row). That view is what the server did
and what APNs answered. What the device actually shows is a separate fact that only the app can report.

## Declaring a `LiveActivityType`

```php
interface LiveActivityType
{
    public function swiftName(): string;   // sent as "attributes-type"
    public function version(): int;        // recorded with the row, never sent to the device
}
```

The Swift type name is **explicit**: the package never derives it from a PHP class name, because renaming a PHP class
must not silently break activities that are already running on phones. The type is also stored under a stable key (the
key in `live_activities.types`, or the Swift name when the class is not registered), together with its Swift name and
version.

<!-- example: live-activities-declare-type -->
```php
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Apns\Message\Alert;

final class LiveActivitiesDocDeliveryActivity implements LiveActivityType
{
    public function swiftName(): string
    {
        return 'DeliveryAttributes';   // exactly the Swift `struct DeliveryAttributes: ActivityAttributes`
    }

    public function version(): int
    {
        return 1;                      // bump when attributes / ContentState change shape
    }
}

$type = new LiveActivitiesDocDeliveryActivity;
$start = LiveActivityMessage::start($type, ['orderId' => '1042'], ['eta' => 12, 'status' => 'preparing'], Alert::make('Order on its way', 'Tap to follow'));

expect($type->swiftName())->toBe('DeliveryAttributes')
    ->and($start->event->value)->toBe('start')
    ->and($start->type)->toBe($type);
```

Register it in config so you can refer to it by key (from commands too):

```php
// config/mobile-push.php
'live_activities' => [
    'types' => [
        'delivery' => App\LiveActivities\DeliveryActivity::class,
    ],
],
```

`TypeRegistry` accepts an instance, a registered key, or a class name that implements the interface. A Swift name must be
1 to 191 characters and the version cannot be negative.

## The client/server contract

This is what the iOS app must report, and when. Each point was checked against the code; the host app writes the two
endpoints (the package has no routes for Live Activities, only the model methods).

1. **Push-to-start token** (per type, per device). Whenever the app starts, and whenever the token changes, send it to
   your API together with the type key. Server side:
   ```php
   $user->registerPushToStartToken($binding, 'delivery', ApnsPushToStartToken::of($hex), $previous /* optional */);
   ```
   Same owner and still active: refresh only. Owner changed, or the row was quarantined/invalidated: generation + 1.
   Pass `$previous` (the token this one replaces, if the app saw it rotate) to update that row in place instead of
   leaving a duplicate behind.
2. **Activity update token** (per activity). As soon as the activity exists on the device, started remotely or locally,
   and again whenever its token rotates, send the token. Server side:
   ```php
   $user->reportLiveActivityToken($binding, 'delivery', ApnsLiveActivityToken::of($hex), new ActivityReport(
       clientActivityId: $activityId,        // ActivityKit's Activity.id: recognises the same activity when its token rotates
       activityId: $serverId,                // optional: the server's id, if you put it in the attributes
       pushToStartToken: $pushToStartToken,  // optional: the push-to-start token of this device for the same type
       state: $contentState,                 // optional: for locally started activities
       channelId: $channelId,                // optional: the broadcast channel the activity subscribed to
       startedAt: $startedAt,                // optional
   ));
   ```
   The more the app sends, the more precisely the report is matched to the remote start it answers. **Matching order**,
   always inside the same scope + binding + type key, first hit wins:
   1. a row that already holds this token (the same report again: refreshed);
   2. `activityId`: the server row with that id;
   3. `clientActivityId`: the row with that ActivityKit id (a rotated token of a known activity);
   4. `pushToStartToken`: the newest `requested` row without a token created from that push-to-start token;
   5. the owner: the oldest `requested` row of that owner and type without a token (FIFO);
   6. otherwise the activity was started locally: a new `active` row is created.

   A token arriving for a row that already has another one is a rotation: generation + 1 and the old value is replaced.
   Until the update token is known the row is `requested` and **no update can be sent** (`The app has not reported the
   update token of this activity yet`).
3. **Client state** (optional but recommended). When ActivityKit reports `pending`, `active`, `stale`, `ended` or
   `dismissed`, tell the server with a timestamp; it is stored in `client_reported_state` / `client_reported_at`:
   ```php
   LiveActivity::manager()->registry()->reportClientState($activity, ClientActivityState::Dismissed, $reportedAt);
   ```
   It is informational: it never changes the server state or what is sent. A report older than the stored one is ignored
   (reports can arrive out of order).
4. **Report with the environment of the build.** Use the same declaration table as for devices
   ([devices](devices.md)): the `BindingRef` you pass decides which scope, bundle and environment the token belongs to.
   Tokens of the sandbox and production environments are different things.
5. **Do not rely on the server learning the end.** An activity ends on the device because the user dismissed it, because
   iOS ended it (the 8-hour rule), or because of your `end`. Only the app can tell which: report it (point 3).

The two registrations return the stored rows (`PushToStartToken`, `PushActivity`). Owners are any Eloquent model using
`Padosoft\MobilePush\Laravel\LiveActivities\HasLiveActivities` (relations `liveActivities()` and `pushToStartTokens()`;
`openLiveActivities($binding, $typeKey = null)` lists the `requested`/`active`/`ending` ones).

### Swift sketch (not compiled)

```swift
// Not compiled. A shape of what the app side reports, using ActivityKit.
import ActivityKit

func observePushToStartTokens() {
    Task {
        for await tokenData in Activity<DeliveryAttributes>.pushToStartTokenUpdates {          // iOS 17.2+
            let hex = tokenData.map { String(format: "%02x", $0) }.joined()
            await Api.post("/push/live-activities/start-token", ["type": "delivery", "token": hex,
                                                                 "environment": Build.environment, "bundle_id": Build.bundleId])
        }
    }
}

func observe(_ activity: Activity<DeliveryAttributes>) {
    Task {
        for await tokenData in activity.pushTokenUpdates {
            let hex = tokenData.map { String(format: "%02x", $0) }.joined()
            await Api.post("/push/live-activities/token", ["type": "delivery", "token": hex,
                                                           "client_activity_id": activity.id,
                                                           "environment": Build.environment, "bundle_id": Build.bundleId])
        }
    }
    Task {
        for await state in activity.activityStateUpdates {
            await Api.post("/push/live-activities/state", ["client_activity_id": activity.id,
                                                           "state": "\(state)", "at": ISO8601DateFormatter().string(from: Date())])
        }
    }
}

// Activities started remotely appear in Activity<DeliveryAttributes>.activityUpdates: observe each one there.
```

The `Api.post` calls are yours; map them to `registerPushToStartToken()`, `reportLiveActivityToken()` and
`reportClientState()`. Whether `"\(state)"` yields exactly `pending`/`active`/`stale`/`ended`/`dismissed` must be checked
against your Xcode version: map the cases explicitly.

## Starting an activity

A remote start goes to the owner's **push-to-start tokens** for the type. It always needs an alert (the person is told
about the new activity), and it is **never retried by the server**: it is not replaceable, so a lost response stays an
unknown outcome.

```php
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;

$result = LiveActivity::start('delivery', attributes: ['orderId' => '1042'], state: ['eta' => 12, 'status' => 'preparing'])
    ->alert('Order on its way', 'Tap to follow', subtitle: null)
    ->sound('default')
    ->staleAt(now()->addMinutes(30))
    ->relevanceScore(75.0)
    ->inputPushToken()                // iOS 18: ask the device to report an update token
    // ->inputPushChannel($channel)   // iOS 18: subscribe the activity to a broadcast channel (not both: the compiler refuses)
    ->lowPriority()                   // priority 5 instead of the configured default
    ->connection('ios')               // default: live_activities.binding
    ->scope('tenant-a')               // default: the current scope
    ->to($user)                       // a model or an iterable of models using HasLiveActivities
    ->send();                         // StartResult
```

| Option | Effect |
|---|---|
| `alert(Alert\|string $title, ?string $body, ?string $subtitle)` | required; `send()` throws `InvalidMessageException` without it |
| `sound(?string)` | sound name, default `'default'` when called |
| `staleAt(DateTimeInterface)` | `stale-date`: the system marks the content stale from then |
| `relevanceScore(?float)` | ordering among activities |
| `inputPushToken(bool)` | `input-push-token: 1` (iOS 18) |
| `inputPushChannel(BroadcastChannel\|string\|null)` | `input-push-channel` (iOS 18); a channel row or its id |
| `lowPriority()` | priority 5 |
| `connection(string)`, `scope(string)` | binding and tenant |
| `to(Model\|iterable)` | the owners; only their **active** push-to-start tokens for this type, in this scope and binding, are used |

`StartResult` holds `results` (one `SendResult` per push-to-start token), `activities` (keyed by result position), `count()`,
`countOf($status)` and `accepted()`.

What is written, and when:

- one `requested` row per token is written **before** the request;
- an accepted start sets `started_at`; a known refusal (`Rejected`/`Failed`) closes the row (`start_not_accepted`);
- an unknown outcome leaves the row `requested` with `start_status = outcome_unknown` and is never re-sent: the app
  will either report an update token (then the activity exists) or never will;
- nothing is written when nothing left the server: simulation, safe-mode block, a gate, expiry or a deferral leave **no
  row** behind. A message that is globally invalid (thrown before the network) discards the rows it just wrote.

No token for the owner and type: an empty `StartResult`, not an error.

## Updating and ending

```php
$activity = $user->openLiveActivities($binding, 'delivery')[0];   // or LiveActivity::for($id)

$result = LiveActivity::for($activity)
    ->update(['eta' => 5, 'status' => 'arriving'])               // the FULL new ContentState
    ->staleAt(now()->addMinutes(10))
    ->relevanceScore(90.0)
    ->alert('Almost there', 'Two minutes away')                  // an update that also notifies
    ->sound('default')
    ->highPriority()                                             // priority 10, subject to the hourly limiter
    // ->lowPriority()                                           // priority 5, spends nothing
    ->send();                                                    // TransitionResult

LiveActivity::for($activity)
    ->end(['eta' => 0, 'status' => 'delivered'])                 // Apple recommends sending the final state
    ->dismissAt(now()->addMinutes(15))                           // end only; a past instant removes it immediately
    ->send();
```

`update()` and `end()` return a `PendingTransition` with `staleAt()`, `dismissAt()` (end only: calling it on an update
throws `InvalidMessageException`), `relevanceScore()`, `alert()`, `sound()`, `lowPriority()`, `highPriority()`, `send()`
and `ticket()`. The default priority of an update is `live_activities.default_priority` (`high`); an end defaults to
priority 10 (`lowPriority()` changes it) and is not counted by the limiter.

`TransitionResult`: `outcome` (`TransitionOutcome`: sent, deferred, skipped), `status` (`SendStatus`), `reason`, `result`
(the pipeline's `SendResult`, or null when nothing was attempted), `row`, `seq` (the ticket number), `timestamp` (the Unix
second the request carried), `priority`, `downgraded`, `nextAttemptAt`, `sent()`, `isAccepted()`, `publications()`.

### Same-second updates: deferred and coalesced

APNs discards a Live Activity update whose `timestamp` is not newer than the last one it processed, and the package
must not invent a future second. So each request carries the **current Unix second**, and the server remembers the last
second that left for each activity (`last_timestamp`). If a second transition is asked for in the same second:

- it is **not sent**; it becomes the row's single **pending transition** (latest state wins) and the result is
  `Deferred` with `nextAttemptAt` = the next second;
- it goes out from the next real second through `LiveActivity::for($activity)->flush()`, `LiveActivity::manager()->flushDue()`,
  `php artisan mobile-push:live-activity:flush`, or when **any newer transition** of that activity arrives;
- intermediate states that were overwritten while pending are **never sent**.

What the app observes: the first update appears immediately; a burst of updates within one second collapses to the first
and the last; the last one arrives at least one second after the first. Nothing is reordered: an older queued update can
never overwrite a newer state.

So that a pending transition does not wait for the next transition, `IntegrationModule` (`src/Laravel/Integration`)
schedules `ActivityManager::flushDue()` **every minute** while Live Activities are enabled (the scheduled event is named
`mobile-push:live-activities-flush`; check with `php artisan schedule:list`; it needs your scheduler to run
`schedule:run` every minute as usual). A minute is the resolution of the scheduler: if updates must land within a second
or two, dispatch `LiveActivity::for($activity)->flush()` yourself on a short delay, or run
`mobile-push:live-activity:flush` more often.

### Stale jobs after an end

An end is terminal. Transition rules, evaluated in this order:

| Situation | Result |
|---|---|
| `ended` or `expired` row | `Skipped`, `PolicyIneligible` ("nothing is sent after an end" / the local expiry passed) |
| open row (`requested`, `active`, `ending`) at or past `local_expires_at`, update **or end** | `Skipped`, `PolicyIneligible` naming the local expiry; the row becomes `expired` (nothing is sent) |
| `ending` row and the request is an update | `Skipped`, `PolicyIneligible` |
| `requested` row (no update token yet) | `Skipped`, `PolicyIneligible` |
| token quarantined or invalidated | `Skipped`, `PolicyIneligible` |
| a transition dispatched **before** one already applied or pending | `Skipped`, reason `Cancelled` ("superseded") |
| same second as the last request | `Deferred` (pending, see above) |
| otherwise | sent (priority decided by the limiter) |

So a queued update that runs after the end, or an old job that runs after a newer one, sends nothing. Order is the order
of **dispatch**, not of execution: each transition takes a ticket number when it is created.

### Queueing with tickets

`->ticket()` numbers the transition now and returns an `ActivityTicket` (plain data, serialisable) that a job applies
later with `LiveActivity::replay($ticket)`. The package ships the job:

```php
use Padosoft\MobilePush\Laravel\Integration\SendLiveActivityTransitionJob;

$ticket = LiveActivity::for($activity)->update(['eta' => 4])->ticket();
dispatch(SendLiveActivityTransitionJob::for($ticket));   // encrypted payload, tries = 1
```

The job is never retried by the queue: a lost response is the send pipeline's own retry concern (below) and a crash
leaves at most the pending transition for the next flush. The ticket carries the content state but no token: the token
is read from the row when the job runs.

### Unknown outcomes and retries

| Message | If the response is lost |
|---|---|
| start | **never retried** (not replaceable) |
| update, end | retried inside the pipeline, because a later copy replaces the earlier one (`retry.max_attempts`, `unknown_outcome.retry = replaceable`). While waiting, the transition is kept as the row's pending transition, up to `live_activities.max_update_retries` (3) |

The row keeps what was sent (`last_content_state`, `last_status = outcome_unknown`) rather than pretending it did not
happen. Nothing is sent a second time for a start; an update/end retry carries the *current* second at the time of the
retry.

### The high-priority limiter

Apple gives each device a budget for priority-10 updates and does not tell the server what is left of it. The server
cannot know the budget of the device, so it does not pretend to. It applies its own conservative **per-activity** limit:
at most `live_activities.high_priority.max_per_hour` (env `MOBILE_PUSH_LA_HIGH_PRIORITY_PER_HOUR`, default 20, `null` =
no limit) priority-10 *attempts* in a fixed one-hour window that opens with the first counted attempt. Beyond it the update
still goes out, at **priority 5**, never deferred (`TransitionResult::$downgraded` is true). Only updates asking for high
priority are counted; ends and low-priority updates are not. If the device runs out of budget before your limit, Apple
may throttle or drop updates and the server will not know.

### Local expiry, accepted end and client-reported state

Three different facts, kept apart on the row:

| Fact | Column | Set by |
|---|---|---|
| **Local expiry**: past it the server stops sending, checked at every transition (state `expired`) | `local_expires_at` | the server: `start + live_activities.local_expiry.hours` (default 8), or `last accepted update + hours` with `local_expiry.from = last_update` |
| **Accepted end**: APNs accepted an end | `end_accepted_at`, state `ended`, `closed_reason = end_accepted` | the server |
| **Client-reported state**: what the app says the device shows | `client_reported_state`, `client_reported_at` | the app, through `reportClientState()` |

`expired` says nothing about the phone: iOS ends an activity on its own after the 8-hour rule and only the app knows.

**Enforced at transition time.** Every transition (update, end, flush of a pending update, replay of a queued job)
compares `local_expires_at` with the clock inside the transition rules, whether or not anything has pruned. An open row
(`requested`, `active` or `ending`) at or past its local expiry is **not sent to**: the result is `Skipped` with reason
`PolicyIneligible` naming the local expiry, and the same compare-and-set marks the row `expired`. An **end** on an
expired activity is refused too (the server sends nothing after the expiry; the phone ends the activity on its own).
In simulate mode nothing is written, so the row stays as it was. Set the local expiry at or beyond the real lifetime of
your activities, because past it nothing more leaves the server.

The prune command is therefore housekeeping, not part of the rule: it marks as `expired` the open rows nobody touched
since their expiry (tidier lists and channel cleanup) and **deletes closed rows** older than `closed_after_days`
(default 7). Nothing in the package schedules it, so schedule it **daily**, for example
`Schedule::command('mobile-push:live-activity:prune')->daily();`.

## Broadcast channels

A broadcast channel (iOS 18) lets one publication reach every activity subscribed to it. The channel is a provider-side
object created through APNs' channel-management API; the package keeps a local row for each.

```php
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelLimitReachedException;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;

$manager = LiveActivity::manager();

$channel = $manager->createChannel(StoragePolicy::NoStorage, label: 'match-1042');   // connection and scope optional
// $channel->state: active (id known) | unknown (the response was lost: see below)

LiveActivity::broadcast($channel)->update(['score' => '2-1', 'minute' => 63])->send();   // ONE publication
LiveActivity::broadcast($channel)->end(['score' => '2-1', 'minute' => 90])->dismissAt(now()->addHour())->send();
```

| API | Notes |
|---|---|
| `ActivityManager::createChannel(StoragePolicy $policy = NoStorage, ?string $label, ?string $connection, ?string $scope)` | writes a `creating` row **before** the API call, then `active` with the id APNs returned |
| `LiveActivity::broadcast(BroadcastChannel\|string $channel, ?string $connection, ?string $scope)` | a row, or an APNs channel id in that scope and binding; unknown id throws `InvalidArgumentException` |
| `ChannelHandle::update()`, `end()` | same builder as for an activity; `start()` throws `UnsupportedCapabilityException` (a channel can never start) |
| `ChannelRegistry::delete($channel)` | deletes at APNs ("an irreversible action"), then locally |
| `ChannelRegistry::sync($binding)` | reconciles local rows and the list APNs holds, in both directions (returns `SyncReport`) |
| `ChannelRegistry::adopt($unknownRow, $channelId)` | attach an orphan id to an `unknown` row by hand |
| `ChannelRegistry::unreferenced($binding, ?$idleHours)` / `cleanup($binding, ?$idleHours)` | channels no open activity references, idle at least `channels.cleanup_after_hours` (24) |

The storage policy is fixed at creation: `NoStorage` (0) stores nothing, so `apns-expiration` is forced to 0;
`MostRecent` (1) keeps the latest message, for at most 8 hours (per the Apple page the code was written from). Publication
payloads are limited to 5120 bytes.

**Safe mode:** channel management and broadcast publication are operations that "reach many devices or manage provider
state". Outside production they need `live.allow = true` on the connection **and** `live.allow_channel_management` /
`live.allow_broadcast` respectively ([safe mode](safe-mode.md)). In simulation or when blocked, **no row is written**: the
registry asks the guard before the first write.

### The lost-create case

If the response to a create is lost, a channel may exist at APNs that the server does not know. The create is **never
retried blindly** (it would create a second channel). The row is left in state `unknown` with the request id. Resolve it:

```bash
php artisan mobile-push:channels:sync                              # lists APNs' channels and reconciles
php artisan mobile-push:channels:sync --adopt=CHANNEL_ID           # attach an orphan to the one unknown row
php artisan mobile-push:channels:sync --adopt=CHANNEL_ID:LOCAL_ROW_ID
```

`sync` adopts an orphan on its own only when exactly one `unknown` row and exactly one orphan exist (otherwise the channel
could belong to another tenant or another create); with no orphan at all, every `unknown` row was never created and
becomes `deleted`. Rows `active`/`deleting`/`unknown` whose id APNs no longer lists become `deleted`. A `creating` row
older than 120 seconds is treated like an `unknown` one (the process died mid-create).

### Limits and cleanup

- Apple allows 10,000 channels per app and environment. The package enforces a **local cap** of
  `live_activities.channels.max_per_environment` (env `MOBILE_PUSH_LA_CHANNEL_CAP`, default 9500, between 1 and 9999),
  counted across scopes because the app is shared, and refuses a create before the network with
  `ChannelLimitReachedException`.
- Channels outlive their subscribers. Clean up with `mobile-push:channels:sync --cleanup [--idle-hours=N] [--force]`
  (`--force` for the scheduler) or `ChannelRegistry::cleanup()`.
- A publication has no per-device result, no frequency cap, no receipt and no notification fallback.

## Configuration reference

All keys are under `mobile-push.live_activities`. The full generated table is [configuration](configuration.md).

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED` | `false` | |
| `binding` | `MOBILE_PUSH_LA_BINDING` | `apns` | name of the APNs connection used by default |
| `connection` | `MOBILE_PUSH_LA_DB_CONNECTION` | default DB connection | database connection of the three tables |
| `table`, `push_to_start_table`, `channels_table` | | `mobile_push_live_activities`, `mobile_push_live_activity_push_to_start_tokens`, `mobile_push_broadcast_channels` | |
| `models.activity`, `models.push_to_start_token`, `models.broadcast_channel` | | `PushActivity`, `PushToStartToken`, `BroadcastChannel` | replace with subclasses |
| `types` | | `[]` | key => class implementing `LiveActivityType` |
| `default_priority` | `MOBILE_PUSH_LA_PRIORITY` | `high` | `high` or `normal` |
| `high_priority.max_per_hour` | `MOBILE_PUSH_LA_HIGH_PRIORITY_PER_HOUR` | `20` | `null` = unlimited, `>= 0` |
| `local_expiry.hours` | | `8` | `>= 1` |
| `local_expiry.from` | | `start` | `start` or `last_update` |
| `max_update_retries` | | `3` | pending retries of an update/end after an unknown outcome |
| `prune.closed_after_days` | | `7` | |
| `channels.max_per_environment` | `MOBILE_PUSH_LA_CHANNEL_CAP` | `9500` | 1 to 9999 |
| `channels.cleanup_after_hours` | | `24` | |

Connection key `broadcast_host` (`standard` default, or `api-broadcast`) chooses the host used to publish broadcasts (see
"Unverified provider details").

## Commands

| Command | Purpose |
|---|---|
| `mobile-push:live-activity:start {type} --owner= --attributes= --state= --title= [--body= --connection= --scope= --input-push-token --channel= --low-priority --json]` | remote start (`--owner` is `Type:id`, a class or morph alias then the key) |
| `mobile-push:live-activity:update {activity} --state= [--stale= --title= --body= --relevance= --low-priority --json]` | update |
| `mobile-push:live-activity:end {activity} [--state= --dismiss= --json]` | end |
| `mobile-push:live-activity:list [--owner= --type= --state= --scope= --limit=50 --json]` | rows, tokens masked |
| `mobile-push:live-activity:flush [--limit=100 --json]` | send due pending transitions |
| `mobile-push:live-activity:prune [--days= --pretend --json]` | delete old closed rows, mark untouched rows past their expiry `expired` (schedule it daily) |
| `mobile-push:channels:list [--connection= --scope= --state= --json]` | local rows (nothing is sent) |
| `mobile-push:channels:create [--connection= --scope= --storage=none\|recent --label= --type=]` | create at APNs |
| `mobile-push:channels:delete {channel} [--force]` | delete at APNs (irreversible) |
| `mobile-push:channels:sync [--connection= --scope= --adopt= --cleanup --idle-hours= --force --json]` | reconcile, adopt, clean up |
| `mobile-push:channels:publish {channel} [--state= --end --stale= --dismiss= --json]` | one publication |

They consult safe mode before writing anything, and refuse to write when nothing would be sent.

## Unverified provider details

Taken from the authors' notes and the Apple pages they read; none was confirmed against a real APNs:

- **Broadcast publish host.** Apple's page states the connection section uses the standard APNs hosts but its examples use
  `api-broadcast.sandbox.push.apple.com`. The connection key `broadcast_host` chooses between `standard` (default) and
  `api-broadcast`. The production name `api-broadcast.push.apple.com` is the symmetric form and is **not stated** on the
  page. Try the other value if publications are not accepted.
- **Request-id header on broadcasts.** The page lists `apns-request-id` in the header table and the response, but one
  example uses `apns-id`. The compiler sends `apns-request-id` on broadcast publications and `apns-id` on per-device
  requests. Which one the broadcast host accepts is unverified.
- **Channel-management error strings.** Apple lists no `reason` strings for channel operations; `FeatureNotEnabled` and
  `CannotCreateChannelConfig` are mapped by name and not verified. The channel limit currently surfaces as `QuotaExceeded`.
- Alert fields (`loc-key`/`loc-args` forms, `sound` inside `alert`) follow Apple's Live Activity examples.
- The behaviour of `timestamp` (updates not newer than the last are discarded) and the device's priority-10 budget come
  from Apple's documentation, not from measurement.
