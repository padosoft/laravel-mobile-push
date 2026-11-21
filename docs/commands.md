# Artisan commands

The package registers **38** `mobile-push:*` commands: 19 core and operational ones (always, when running in the
console), 11 for Live Activities and broadcast channels (only when `live_activities.enabled` is true) and 8 for
[campaigns](campaigns.md) (only when `campaigns.enabled` is true). Signatures, options and exit codes below are read
from the command classes.

Related pages: [safe mode](safe-mode.md), [troubleshooting](troubleshooting.md), [queues and Horizon](queues-and-horizon.md),
[security and privacy](security-and-privacy.md), [delivery profiles](delivery-profiles.md).

## Conventions

- **`--json`** prints one JSON document instead of text (where the signature lists it). A failure in JSON mode is
  `{"exit_code": N, "error": "...", "remedy": "..."}`.
- **Exit codes are part of the public contract** (see [upgrading](upgrading.md)). 0 is success; the meaning of the other
  codes is per command and listed in each section. Scripts should test the codes, not parse the text.
- **Tokens are never printed**: every table shows the masked form (`aabbcc…8899`). Tokens passed as arguments are visible
  in the process list and in the shell history: use `--stdin` or `--file`.
- **Questions.** Commands that ask for confirmation (`forget`, `circuit --reset`, `devices:probe-environment`,
  `channels:delete`, `channels:sync --cleanup`) refuse in a non-interactive run unless `--force` is given. Pass
  `--no-interaction` in scripts.
- **Safe mode.** Commands that could touch a provider ask the [execution mode](safe-mode.md) first. The last column of the
  table says what they do when the mode is `simulate` (the default outside production).
- **Scope.** Where `--scope` exists the default is `mobile-push.scope` (or your `scopeUsing()` callback). See
  [multi-tenancy](multi-tenancy.md).

