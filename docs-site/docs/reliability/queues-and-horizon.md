---
title: "Queues, workers, Horizon and the scheduler"
description: "queue() puts work on a Laravel queue; this page says on which queue, with what timeouts, which jobs exist, what to run in production (a Horizon block and its plain queue:work equivalent, both printed by…"
---

# Queues, workers, Horizon and the scheduler

`queue()` puts work on a Laravel queue; this page says on which queue, with what timeouts, which jobs exist, what to run in production (a Horizon block and its plain `queue:work` equivalent, both printed by `mobile-push:install`), which entries the package adds to the scheduler, and what has and has not been verified per queue driver.

Related pages: [delivery profiles](/reliability/delivery-profiles) (what the jobs do), [retries and limits](/reliability/retries-and-limits), [commands](/operations/commands), [troubleshooting](/operations/troubleshooting).

## Two different "connections"

A **push connection** is an APNs or FCM app binding (`mobile-push.connections.*`). A **queue connection** is a Laravel queue backend (`config/queue.php`). They are unrelated names. On `PendingSend`, the queue connection is chosen with `onQueueConnection()`, never `connection()`.

```php
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

// Which push connection: ->connection('ios'). Which queue: onQueueConnection() / onQueue().
$effectId = MobilePush::to($user)
    ->bulk()                          // traffic class: uses the bulk queue by default
    ->onQueueConnection('redis')      // the Laravel queue connection
    ->onQueue('push-bulk')            // the queue name (overrides the default for the class)
    ->delay(30)                       // seconds, or a DateTimeInterface
    ->afterCommit()                   // wait for the surrounding database transaction to commit (default)
    ->queue(PushMessage::make('Weekly digest', 'Three new things for you'));
```

`queue()` returns the effect id (several ids joined by `,` when the targets span several push connections).

## Queue names