| Command | What it does | Exit codes | In simulate mode |
|---|---|---|---|
| [`install`](#mobile-pushinstall) | Guided first setup | 0, 2 | local |
| [`doctor`](#mobile-pushdoctor) | Checks environment, config, credentials, cache, queue, database | 0, 1, 2 | `--live` refused (2) |
| [`send`](#mobile-pushsend) | Sends a push on the fly | 0 to 4 | results `simulated`, exit 3 |
| [`preview`](#mobile-pushpreview) | Shows what a message compiles to | 0, 2 | local |
| [`explain`](#mobile-pushexplain) | Explains a reason | 0, 1, 2 | local |
| [`simulator`](#mobile-pushsimulator) | Builds an iOS Simulator payload | 0, 2, 5 | local |
| [`connections`](#mobile-pushconnections) | Lists connections | 0, 2 | local |
| [`circuit`](#mobile-pushcircuit) | Shows and resets breakers | 0, 1, 2 | local |
| [`relay`](#mobile-pushrelay) | Runs the durable relay inline | 0, 1 | sends simulated |
| [`sweep`](#mobile-pushsweep) | Runs the ledger sweeper | 0, 1 | local |
| [`deliveries:prune`](#mobile-pushdeliveriesprune) | Deletes finished ledger rows | 0, 1 | local |
| [`devices:prune`](#mobile-pushdevicesprune) | Deletes dead registrations | 0, 1 | local |
| [`logs:prune`](#mobile-pushlogsprune) | Deletes old send-log rows | 0, 1 | local |
| [`health`](#mobile-pushhealth) | Liveness and readiness report | 0, 1, 2 | local |
| [`credentials:check`](#mobile-pushcredentialscheck) | Credential expiry and validity | 0, 1, 2 | local |
| [`forget`](#mobile-pushforget) | Erases one owner's data | 0, 1 | local |
| [`export`](#mobile-pushexport) | Exports one owner's data | 0, 1 | local |
| [`devices:probe-environment`](#mobile-pushdevicesprobe-environment) | Repairs quarantined tokens' environment | 0, 1, 2 | `not_sent` |
| [`fcm:topic-subscription`](#mobile-pushfcmtopic-subscription) | FCM topic subscribe / unsubscribe / list | 0, 1 | reported simulated, 0 |
| [`live-activity:start`](#live-activity-and-channel-commands) ... | Live Activities and broadcast channels (11) | 0, 1, 2 | "nothing was sent and nothing was written", 0 |
| [`campaign:list`](#campaign-commands) ... | Campaigns: list, status, pause, resume, cancel, reconcile, sweep, prune (8) | 0, 1, 2 | local (they read and change campaign rows; nothing is sent) |

## `mobile-push:install`

```
mobile-push:install
    {--modules= : Comma list of optional modules to enable: devices, live-activities, logs, campaigns, receipts (default: none)}
    {--profile= : Delivery profile: light or durable (default: asked, or light when not interactive)}
    {--force : Overwrite files that were already published}
```

A guided first setup. Interactive, it asks which optional modules and which delivery profile; non-interactive it works
from `--modules` and `--profile` alone (defaults: no module, light profile). `receipts` with the light profile is
refused (receipts need the ledger).

It **publishes** `config/mobile-push.php` (kept if it exists, unless `--force`) and the migrations of the modules and
profile you chose, then prints three things: the `.env` variables for one APNs and one FCM connection plus the module
switches; the Horizon supervisors and the plain `queue:work` commands for your queue settings (the text in
[queues and Horizon](queues-and-horizon.md#what-to-run)); and the next step, `php artisan mobile-push:doctor`. It also
reminds you that until `MOBILE_PUSH_MODE=live` (or `APP_ENV=production`) every send is simulated.

| Exit | Meaning |
|---|---|
| 0 | Installed |
| 2 | Invalid option (unknown module or profile, receipts without durable): nothing was published |

> **Migrations.** The package follows one rule: migrations are published, not auto-loaded. `install` publishes the
> files of the modules you chose and `php artisan migrate` runs the copies; nothing is registered twice. With
> `MOBILE_PUSH_MIGRATIONS_AUTOLOAD=true` `install` publishes nothing and says why (the modules load their own files);
> a copy you already published takes precedence. See [delivery profiles](delivery-profiles.md#migrations).
>
> The question about the delivery profile describes it in one sentence: **light** is best effort (a crash can lose or
> duplicate a push); **durable** keeps intents across crashes and never re-sends an accepted push, reports an outcome
> that cannot be known as unknown instead of guessing, and a replaceable message retried after an unknown outcome can
> arrive twice. The exact windows are in [delivery profiles](delivery-profiles.md).

## `mobile-push:doctor`

```
mobile-push:doctor
    {connection? : Check only this connection}
    {--live : Also send REAL probe requests (one per connection) to prove the authentication works}
    {--json : Print the result as JSON}
```

Checks, in sections: **PHP and curl** (extensions, libcurl version, HTTP/2, ALPN, client certificates in memory),
**Configuration** (the file parses; delivery profile), **Execution mode** (simulated, live outside production), one
section per **Connection** (credential parses, type, expiry, secondary, generation), **Cache** (reachable,
read-after-write, lock support), **Queue timeouts** (the [timeout chain](queues-and-horizon.md#the-timeout-chain)),
**Database** (the tables of every enabled module exist), **Clock** (an informational reminder: skew cannot be checked
offline).

Each line is `✓` ok, `i` info, `! warning`, `! not verifiable` or `✗ failed`, and failures carry a `Remedy:`.

```text
$ php artisan mobile-push:doctor
PHP and curl
  ✓ PHP 8.3.6 with the required extensions (curl, openssl, json).
  ...
Execution mode
  i SIMULATED: mode is "auto" and APP_ENV is "local", so NO request is sent to APNs or FCM; every send ends as "simulated".
      Remedy: To send for real set MOBILE_PUSH_MODE=live (outside production also set live.allow=true ...).
Connection "ios"
  ✓ The APNs key parses and is EC P-256.
  ✓ apns: com.example.shop (production, team ABCDE12345, key id KEY1234567), generation e56c21da2d23.
Queue timeouts
  ✓ transport_timeout (30 s) < chunk_deadline (40 s), margin 5 s
  ✗ failed: job_timeout (120 s) < retry_after (90 s), margin 12 s: violation
      Remedy: Raise retry_after to at least 132 s (...), or lower job_timeout to at most 81.818 s (mobile-push.queue.timeout).

10 ok, 0 warning(s), 2 not verifiable, 2 failure(s).
Result: failures found, fix them before sending (exit 2).
```

**`--live`** adds one **real** request per connection, announced before it is sent: for APNs, one push to an obviously
invalid device token (APNs is expected to answer `BadDeviceToken`, which proves the credential works; APNs counts that
request); for FCM, one `validate_only` call (validated by Google, never delivered). `--live` is **refused** when the
effective mode is `simulate` (it would prove nothing).

| Exit | Meaning |
|---|---|
| 0 | Everything checked is fine |
| 1 | At least one warning or one check that **cannot be verified** (never reported as ok) |
| 2 | At least one failure, or `--live` refused, or invalid input (unknown connection, invalid mode) |

## `mobile-push:send`

```
mobile-push:send
    {tokens?* : Device tokens (visible in the process list and shell history: prefer --stdin or --file for real sends)}
    {--platform= : ios or android: the platform of the raw tokens}
    {--connection= : The connection to send through}
    {--scope= : Tenant scope of the connection (default: mobile-push.scope)}
    {--file= : File with one token per line}
    {--stdin : Read tokens from standard input, one per line (recommended for real sends)}
    {--user= : Send to the registered devices of this user id (needs mobile-push.devices.enabled)}
    {--topic= : Send to this FCM topic (an Android connection; needs live.allow_topics outside production)}
    {--title= : Notification title}
    {--body= : Notification body}
    {--data=* : Custom data as key=value (repeatable)}
    {--badge= : Badge number (0 or more)}
    {--sound= : Sound name ("default" for the system sound)}
    {--type=alert : alert (visible notification) or silent (background / data-only)}
    {--queue : Queue the send instead of sending now}
    {--validate-only : FCM only: have Google validate the request without sending it}
    {--json : Print the result as JSON}
```

Sends one push. **Exactly one kind of destination** per run: tokens (arguments, `--file`, `--stdin`), `--user=ID` (the
model of `auth.providers.users.model`, which must use the devices trait) or `--topic=NAME`. **The platform is never
guessed.** Name it with `--platform` or `--connection`; only an interactive terminal may *propose* one from the shape of
the tokens, and it asks you to confirm before anything is sent (not with `--stdin`, not non-interactive). `--type=silent`
(or `background`) sends a background push. `--validate-only` needs an FCM connection (APNs has no dry run; use `preview`).
`--queue` goes through the profile's queue and prints the effect id; results are not known until a worker runs the job.

```text
$ printf '%s\n' "$TOKEN" | php artisan mobile-push:send --platform=ios --connection=ios --stdin --title="Hello" --body="World"
+-------------+----------+--------+------+---------------------+
| Destination | Status   | Reason | Hint | Provider message id |
+-------------+----------+--------+------+---------------------+
| aabbcc…8899 | accepted |        |      | 3b1c0f6e-...        |
+-------------+----------+--------+------+---------------------+
1 destination(s): 1 accepted. Exit code 0 (everything accepted (or validated)).
```

Only the first 100 rows are shown; `--json` has them all:
`{"exit_code", "connection", "summary", "counts", "results": [{destination, kind, status, reason_code, raw_reason, hint, provider_message_id, http_status, attempts, next_attempt_at}], "warnings", "simulated"}`.

| Exit | Meaning |
|---|---|
| 0 | Everything accepted (or validated by FCM `validate_only`); also `--queue` queued |
| 1 | **Partial**: at least one destination was rejected, failed, **skipped** (for example blocked by safe mode), deferred or expired |
| 2 | Invalid input or configuration; nothing was sent; also "there was nobody to send to" |
| 3 | **Simulated**: nothing was sent (the effective mode is `simulate`) |
| 4 | At least one **unknown outcome** (the request may or may not have been processed). It wins over every other code |

Safe mode: with `mode = simulate` the output has a `SIMULATED: NOTHING WAS SENT` banner and exit 3; blocked destinations
are `skipped` / `policy_safe_mode`, exit 1. See [safe mode](safe-mode.md).

## `mobile-push:preview`

```
mobile-push:preview
    {--platform= : ios or android (needed without --connection)}
    {--connection= : Compile for this connection (its app id and environment)}
    {--scope= : Tenant scope of the connection}
    {--title= : Notification title}
    {--body= : Notification body}
    {--data=* : Custom data as key=value (repeatable)}
    {--badge= : Badge number (0 or more)}
    {--sound= : Sound name ("default" for the system sound)}
    {--type=alert : alert (visible notification) or silent (background / data-only)}
    {--json : Print the result as JSON}
```

Compiles a message **locally** and prints the headers and the JSON that would go on the wire, the size against the
provider limit, warnings, what needs app code and what the platform does not support. It sends nothing and validates
nothing remotely: no credential, token or topic is checked (APNs has no dry run). Without `--connection` it uses a
placeholder binding.

```text
Destination  <APNs device token placeholder>
Headers (credentials are added at send time and never shown)
  apns-push-type: alert
  apns-topic: com.example.app
  apns-priority: 10

Payload
{ "aps": { "alert": { "title": "Hi", "body": "There" } }, "order": "42" }

Size 60 of 4096 bytes (1%)
```

Exit: 0 compiled; 2 invalid input, invalid message or payload over the limit.

## `mobile-push:explain`

```
mobile-push:explain
    {reason? : An APNs reason (BadDeviceToken), an FCM error code (UNREGISTERED) or a package reason code (destination_rejected)}
    {--provider= : Restrict to apns, fcm or package}
    {--all : Print the whole catalog as a table}
    {--json : Print the result as JSON}
```

Prints, for a reason, the cause and remedy, the package reason code, the resulting status and what the package does.
`--all` prints the whole catalog (95 entries: 43 APNs, 16 FCM, 36 package codes in this version); the same catalog is in
[troubleshooting](troubleshooting.md#reason-catalog).

```text
$ php artisan mobile-push:explain BadDeviceToken
APNs reason: BadDeviceToken (HTTP 400)
  Cause and remedy: APNs refused the device token: it is malformed or belongs to the other environment ...
  Package reason code: destination_rejected
  Resulting status: rejected
  What the package does:
    - Does not retry: the same request would fail again.
    - Final status: rejected.
    - Quarantines the registration (kept, but not used until it is probed or re-registered).
```

| Exit | Meaning |
|---|---|
| 0 | Explained |
| 1 | Not a reason the package knows (the closest matches are listed) |
| 2 | Invalid input (unknown `--provider`, nothing to explain) |

## `mobile-push:simulator`

```
mobile-push:simulator
    {--bundle= : Bundle id of the app in the simulator (default: the bundle of the only APNs connection)}
    {--device=booted : Simulator device id, or "booted"}
    {--export= : Write the .apns payload file to this path}
    {--title= : Notification title}  {--body= : Notification body}  {--data=* : Custom data as key=value (repeatable)}
    {--badge= : Badge number (0 or more)}  {--sound= : Sound name}  {--type=alert : alert or silent}
```

Builds the `.apns` file the iOS Simulator accepts (the payload plus the `Simulator Target Bundle` key), writes it to
`--export`, and on macOS with Xcode pushes it with `xcrun simctl push`. It checks how a notification **renders** in the
simulator; it does **not** exercise APNs, your credentials, the token or the environment. Without `--bundle` it uses the
bundle of the only APNs connection and refuses to guess when there are several.

Exit: 0 done; 2 invalid input, nothing written or pushed (also: no `--export` and no `xcrun`); 5 `xcrun simctl push`
failed. The `xcrun` step was not verified on a real Mac.

## `mobile-push:connections`

```
mobile-push:connections
    {--scope= : Tenant scope used to compute the binding generation (default: mobile-push.scope)}
    {--json : Print the result as JSON}
```

Lists the configured connections: provider, app id, environment, auth type, **non-secret** credential identity (team and
key id, certificate fingerprint, Google principal), generation, whether a secondary exists, the live-sending state
outside production and the days left of a certificate. No secret is ever shown. A connection with a configuration
problem is listed with its error. Exit 0 (even with a broken connection); 2 for an invalid mode.

```text
Effective mode: simulate (configured "auto", APP_ENV "local").
| Name | Provider | App id           | Env        | Auth        | Credential                         | Generation   | Live outside production  | Days left |
| ios  | apns     | com.example.shop | production | token (.p8) | team ABCDE12345, key id KEY1234567 | e56c21da2d23 | blocked (live.allow off) | -         |
```

## `mobile-push:circuit`

```
mobile-push:circuit
    {connection? : Connection name (default: every configured connection)}
    {--reset : Close the breakers after confirmation}
    {--force : With --reset: do not ask for confirmation}
    {--json : Print the result as JSON}
```

Shows, per connection, the **authentication breaker** (state, consecutive failures of the threshold, credential generation,
next probe), the **destination-quality breaker** (state, sample, bad count) and the **provider throttle** (paused
until). `--reset` closes the two breakers after confirmation; the provider throttle is the provider's own request and is
left alone. Fix the cause first: a breaker that opened for a real reason opens again. See
[retries and limits](retries-and-limits.md#the-three-circuit-breakers).

```text
Connection "ios"
  Authentication breaker: closed (failures in a row: 0 of 5, credential generation 0)
  Destination-quality breaker: closed (sample 0, bad 0)
  Provider throttle: none

Everything is closed (exit 0).
```

| Exit | Meaning |
|---|---|
| 0 | Everything closed (after a reset: the state is read again) |
| 1 | A breaker is open or half-open, or the provider is throttling |
| 2 | Invalid input: no connection configured, unknown connection, `--reset` not confirmed or not interactive without `--force` |

## `mobile-push:relay`

```
mobile-push:relay
    {--scope= : Scope (tenant) to relay; default is the current scope}
    {--effect= : Relay only the deliveries of this effect id}
    {--once : Do a single relay pass instead of repeating until nothing is due}
    {--json : Print the summary as JSON}
```

**Durable profile only.** Runs the relay (the work of a `RelayDeliveriesJob`) in this process: for a cron-driven setup
without a queue worker, and for debugging. It first applies lease expiry and expiry, then repeats passes until nothing is
due (a pass stops at the chunk deadline and hands over), and prints counters only.

```text
 INFO  Relay finished.

  scope ............................................................ default
  passes ..................................................................... 1
  claimed .................................................................... 0
  sent ....................................................................... 0
  recorded ................................................................... -
  deferred.next_attempt_at ................................................... -
  deferred.follow_up_relays_scheduled ........................................ 0
  reclaimed_leases.requeued_not_started ...................................... 0
  reclaimed_leases.retried_after_unknown ..................................... 0
  reclaimed_leases.ended_unknown_outcome ..................................... 0
  expired .................................................................... 0
  unknown_outcomes ........................................................... 0
  leases_lost_before_sending ................................................. 0
  writes_ignored ............................................................. 0
  now ............................................................ 2026-11-21T00:48:35+00:00
```

Exit 0 on success; **1** when refused (the light profile: "The relay needs the durable delivery profile") or on an invalid
option. Sends follow the normal pipeline, including [safe mode](safe-mode.md): in simulate mode the deliveries end
`simulated`.

## `mobile-push:sweep`

```
mobile-push:sweep
    {--json : Print the summary as JSON}
```

**Durable profile only.** Runs one ledger sweep inline: lost leases are applied (requeued, retried after an unknown
outcome, or ended as unknown), overdue deliveries expired, and a scope-wide relay job handed to the queue for every scope
with due work. It prints `scopes_visited`, `leases_requeued_not_started`, `leases_retried_after_unknown`,
`ended_unknown_outcome`, `expired` and `relay_jobs_dispatched_for_scopes`. Exit 0; 1 on the light profile.

## `mobile-push:deliveries:prune`

```
mobile-push:deliveries:prune
    {--days= : Keep finished deliveries this many days (default: mobile-push.ledger.retention_days, 30)}
    {--scope= : Prune only this scope; default is every scope}
    {--pretend : Count what would be deleted without deleting}
    {--json : Print the summary as JSON}
```

Deletes **terminal** deliveries (and the effects left empty) whose last change is older than the retention and whose
effect is past its deduplication window. Unfinished rows are never deleted. On the light profile it prints a notice and
exits 0, so it is safe to schedule everywhere. Output: `retention_days`, `scope`, `pretend`, and `deleted.deliveries` /
`deleted.effects` (or `would_delete.*` with `--pretend`). Exit 0; 1 on an invalid option (`--days` must be a whole number).

## `mobile-push:devices:prune`

```
mobile-push:devices:prune
    {--invalidated-days= : Delete invalidated registrations this many days after the change (default: devices.prune.invalidated_days, 30)}
    {--inactive-days= : Delete registrations not seen for this many days (default: devices.prune.inactive_days, 365)}
    {--pretend : Count what would be deleted without deleting}
    {--json : Print the summary as JSON}
```

Deletes invalidated registrations after a grace period and any registration not seen for a long time. With the devices
module off it prints a notice and exits 0. Output: `invalidated_days`, `inactive_days`, `pretend`, `deleted.invalidated`,
`deleted.inactive`, `deleted.total`. Exit 0; 1 on an invalid option.

## `mobile-push:logs:prune`

```
mobile-push:logs:prune
    {--days= : Keep log rows this many days (default: log.database.retention_days, 30)}
    {--pretend : Count what would be deleted without deleting}
    {--json : Print the summary as JSON}
```

Deletes send-log rows (database sink only) older than the retention. Without the database sink: a notice, exit 0. Exit 0;
1 on an invalid option.

## `mobile-push:health`

```
mobile-push:health
    {--json : Print the report as JSON}
```

A report for a monitoring system, in two sections: **Liveness** (PHP extensions, curl capabilities, configuration) and
**Readiness** (credentials of every connection, cache, ledger backlog, log sinks, circuit breakers and throttled
accounts, queue `retry_after` against the job timeout, execution mode). It **never contacts a provider**; a live probe is
a separate operation (`doctor --live`). A check that cannot be verified is reported as such and does not degrade the
exit code.

```text
 INFO  Mobile push health: OK (exit code 0).

Liveness [ok]
| php_extensions    | ok | Required PHP extensions are loaded (curl, openssl, json). |
| curl_capabilities | ok | libcurl 8.5.0 with HTTP/2 and TLS.                        |
| config            | ok | The configuration parses.                                 |

Readiness [ok]
| credentials.ios | ok | Credentials parse and are valid.                    |
| cache           | ok | The cache store is reachable and lock-capable.      |
| execution_mode  | ok | Execution mode is simulate in the "local" environment. |
```

Exit: 0 ok, 1 warning, 2 failed. A ledger whose oldest due delivery is older than `health.ledger_backlog_seconds` (300)
is a warning, older than `health.ledger_backlog_failed_seconds` (3600) a failure.

## `mobile-push:credentials:check`

```
mobile-push:credentials:check
    {--no-alert : Only report, do not send expiry alerts}
    {--json : Print the findings as JSON}
```

Inspects the credentials of every configured connection (key validity and type, certificate expiry and team, service
account) **without contacting any provider**, and, unless `--no-alert`, raises the expiry alerts (see `alerts.*` and
`credentials.warn_days`, default 30). Schedulable. Connections supplied only by a runtime resolver are not enumerated
([multi-tenancy](multi-tenancy.md)).

```text
ios (apns, com.example.shop, production) - ok
| Severity | Code           | Finding and remedy                   |
| ok       | apns.key.valid | The APNs key parses and is EC P-256. |
```

Exit: 0 ok **or not verifiable** (when no credentials source is bound the command says so and does not pass), 1 warnings (for
example a certificate close to expiry), 2 errors.

## `mobile-push:forget`

```
mobile-push:forget
    {owner : The owner as Type:id, for example "App\\Models\\User:42"}
    {--scope= : Scope (tenant) to erase in; default is the current scope. Other scopes are never touched}
    {--force : Do not ask for confirmation}
    {--json : Print the report as JSON}
```

Erases what the package holds about one owner inside one scope (devices, Live Activities, ledger deliveries and message
snapshots, send log rows), says per store how many rows were affected and **always lists what it cannot reach**. It does
not make an application compliant with any regulation. Idempotent: run it again if a store failed. Full coverage in
[security and privacy](security-and-privacy.md#forget-and-export).

```text
 INFO  Forget report for an owner of type App\Models\User in scope "default":

  - ledger: pending deliveries cancelled: 0 row(s) affected
  - devices: 0 row(s) affected (registrations deleted, tokens included)
  - live activities: not present, nothing touched (module not installed or not migrated)
  - ledger: deliveries erased: 0 row(s) affected (destination, owner and device snapshot erased; 0 message snapshot(s) erased; non-personal tombstones kept)
  - send log: not present, nothing touched (no log table (database sink not installed))
Not reached by this command:
  * Database backups and snapshots ...
  * External collectors: OpenTelemetry backends, log files and log channels ...
  * Jobs still waiting in the queue on the light profile ...
  * The providers' own systems (Apple APNs, Google FCM) and notifications already delivered to the device.
  * Your application's own tables, caches and logs.
This command alone does not make the application compliant with the GDPR or any other regulation: ...
```

Exit: **0** done; **1** refused (non-interactive without `--force`; confirmation declined; malformed `Type:id`) or a store
failed.

## `mobile-push:export`

```
mobile-push:export
    {owner : The owner as Type:id, for example "App\\Models\\User:42"}
    {--scope= : Scope (tenant) to export from; default is the current scope}
    {--output= : Write the JSON to this file instead of printing it}
```

Writes a JSON document of what the package holds about one owner in one scope: `owner`, `scope`, `generated_at`, then
`devices`, `live_activities`, `deliveries`, `effects`, `send_log` (each with `status`, `truncated` and `rows`) and `notes`.
Tokens are masked; clear tokens, receipt markers, encrypted envelopes, keyed indexes and message contents are never
written; each store is capped at 10,000 rows. Without `--output` the JSON is the only thing printed (safe to pipe). With
`--output` the file is created with mode 0600 and an existing file is never overwritten. Exit 0; 1 on a malformed owner or
an output path that exists or cannot be created.

## `mobile-push:devices:probe-environment`

```
mobile-push:devices:probe-environment
    {--device= : Probe this single device (registry id); it must be quarantined}
    {--quarantined : Probe the quarantined APNs device tokens of the scope}
    {--connection= : Name of the connection of the same app on the other environment (when several match)}
    {--limit=10 : Devices to probe in one run (1-100): every rejected token counts against Apple's error budget}
    {--allow-cross-environment : Required: acknowledge that tokens are sent to the other APNs environment}
    {--scope= : Scope (tenant); default is the current scope}
    {--ignore-cooldown : Also probe devices probed less than devices.probe.cooldown_hours ago (always asks, --force does not skip it)}
    {--force : Do not ask for the usual confirmation (it does NOT bypass the cooldown: use --ignore-cooldown)}
    {--json : Print the result as JSON}
```

The explicit repair for tokens registered under the wrong APNs environment: one silent push per quarantined device on the
other environment, one at a time. **Read [the environment probe](troubleshooting.md#environment-probe) before you run
it**: what it does, why it is explicit and its risks. It needs the devices module, a second connection of the same app
for the other environment, live mode (and, outside production, the allowlist).

```text
$ php artisan mobile-push:devices:probe-environment --quarantined --allow-cross-environment --force
| Device | Token       | App              | From       | To      | Outcome     | Detail                                                   |
| 7      | aabbcc…8899 | com.example.shop | production | sandbox | relabelled  | Accepted on sandbox: re-labelled and active, generation 3. |
```

Outcomes: `relabelled`, `still_rejected`, `untouched`, `conflict`, `changed`, `not_sent`, `blocked`, `no_connection`,
`authentication_error`, `not_contacted`, `unreadable`, `cooling_down`.

**Frequency limit.** Every rejected probe counts against Apple's error budget, so a device is contacted **at most once
per `devices.probe.cooldown_hours`** (`MOBILE_PUSH_DEVICES_PROBE_COOLDOWN_HOURS`, default 24; 0 switches the protection
off). When a probe sent a request, the time and the outcome are stored in the device row (`metadata.environment_probe`:
no new column, survives restarts and is shared by every host). A device still inside its cooldown is **not contacted**:
it appears in the table as `cooling_down` with the time of its last probe, its outcome and the instant it may be probed
again, and it does not use one of the `--limit` places, so a cron that runs every hour moves on to the next devices
instead of repeating the same ones. `--force` does **not** bypass the cooldown. `--ignore-cooldown` does, after an
interactive confirmation that `--force` does not replace; a non-interactive run with `--ignore-cooldown` is refused.
`--limit` (default 10, at most 100) remains the per-run cap. In JSON the result carries `cooling_down` (how many were
skipped); at most 100 skipped devices are listed per run.

| Exit | Meaning |
|---|---|
| 0 | Every device got a clean answer (`relabelled`, `still_rejected`, `not_sent`, `cooling_down`), or nothing matched |
| 1 | Something needs attention (`untouched`, `blocked`, `conflict`, `changed`, `no_connection`, `unreadable`, `not_contacted`), or the run was refused (no `--allow-cross-environment`, nothing selected, `--limit` over 100, devices module off, non-interactive without `--force`, `--ignore-cooldown` in a non-interactive run, declined) |
| 2 | The run was **stopped by an authentication error** (a key limited to one environment answers like this) |

## `mobile-push:fcm:topic-subscription`

```
mobile-push:fcm:topic-subscription
    {action : subscribe, unsubscribe or list}
    {topic? : The topic (subscribe and unsubscribe)}
    {--connection= : The FCM connection to use (default: the only FCM connection)}
    {--token=* : FCM registration token (repeatable)}
    {--fid=* : FCM installation id (repeatable)}
    {--stdin : Read installations from standard input, one per line (token, or fid=<id>)}
    {--scope= : Scope (tenant); default is the current scope}
    {--json : Print the result as JSON}
```

Subscribes or unsubscribes installations to an FCM topic, or lists an installation's subscriptions. One call per
installation (FCM has no bulk operation), multiplexed; one result row per installation. With `--stdin`, a bare line is a
registration token and a line `fid=<id>` is an installation id (a token is never guessed from its shape). Tokens given as
options end up in your shell history: use `--stdin`. Topic subscription is a group operation: outside production it needs
`live.allow_topics`.

Exit: 0 every installation succeeded (or the mode is simulate: nothing was sent); 1 at least one failed or was blocked
by safe mode, or the usage was wrong.

## Live Activity and channel commands

Registered only when `live_activities.enabled` is true. Every one refuses with exit 1 and a message when the module is
disabled. The shared exit codes: **0** done (a simulated run is "done": it reports what it would do, writes nothing and
sends nothing); **1** nothing was done or the operation did not succeed (module disabled, blocked by safe mode, APNs
refused, outcome unknown, activity closed, nothing to send to); **2** invalid input, detected before any effect. Tokens
are masked. For the model of Live Activities see [live activities](live-activities.md).

| Command and arguments | Options |
|---|---|
| `mobile-push:live-activity:start {type}` | `--owner=Type:id` (required) `--title=` (required) `--body=` `--attributes=JSON` `--state=JSON` `--connection=` `--scope=` `--input-push-token` `--channel=` `--low-priority` `--json` |
| `mobile-push:live-activity:update {activity}` | `--state=JSON` (required) `--stale=` `--title=` `--body=` `--relevance=` `--low-priority` `--json` |
| `mobile-push:live-activity:end {activity}` | `--state=JSON` `--dismiss=` `--json` |
| `mobile-push:live-activity:list` | `--owner=Type:id` `--type=` `--state=requested|active|ending|ended|expired` `--scope=` `--limit=50` (1 to 1000) `--json` |
| `mobile-push:live-activity:flush` | `--limit=100` (1 to 10000) `--json` |
| `mobile-push:live-activity:prune` | `--days=` (default `live_activities.prune.closed_after_days`, 7) `--pretend` `--json` |
| `mobile-push:channels:list` | `--connection=` `--scope=` `--state=creating|active|deleting|deleted|unknown` `--json` |
| `mobile-push:channels:create` | `--connection=` `--scope=` `--storage=none|recent` `--label=` `--type=` |
| `mobile-push:channels:delete {channel}` | `--force` |
| `mobile-push:channels:publish {channel}` | `--state=JSON` `--end` `--stale=` `--dismiss=` `--json` |
| `mobile-push:channels:sync` | `--connection=` `--scope=` `--adopt=CHANNEL_ID[:LOCAL_ROW_ID]` `--cleanup` `--idle-hours=` `--force` `--json` |

- `start` sends a remote start to the push-to-start tokens of **one owner**. It needs a token the app has reported;
  `Accepted` means APNs took the request, not that the activity is on the screen. A start is never retried (a duplicate
  would show two activities).
- `update` and `end` take the activity id from `list`; durations accept an ISO-8601 instant or a relative form such as
  `"+10 minutes"`. `update` with a second update in the same second reports `Deferred: another update was already sent
  in this second` (exit 0): the latest state goes out from the next second, through `flush` or the scheduler.
- `flush` sends the transitions that are due (the package schedules it every minute when the module is on).
- `prune` deletes old closed rows (`ended` and `expired` activities older than `--days`) and still marks as `expired` any
  open row whose local expiry passed without a transition. The expiry itself is enforced at transition time (every
  `update` and `end` checks it), so a **daily** prune is enough; see [live activities](live-activities.md).
- `channels:*` manage APNs **broadcast channels** (iOS 18+): `create`, `delete` (irreversible), `publish` (one publication,
  not N deliveries), `sync` (reconcile with the list APNs holds, in both directions; `--cleanup` deletes channels no
  open activity references, `--force` is for the scheduler). Channel management needs `live.allow_channel_management`
  outside production, and `sync` exits 1 when an orphan channel remains.
- In simulate mode or when safe mode blocks, these commands ask the guard **before** the first database write: nothing is
  sent and **nothing is written** (no `requested` row, no transition number, no `creating` channel row).

## Campaign commands

Eight commands over the [campaigns](campaigns.md) module (registered while `campaigns.enabled` is true; they need the
durable profile and refuse otherwise, exit 1). `--scope` defaults to the current scope; `--json` prints one JSON document
(one compact line per refresh with `--watch`). Exit codes: **0** done (or consistent, or already in the wanted state);
**1** refused (module disabled, light profile), not allowed in the campaign's current state, declined, or a difference
found by `reconcile`; **2** invalid input or campaign not found in this scope. None of them contacts a provider: they read
the ledger and change campaign rows.

| Command and arguments | Options |
|---|---|
| `mobile-push:campaign:list` | `--scope=` `--status=draft|planning|running|paused|completed|cancelled|failed` `--limit=20` (1 to 200, newest first) `--json` |
| `mobile-push:campaign:status {campaign}` | `--scope=` `--watch=N` (refresh every N seconds, 1 to 3600, until completed, cancelled or failed) `--json` |
| `mobile-push:campaign:pause {campaign}` | `--scope=` `--json` |
| `mobile-push:campaign:resume {campaign}` | `--scope=` `--json` |
| `mobile-push:campaign:cancel {campaign}` | `--scope=` `--force` (skip the question; required with `--json`) `--json` |
| `mobile-push:campaign:reconcile {campaign}` | `--scope=` `--json` |
| `mobile-push:campaign:sweep` | `--json` |
| `mobile-push:campaign:prune` | `--days=` (default `campaigns.retention_days`) `--scope=` (default every scope) `--pretend` `--json` |

- `status` shows where a campaign is, with every count read from the ledger.
- `pause` stops planning; `resume` continues a **paused or failed** campaign from its stored cursor. Both are safe to
  repeat (already paused / already running exit 0).
- `cancel` plans and sends nothing more and cancels the deliveries not yet sent. It asks for confirmation (interactive;
  `--force` or a refusal in `--json` without it) because it **cannot undo** what the provider already accepted or what is
  in flight at that moment.
- `reconcile` compares the planner's count with the deliveries in the ledger and **never corrects**: it exits 1 when it
  finds a difference.
- `sweep` restarts stalled planner chains and completes finished campaigns in every scope; it is the command form of the
  job the scheduler runs every minute (`campaigns.sweeper.schedule`), so it is safe to schedule or run by hand.
- `prune` deletes finished campaign rows (`completed`, `cancelled`, `failed`) older than the retention; `--pretend`
  counts and changes nothing. The ledger rows prune themselves with [`deliveries:prune`](#mobile-pushdeliveriesprune).

## Not verified

Run against a Testbench application with simulated and local data: `doctor`, `connections`, `circuit`, `send` (simulate
and blocked), `preview`, `explain`, `simulator --export`, `health`, `credentials:check`, `relay`, `sweep`, the three
`prune` commands, `forget` and `export` (on empty tables) and the refusals of `devices:probe-environment`. **Not**
verified against real systems: any command that sends (`send --live`, `doctor --live`, `devices:probe-environment`,
`fcm:topic-subscription`, the Live Activity and channel commands) against real APNs and FCM; `simulator` with a real
`xcrun`; `relay` and `sweep` on MySQL or PostgreSQL under concurrent workers; `send --user` with a real user model.