| Setting | Env | Default | Used for |
|---|---|---|---|
| `queue.connection` | `MOBILE_PUSH_QUEUE_CONNECTION` | `null` (the app's default queue connection) | The queue backend for every package job. |
| `queue.queue` | `MOBILE_PUSH_QUEUE` | `push` | **Transactional** traffic (one-to-one messages), the sweeper, relay follow-ups. |
| `queue.bulk_queue` | `MOBILE_PUSH_BULK_QUEUE` | `push-bulk` | **Bulk** traffic (`->bulk()`, campaigns). |

A job goes to the queue named by `onQueue()` when given, otherwise to the queue of its traffic class. Keep the two queues on **separate worker pools** so a large campaign cannot delay a login code. Follow-up jobs created inside a worker (continuations, relay follow-ups) inherit the queue of their traffic class.

## The jobs

| Job | Profile | What it does | Retried by the queue? |
|---|---|---|---|
| `SendPushJob` | light | Sends one small chunk of destinations (`queue.chunk` 500, `queue.chunk_bytes` 98304), then dispatches a **new** job for the `Deferred` ones. Encrypted payload (`ShouldBeEncrypted`). | **No**: `tries = 1`, never `release()`d |
| `RelayDeliveriesJob` | durable | Claims due deliveries of one effect (or of a whole scope), sends them, records outcomes before it finishes. Carries only ids. | Yes: `tries = 3`, `backoff = [10, 60]` s. Safe: a rerun claims again and never re-sends a delivery with a recorded or unknown outcome. |
| `SweepLedgerJob` | durable | Applies lease expiry, expires overdue deliveries, dispatches a scope-wide relay when work is due. Idempotent. | `tries = 1` (the next minute's run covers it) |
| `PlanCampaignJob`, `SweepCampaignsJob` | campaigns | Write one slice of a campaign's audience to the ledger; detect completion and stalled planners. | See [campaigns](/modules/campaigns) |
| `SendLiveActivityTransitionJob` | Live Activities | Sends one Live Activity transition. Never retried by the queue; a lost job is replayed by the module. | No |

Laravel's own `SendQueuedNotifications` job is the **only** owner of queueing when a notification implements `ShouldQueue`: the push channel then sends inside that job and does not queue a second one. See [notifications and fallback](/sending/notifications-and-fallback).

All package jobs set `onConnection` / `onQueue` / `delay` at dispatch. The light job is marked **after commit** (held until the surrounding transaction commits, dropped on rollback) when both the `afterCommit()` call option and `queue.after_commit` (`MOBILE_PUSH_QUEUE_AFTER_COMMIT`, default `true`) are on. The durable relay is dispatched after the commit of the **ledger connection's** transaction. A job dispatched from inside a worker (a continuation, a follow-up, a sweep) never waits for a commit.

### Tags

Horizon tags (and `displayName`) never contain a token, a user id or a device id:

| Job | Tags |
|---|---|
| `SendPushJob` | `mobile-push`, `provider:<apns|fcm>`, `connection:<name>`, `scope:<scope>`, `effect:<id>` |
| `RelayDeliveriesJob` | `mobile-push`, `relay`, `scope:<scope>`, `effect:<id>` (when for one effect) |
| `SweepLedgerJob` | `mobile-push`, `sweep` |
| `PlanCampaignJob` | `mobile-push`, `campaign`, `scope:<scope>`, `campaign:<id>` |
| `SendLiveActivityTransitionJob` | `mobile-push`, `live-activity`, `<target>:<id>` (the activity or channel row id) |

Horizon only stores tags when it is installed, so look for them in the Horizon dashboard.

## The timeout chain

Retries are the package's business, so the queue must never redeliver a job that is still working. That requires a strict order of timeouts, each with a margin:

```
transport timeout  <  chunk deadline  <  job timeout  <  worker timeout  <  retry_after (SQS: visibility timeout)
and, in the durable profile:  chunk deadline  <  ledger lease
```

| Link | Where it is set | Default |
|---|---|---|
| transport timeout | `transport.timeout_ms` (or the largest `connections.*.timeout_ms`) | 30000 ms (30 s) |
| chunk deadline | `queue.chunk_deadline` (`MOBILE_PUSH_QUEUE_CHUNK_DEADLINE`): wall time of one relay run before it hands over | 40 s |
| job timeout | `queue.timeout` (`MOBILE_PUSH_QUEUE_TIMEOUT`): the `$timeout` of every package job | 50 s |
| worker timeout | Horizon supervisor `timeout`, or `queue:work --timeout`; declare it in `queue.worker_timeout` when it cannot be read | not set |
| `retry_after` | `queue.connections.<connection>.retry_after`; for SQS the queue's visibility timeout, declared in `queue.visibility_timeout` | the connection's |
| ledger lease | `ledger.lease_seconds` | 120 s |

The **margin** of each link is `max(5 s, 10 % of the lower value)`. The defaults are chosen to fit a **stock Laravel application with no change**, whose `database` and `redis` queue connections use `retry_after` 90: 30 s (transport) < 40 s (chunk deadline) < 50 s (job timeout) < 55 s (worker timeout, the value `mobile-push:install` prints) < 90 s (`retry_after`), and, in the durable profile, 40 s < 120 s (ledger lease). The worker timeout must be at least `50 + 5 = 55` s and `retry_after` at least `55 + 5.5`, rounded up to **61 s**: that is what `mobile-push:install` prints. A connection that sets no `retry_after` falls back to the framework's 60, which still fits. The chain is checked as a whole: if you raise one value (a slower transport, a bigger chunk), raise the ones above it too, and `mobile-push:doctor` tells you which link breaks.

### How `doctor` reports it

`php artisan mobile-push:doctor` prints one line per link in the section "Queue timeouts" (a real run of the defaults, durable profile, with a database queue connection whose `retry_after` is 90 as in a fresh Laravel application):

```text
Queue timeouts
  ✓ transport_timeout (30 s) < chunk_deadline (40 s), margin 5 s
  ✓ chunk_deadline (40 s) < job_timeout (50 s), margin 5 s
  ! not verifiable: job_timeout (50 s) < worker_timeout (?), margin 5 s: not verifiable
      Remedy: worker_timeout cannot be read here: check it by hand or declare it (the Horizon supervisor "timeout" or `queue:work --timeout` (declare it in mobile-push.queue.worker_timeout)).
  ! not verifiable: worker_timeout (?) < retry_after (90 s), margin 5 s: not verifiable
      Remedy: worker_timeout cannot be read here: check it by hand or declare it (the Horizon supervisor "timeout" or `queue:work --timeout` (declare it in mobile-push.queue.worker_timeout)).
  ✓ job_timeout (50 s) < retry_after (90 s), margin 5 s
  ✓ chunk_deadline (40 s) < lease_seconds (120 s), margin 5 s
```

There is no violation. The two "not verifiable" lines are about the one value the application cannot read, the `queue:work --timeout` flag (see below); the line that follows them proves the rule that matters most, job timeout below `retry_after`. With a higher `queue.timeout` (for example 120) and the same `retry_after` of 90 the last two lines turn into a violation with a remedy such as `Raise retry_after to at least 132 s, or lower job_timeout to at most 81.818 s`.

Each link is one of:

| Status | Meaning | `doctor` exit code |
|---|---|---|
| ok | The upper value exceeds the lower by at least the margin. | 0 |
| **violation** | It does not. The remedy names the key to raise and the largest value the lower one may have. | **2** |
| **not verifiable** | One side cannot be read from this process. It is **never** reported as ok. | **1** (a warning) |
| not applicable | The `sync` driver: jobs run in-process with no worker and no redelivery. | 0 |

What can and cannot be read:

- **Horizon:** the worker timeout is read from `horizon.defaults` and `horizon.environments.<APP_ENV>` for every supervisor that uses the same queue connection **and** lists `push` or `push-bulk`; a supervisor without `timeout` counts as 60 s. The smallest value is checked against the job timeout and the largest against `retry_after`.
- **`queue:work --timeout`:** it is a command-line flag of a process the application cannot see. It stays "not verifiable" until you declare the value in `queue.worker_timeout` (`MOBILE_PUSH_QUEUE_WORKER_TIMEOUT`). Declaring is trusting yourself: the doctor then verifies the arithmetic, not the process.
- **SQS:** the visibility timeout is an attribute of the queue, not part of Laravel's config. Declare it in `queue.visibility_timeout` (`MOBILE_PUSH_QUEUE_VISIBILITY_TIMEOUT`).
- **`redis`, `database`, `beanstalkd`:** `retry_after` is read from the connection (the framework default of 60 when the connection sets none).
- **Any other driver:** `retry_after` is "not verifiable" ("driver ... cannot be inspected").
- `mobile-push:health` has its own simpler `queue` check: it compares `retry_after` with the job timeout only.

## What to run

`php artisan mobile-push:install` prints the block below with the values of **your** settings (timeout = `ceil(queue.timeout + margin)`, here 55; memory 256 MB). Take the text from the command, not from this page, when your timeouts differ.

### Horizon

```php
// config/horizon.php, inside 'environments' => ['production' => [ ... ]]
'push' => [
    'connection' => 'redis',
    'queue' => ['push'],
    'balance' => false,          // fixed, always-on processes for one-to-one pushes
    'processes' => 3,
    'tries' => 1,                // the package owns retries; the job never re-runs blindly
    'timeout' => 55,
    'memory' => 256,
],
'push-bulk' => [
    'connection' => 'redis',
    'queue' => ['push-bulk'],
    'balance' => 'auto',         // scales with the backlog of campaign / bulk traffic
    'minProcesses' => 1,
    'maxProcesses' => 8,
    'balanceMaxShift' => 1,
    'balanceCooldown' => 3,
    'tries' => 1,
    'timeout' => 55,
    'memory' => 256,
],
```

The printed `connection` is `redis`; change it if your queue connection has another name. `tries => 1` is right for `SendPushJob`; `RelayDeliveriesJob` declares its own `tries = 3` (a job-level value wins over the worker option) and is safe to rerun. Remember `retry_after` of that Redis connection must be at least **61** s.

### Plain `queue:work`

Without Horizon, two workers under Supervisor or systemd (the connection is `queue.connection`, else your default queue connection, else `redis`):

```bash
php artisan queue:work redis --queue=push --tries=1 --timeout=55 --memory=256
php artisan queue:work redis --queue=push-bulk --tries=1 --timeout=55 --memory=256
```

`--memory=256` makes the worker exit when it grows past 256 MB so the process manager restarts it. (A test that runs a worker inside a large test process must raise it, for example `--memory=2048`: a worker that hits the limit stops after its first job.)

### The scheduler

The durable profile **needs the scheduler**: add the standard Laravel cron entry (`* * * * * php artisan schedule:run`) or run `schedule:work`.

What the package registers itself (each only when enabled, all `everyMinute()`):

| Name | What | Registered when |
|---|---|---|
| `mobile-push:sweep-ledger` | Dispatches `SweepLedgerJob` on the push queue | `delivery.profile = durable` and `queue.sweeper.schedule` (default true) |
| `mobile-push:sweep-campaigns` | Dispatches `SweepCampaignsJob` on the bulk queue | campaigns enabled, durable profile, `campaigns.sweeper.schedule` |
| `mobile-push:alerts` | Evaluates the operational alerts (`withoutOverlapping()`: needs a cache lock) | `alerts.enabled` |
| `mobile-push:live-activities-flush` | Sends the Live Activity updates that are due (`withoutOverlapping()`) | `live_activities.enabled` |

What **you** should add (the package does not schedule these, and nothing prunes automatically):

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mobile-push:deliveries:prune')->daily();          // durable profile; --days= or ledger.retention_days
Schedule::command('mobile-push:devices:prune')->daily();             // devices module
Schedule::command('mobile-push:logs:prune')->daily();                // database log sink
Schedule::command('mobile-push:credentials:check')->dailyAt('07:30'); // exit 1 = warning, 2 = error; sends expiry alerts unless --no-alert
Schedule::command('mobile-push:live-activity:prune')->daily();       // Live Activities
Schedule::command('mobile-push:channels:sync --cleanup --force')->dailyAt('03:30'); // broadcast channels: delete unused ones
```

Each prune command is safe to schedule on a profile or module that is off: it prints a notice and exits 0. `credentials:check` needs a connections source (`ManagerCredentialsSource` is bound by the package); it never contacts a provider. Command details: [commands](/operations/commands).

### The cache store

The queue and the guards share state through one Laravel cache store: provider tokens (encrypted), circuit breakers, rate-limit buckets, throttle gates, the relay follow-up markers, metrics counters, the fallback idempotency marker.

| Setting | Env | Default |
|---|---|---|
| `cache.store` | `MOBILE_PUSH_CACHE_STORE` | `null` (the application's default store) |
| `cache.prefix` | `MOBILE_PUSH_CACHE_PREFIX` | `mobile-push` |

Use a **lock-capable shared store**: `redis`, `memcached`, `database` or `dynamodb`. The `file` store is atomic on one host only; `array` lives inside one process (fine for tests, wrong for workers). Breakers and the rate limiter update their state with compare-and-set under cache locks; with a store that cannot take locks the update fails and a send can throw `Guard state is too contended to update` before the network. `doctor` checks reachability, read-after-write and lock support. An unreachable cache behaves as an empty one (tokens are re-fetched, state is lost) and never throws from the token and guard stores themselves.

## What is verified, per queue driver

| Driver | Status |
|---|---|
| `sync` | Run in tests: jobs execute in-process; timeout links are reported "not applicable". |
| `database` | Run in the scenario tests with a real `queue:work` loop (light chunks, continuations, the relay and the sweeper). |
| `redis` | **Not verified** against a real Redis. Only the arithmetic of the timeout chain is tested for its config shape (`retry_after`). |
| `sqs` | **Not verified**, including through an emulator. The visibility timeout is only compared when you declare it. |
| `beanstalkd` | **Not verified.** `retry_after` is read the same way as for Redis. |
| Horizon | **Not verified** against a running Horizon. The supervisor timeouts are read from the config files. The block printed by `install` is derived from your settings, not from a Horizon run. |
| Octane / Swoole / RoadRunner | **Not verified.** The transport's connection pools are per process, with a guard for `fork()`; behaviour under Swoole is an open question (see [transport](/reference/transport)). |

Treat Redis, SQS and Horizon as "designed for, not yet proven": run `mobile-push:doctor` and a canary push in a staging environment on your real backend before you rely on it, and check [troubleshooting](/operations/troubleshooting#troubleshooting-by-symptom-the-queue-sends-twice) for the symptoms of a bad chain.

---

*This page is generated from [`docs/queues-and-horizon.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/queues-and-horizon.md). To change it, edit that file in the repository, not this site.*
