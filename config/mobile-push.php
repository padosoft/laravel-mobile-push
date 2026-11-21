<?php

declare(strict_types=1);
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Health\RequireMetricsToken;
use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushToStartToken;

/*
 * Laravel Mobile Push configuration.
 *
 * Every key below is described in Padosoft\MobilePush\Laravel\Integration\ConfigSchema, and docs/configuration.md is
 * GENERATED from that schema (ConfigReference::write()): never edit that document by hand. A test fails when a key
 * read by the package is missing from the schema, or when a default here differs from the schema.
 *
 * Note for maintainers: Laravel loads this file inside a method that has its own $key and $path variables.
 * Never declare a local variable with a common name here (see lesson.md).
 */

$versionedKey = static fn (string $name): array => array_filter([1 => env($name)], static fn ($value): bool => is_string($value) && $value !== '');

return [

    /*
    |--------------------------------------------------------------------------
    | Default scope and connection
    |--------------------------------------------------------------------------
    | "scope" is the tenant boundary used when the application does not supply one with
    | MobilePush::scopeUsing() or ->scope(). It is never read from client payload.
    | Allowed characters: letters, digits, ".", "_", ":" and "-" (max 64).    env MOBILE_PUSH_SCOPE
    |
    | "default" is the connection used by MobilePush::connection() without a name. When it is null,
    | an unnamed send is routed by provider (see "routes").                    env MOBILE_PUSH_CONNECTION
    */
    'scope' => env('MOBILE_PUSH_SCOPE', 'default'),

    'default' => env('MOBILE_PUSH_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Connections (app bindings)
    |--------------------------------------------------------------------------
    | One entry per app and provider. Each connection is validated the first time it is used: a wrong key,
    | an expired certificate or a secondary credential of another team/project throws a
    | ConfigurationException that says how to fix it, before any request is sent.
    |
    | Secrets are never written here directly as values you could leak: each secret is a SOURCE with
    | exactly one of
    |   'path'     => absolute path of the file (.p8, .pem, service-account .json)
    |   'inline'   => the text itself (PEM or JSON; "\n" sequences are accepted)
    |   'base64'   => the base64 of the file (handy for one-line environment variables)
    |   'resolver' => a class-string implementing Padosoft\MobilePush\Core\Auth\SecretResolver,
    |                 with 'reference' => what the resolver should fetch (vault path, secret name...)
    | Never put a closure here: the config must stay cacheable (php artisan config:cache).
    |
    | APNs keys:
    |   driver            'apns'
    |   auth              'token' (a .p8 key, recommended) | 'certificate' (mTLS)
    |   team_id           Apple team id (10 characters)
    |   key_id            id of the .p8 key (token auth)
    |   key               secret source of the .p8 key (token auth)
    |   certificate       secret source of the PEM certificate (may also contain the key)  (certificate auth)
    |   certificate_key   secret source of the private key when it is a separate file       (certificate auth)
    |   passphrase        the key passphrase, as a string or a secret source                (certificate auth)
    |   bundle_id         the app bundle id (the APNs topic)
    |   environment       'production' | 'sandbox' — the environment of the app build, never guessed
    |   secondary         optional rotation twin: same keys as above (team_id defaults to the primary's);
    |                     must belong to the same team and environment
    |   port              443 (default) | 2197
    |   broadcast_host    'standard' (default) | 'api-broadcast' (host used to publish Live Activity broadcasts)
    |
    | FCM keys:
    |   driver            'fcm'
    |   auth              'service_account' (default) | 'adc' (Application Default Credentials, needs google/auth)
    |   credentials       secret source of the service-account JSON (service_account auth)
    |   project_id        Firebase project id; null = taken from the service-account JSON (required for adc)
    |   secondary         optional rotation twin: ['credentials' => source]; same project only
    |   http_version      '2' (default) | '1.1'
    |
    | Every connection may also set:
    |   live              sending for real OUTSIDE production (see "mode" below):
    |                       allow                     true to allow live sends from this non-production app
    |                       only_to                   list of raw TEST device tokens allowed to receive them;
    |                                                 a string is a device token of the connection's default
    |                                                 kind (APNs device token / FCM registration token), or
    |                                                 ['kind' => 'apns_voip_token', 'token' => '...'].
    |                                                 They are converted to keyed indexes at load time and
    |                                                 never stored or logged raw.
    |                       allow_topics              also allow FCM topics and conditions
    |                       allow_broadcast           also allow APNs broadcast channels
    |                       allow_channel_management  also allow creating/deleting broadcast channels
    |   window, max_connections, connect_timeout_ms, timeout_ms, max_idle_seconds, proxy, ca_bundle
    |                     transport knobs; when absent the "transport" block below applies
    |
    | Ready-to-uncomment example driven by .env only:
    |
    |   'ios' => [
    |       'driver' => 'apns',
    |       'auth' => 'token',
    |       'team_id' => env('MOBILE_PUSH_APNS_TEAM_ID'),
    |       'key_id' => env('MOBILE_PUSH_APNS_KEY_ID'),
    |       'key' => [
    |           'path' => env('MOBILE_PUSH_APNS_KEY_PATH'),          // e.g. storage_path('push/AuthKey.p8')
    |           'base64' => env('MOBILE_PUSH_APNS_KEY_BASE64'),      // or the base64 of the .p8 file
    |       ],
    |       'bundle_id' => env('MOBILE_PUSH_APNS_BUNDLE_ID'),
    |       'environment' => env('MOBILE_PUSH_APNS_ENVIRONMENT', 'production'),
    |       'live' => [
    |           'allow' => (bool) env('MOBILE_PUSH_APNS_LIVE_ALLOW', false),
    |           'only_to' => array_filter(explode(',', (string) env('MOBILE_PUSH_APNS_LIVE_ONLY_TO', ''))),
    |       ],
    |   ],
    |
    |   'android' => [
    |       'driver' => 'fcm',
    |       'auth' => 'service_account',
    |       'credentials' => [
    |           'path' => env('MOBILE_PUSH_FCM_CREDENTIALS_PATH'),   // e.g. storage_path('push/firebase.json')
    |           'base64' => env('MOBILE_PUSH_FCM_CREDENTIALS_BASE64'),
    |       ],
    |       'project_id' => env('MOBILE_PUSH_FCM_PROJECT_ID'),       // null = from the JSON
    |       'live' => [
    |           'allow' => (bool) env('MOBILE_PUSH_FCM_LIVE_ALLOW', false),
    |           'only_to' => array_filter(explode(',', (string) env('MOBILE_PUSH_FCM_LIVE_ONLY_TO', ''))),
    |       ],
    |   ],
    */
    'connections' => [
        // 'ios' => [...],
        // 'android' => [...],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | Connections used when the caller does not name one: MobilePush::to($user) sends to the user's devices
    | registered on these connections, and MobilePush::toToken(Platform::Ios, ...) picks the only route of
    | that provider (two routes of the same provider = name the connection). null = every configured
    | connection. Example: MOBILE_PUSH_ROUTES=ios,android                       env MOBILE_PUSH_ROUTES
    */
    'routes' => env('MOBILE_PUSH_ROUTES') === null
        ? null
        : array_values(array_filter(array_map('trim', explode(',', (string) env('MOBILE_PUSH_ROUTES'))))),

    /*
    |--------------------------------------------------------------------------
    | Execution mode
    |--------------------------------------------------------------------------
    | auto     (default) live only when APP_ENV=production, otherwise simulate
    | live     send for real; outside production a connection also needs live.allow and the test device
    |          in live.only_to, every other destination is Skipped
    | simulate never touch the network: every result is "simulated"            env MOBILE_PUSH_MODE
    */
    'mode' => env('MOBILE_PUSH_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Transport defaults
    |--------------------------------------------------------------------------
    | Used by every connection that does not set its own value.
    */
    'transport' => [
        'window' => (int) env('MOBILE_PUSH_WINDOW', 100),                    // requests multiplexed per window (1..10000)
        'max_connections' => (int) env('MOBILE_PUSH_MAX_CONNECTIONS', 1),    // HTTP/2 connections per pool
        'connect_timeout_ms' => (int) env('MOBILE_PUSH_CONNECT_TIMEOUT_MS', 5000),
        'timeout_ms' => (int) env('MOBILE_PUSH_TIMEOUT_MS', 30000),          // must not be below connect_timeout_ms
        'max_idle_seconds' => (int) env('MOBILE_PUSH_MAX_IDLE_SECONDS', 3600),
        'proxy' => env('MOBILE_PUSH_PROXY'),                                 // e.g. http://proxy.internal:3128
        'ca_bundle' => env('MOBILE_PUSH_CA_BUNDLE'),                         // path of a CA bundle; null = system
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry budget
    |--------------------------------------------------------------------------
    | One budget per destination, shared by every component that may retry.
    */
    'retry' => [
        'max_attempts' => (int) env('MOBILE_PUSH_RETRY_MAX_ATTEMPTS', 5),            // first attempt included, >= 1
        'max_elapsed' => (int) env('MOBILE_PUSH_RETRY_MAX_ELAPSED', 3600),           // seconds from the first attempt
        'backoff_base_ms' => (int) env('MOBILE_PUSH_RETRY_BACKOFF_BASE_MS', 1000),   // doubled at every retry
        'backoff_max_ms' => (int) env('MOBILE_PUSH_RETRY_BACKOFF_MAX_MS', 300000),
        'jitter' => (float) env('MOBILE_PUSH_RETRY_JITTER', 0.2),                    // 0..1
        // Longest wait done in-process before a retry; anything longer becomes "deferred". 0 = never wait.
        'inline_threshold_ms' => (int) env('MOBILE_PUSH_RETRY_INLINE_THRESHOLD_MS', 0),
    ],

    /*
    | When the provider may have accepted a push but the answer was lost:
    | never | replaceable (default: only pushes whose later copy replaces the earlier one) | always
    |                                                                     env MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY
    */
    'unknown_outcome' => [
        'retry' => env('MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY', 'replaceable'),
    ],

    /*
    | Minimum delays the providers ask for, in seconds.
    */
    'apns' => [
        'server_error_retry_delay' => (int) env('MOBILE_PUSH_APNS_SERVER_ERROR_RETRY_DELAY', 900),  // Apple: 15 minutes after a 5xx
        'device_rate_limit_delay' => (int) env('MOBILE_PUSH_APNS_DEVICE_RATE_LIMIT_DELAY', 60),    // after TooManyRequests
    ],

    'fcm' => [
        'throttled_min_delay' => (int) env('MOBILE_PUSH_FCM_THROTTLED_MIN_DELAY', 60),             // QUOTA_EXCEEDED / UNAVAILABLE
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards: rate limits and circuit breakers
    |--------------------------------------------------------------------------
    | Rates count messages per minute per provider ACCOUNT (Firebase project, Apple team), never per
    | connection alias. They are application ceilings, not the provider's quota. null = unlimited.
    */
    'guards' => [
        'rate' => [
            'fcm' => env('MOBILE_PUSH_RATE_FCM', 500000),
            'apns' => env('MOBILE_PUSH_RATE_APNS'),
            'reserved_share' => (float) env('MOBILE_PUSH_RATE_RESERVED_SHARE', 0.2),   // kept for transactional traffic
            'burst' => env('MOBILE_PUSH_RATE_BURST'),                                  // null = one minute of traffic
        ],
        'auth' => [
            'threshold' => (int) env('MOBILE_PUSH_AUTH_BREAKER_THRESHOLD', 5),        // consecutive credential failures
            'cooldown' => (int) env('MOBILE_PUSH_AUTH_BREAKER_COOLDOWN', 60),         // seconds
        ],
        'destination_quality' => [
            'min_sample' => (int) env('MOBILE_PUSH_QUALITY_MIN_SAMPLE', 200),
            'threshold' => (float) env('MOBILE_PUSH_QUALITY_THRESHOLD', 0.5),         // share of bad destinations, 0..1 exclusive
            'cooldown' => (int) env('MOBILE_PUSH_QUALITY_COOLDOWN', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery profile and events
    |--------------------------------------------------------------------------
    | delivery.profile: light (default, best effort, no tables) | durable (ledger + outbox). Only queue() uses the
    |   ledger: send() is the synchronous path in both profiles and writes no ledger row.
    |                                                                     env MOBILE_PUSH_DELIVERY_PROFILE
    | events.per_result: when to dispatch PushResultProduced for every destination:
    |   auto (default: transactional sends only) | always | never         env MOBILE_PUSH_EVENTS_PER_RESULT
    |   The Pulse card counts reasons from these events: set "always" to see the reason codes of bulk traffic too.
    | connection_cache.size: resolved connections (drivers, pools) kept per process; a long-lived worker
    |   serving many tenants never keeps more than this many.              env MOBILE_PUSH_CONNECTION_CACHE_SIZE
    */
    'delivery' => [
        'profile' => env('MOBILE_PUSH_DELIVERY_PROFILE', 'light'),
    ],

    'events' => [
        'per_result' => env('MOBILE_PUSH_EVENTS_PER_RESULT', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    | Default: the migrations of the modules you use are PUBLISHED (`php artisan mobile-push:install` copies them into
    | database/migrations) and the modules do not register the package files with `migrate`.
    | migrations.autoload = true: the enabled modules register their package files with `migrate` instead and nothing is
    |   published (install says so). A copy already published wins, so a table is never created twice. Every migration
    |   is also idempotent.                                              env MOBILE_PUSH_MIGRATIONS_AUTOLOAD
    */
    'migrations' => [
        'autoload' => (bool) env('MOBILE_PUSH_MIGRATIONS_AUTOLOAD', false),
    ],

    'connection_cache' => [
        'size' => (int) env('MOBILE_PUSH_CONNECTION_CACHE_SIZE', 64),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared cache
    |--------------------------------------------------------------------------
    | Provider tokens, breakers, rate limits and throttle gates are shared between workers through this
    | cache store. Cross-process guarantees need a lock-capable shared store: redis, memcached, database
    | or dynamodb. "file" is atomic on one host only; "array" inside one process only.
    */
    'cache' => [
        'store' => env('MOBILE_PUSH_CACHE_STORE'),
        'prefix' => env('MOBILE_PUSH_CACHE_PREFIX', 'mobile-push'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy keys
    |--------------------------------------------------------------------------
    | Two independent key rings: one for the HMAC lookup index of device tokens, one for encryption at
    | rest. Each is a map "version => base64 key" (at least 32 bytes once decoded). When a map is empty,
    | version 1 is derived from APP_KEY with a purpose-specific HKDF label. To rotate, add a new version
    | and keep the old ones until every row has been re-indexed / re-encrypted.
    */
    'privacy' => [
        'index_keys' => $versionedKey('MOBILE_PUSH_INDEX_KEY'),
        'index_key_current' => (int) env('MOBILE_PUSH_INDEX_KEY_VERSION', 0),
        'encryption_keys' => $versionedKey('MOBILE_PUSH_ENCRYPTION_KEY'),
        'encryption_key_current' => (int) env('MOBILE_PUSH_ENCRYPTION_KEY_VERSION', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Device registry (optional module)
    |--------------------------------------------------------------------------
    | Stores installations so you can write MobilePush::to($user). Tokens are encrypted at rest and looked
    | up through a keyed HMAC index. The environment of a device is the one the app declares at
    | registration; the package never guesses it.
    */
    'devices' => [
        'enabled' => (bool) env('MOBILE_PUSH_DEVICES_ENABLED', false),
        'table' => env('MOBILE_PUSH_DEVICES_TABLE', 'mobile_push_devices'),
        'connection' => env('MOBILE_PUSH_DEVICES_CONNECTION'),
        'model' => PushDevice::class,
        'probe' => [                                                                   // mobile-push:devices:probe-environment
            'cooldown_hours' => (int) env('MOBILE_PUSH_DEVICES_PROBE_COOLDOWN_HOURS', 24), // a device is probed at most once per this many hours
        ],
        'prune' => [                                                                   // mobile-push:devices:prune
            'invalidated_days' => 30,                                                  // delete invalidated rows after this many days
            'inactive_days' => 365,                                                    // delete rows not seen for this many days
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Send log (optional)
    |--------------------------------------------------------------------------
    | Choose where each outcome is recorded: any combination of "log" (a Laravel log channel),
    | "database" (the mobile_push_logs table) and "otel" (OpenTelemetry spans and metrics, needs
    | open-telemetry/api). Example: MOBILE_PUSH_LOG_SINKS=log,database,otel. Empty = no send log.
    |
    | Nothing sensitive is ever written: a destination appears only as its keyed index and a masked form.
    | "payload" controls how much of the message is recorded: none (default), keys, or full (redacted).
    | A failing sink never affects a send.
    */
    'log' => [
        'sinks' => array_values(array_filter(array_map('trim', explode(',', (string) env('MOBILE_PUSH_LOG_SINKS', ''))))),
        'payload' => env('MOBILE_PUSH_LOG_PAYLOAD', 'none'),
        'channel' => env('MOBILE_PUSH_LOG_CHANNEL'),
        // Map of status => PSR-3 level, e.g. ['accepted' => 'info']. Defaults: accepted/simulated/validated
        // debug; deferred/skipped info; expired/rejected/failed/outcome_unknown warning.
        'levels' => [],
        'buffer' => [
            'size' => (int) env('MOBILE_PUSH_LOG_BUFFER_SIZE', 100),
            'max' => (int) env('MOBILE_PUSH_LOG_BUFFER_MAX', 10000),
        ],
        'database' => [
            'table' => env('MOBILE_PUSH_LOG_TABLE', 'mobile_push_logs'),
            'connection' => env('MOBILE_PUSH_LOG_CONNECTION'),
            // all | failures (only non-routine outcomes) | sampled (failures plus a share of successes)
            'mode' => env('MOBILE_PUSH_LOG_DB_MODE', 'failures'),
            'sample_rate' => (float) env('MOBILE_PUSH_LOG_DB_SAMPLE_RATE', 0.01),
            'retention_days' => (int) env('MOBILE_PUSH_LOG_RETENTION_DAYS', 30),
            'insert_chunk' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Durable ledger (delivery.profile = durable)
    |--------------------------------------------------------------------------
    | One row per logical effect and one per destination; queue() records the intent (inside your transaction
    | when it runs on this connection) and a relay sends it. lease_seconds must exceed queue.chunk_deadline.
    */
    'ledger' => [
        'connection' => env('MOBILE_PUSH_LEDGER_CONNECTION'),                          // null = default database connection
        'tables' => [
            'effects' => env('MOBILE_PUSH_LEDGER_EFFECTS_TABLE', 'mobile_push_effects'),
            'deliveries' => env('MOBILE_PUSH_LEDGER_DELIVERIES_TABLE', 'mobile_push_deliveries'),
        ],
        'lease_seconds' => (int) env('MOBILE_PUSH_LEDGER_LEASE_SECONDS', 120),
        'dedupe_seconds' => (int) env('MOBILE_PUSH_LEDGER_DEDUPE_SECONDS', 86400),     // same effect key within this window = same effect
        'chunk_size' => (int) env('MOBILE_PUSH_LEDGER_CHUNK_SIZE', 500),               // 1..5000 rows per insert/claim
        'claim_strategy' => env('MOBILE_PUSH_LEDGER_CLAIM_STRATEGY', 'auto'),          // auto | skip_locked | optimistic
        'retention_days' => (int) env('MOBILE_PUSH_LEDGER_RETENTION_DAYS', 30),        // mobile-push:deliveries:prune keeps finished rows this long
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | The QUEUE connection and names used by queue() (the push connection is chosen separately). The time budget
    | chain checked by `mobile-push:doctor`: transport timeout < chunk_deadline < timeout < worker timeout <
    | retry_after (SQS: visibility timeout). Declare worker_timeout / visibility_timeout when they cannot be read.
    | The defaults fit a stock Laravel app (retry_after 90): transport 30 s < chunk_deadline 40 s < timeout 50 s <
    | worker 55 s (the value `mobile-push:install` prints) < retry_after 90 s, each link with the margin the doctor
    | requires. Raise the whole chain together, never one value alone.
    */
    'queue' => [
        'connection' => env('MOBILE_PUSH_QUEUE_CONNECTION'),                           // null = the app's default queue connection
        'queue' => env('MOBILE_PUSH_QUEUE', 'push'),
        'bulk_queue' => env('MOBILE_PUSH_BULK_QUEUE', 'push-bulk'),                   // bulk() traffic
        'chunk' => (int) env('MOBILE_PUSH_QUEUE_CHUNK', 500),                          // destinations per job, 1..10000
        'chunk_bytes' => (int) env('MOBILE_PUSH_QUEUE_CHUNK_BYTES', 98304),            // >= 4096, before encryption
        'timeout' => (int) env('MOBILE_PUSH_QUEUE_TIMEOUT', 50),                       // job timeout, seconds
        'chunk_deadline' => (int) env('MOBILE_PUSH_QUEUE_CHUNK_DEADLINE', 40),         // wall time of one relay run, seconds
        'after_commit' => (bool) env('MOBILE_PUSH_QUEUE_AFTER_COMMIT', true),
        'follow_up_bucket' => (int) env('MOBILE_PUSH_QUEUE_FOLLOW_UP_BUCKET', 60),     // seconds; deferred retries this close share a job
        'worker_timeout' => env('MOBILE_PUSH_QUEUE_WORKER_TIMEOUT'),                   // seconds, when not on Horizon
        'visibility_timeout' => env('MOBILE_PUSH_QUEUE_VISIBILITY_TIMEOUT'),           // seconds, SQS
        'sweeper' => [
            'schedule' => (bool) env('MOBILE_PUSH_QUEUE_SWEEPER', true),               // durable profile: sweep every minute
            'scopes_per_run' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Client receipts (optional, durable profile only)
    |--------------------------------------------------------------------------
    | The app reports "received" / "opened" to POST {route.prefix}/receipts with the opaque marker mp_r found in
    | the payload. The marker is an HMAC with a server-side key; it is never logged. No receipt exists for topics
    | and broadcasts. Client contract: src/Laravel/Receipts/CLIENT_CONTRACT.md.
    */
    'receipts' => [
        'enabled' => (bool) env('MOBILE_PUSH_RECEIPTS_ENABLED', false),
        'marker_ttl' => (int) env('MOBILE_PUSH_RECEIPTS_TTL', 604800),                 // seconds a marker is accepted, >= 60
        'max_batch' => 20,                                                             // reports per request
        'route' => [
            'prefix' => env('MOBILE_PUSH_RECEIPTS_PREFIX', 'mobile-push'),
            'domain' => null,
            'middleware' => ['throttle:mobile-push-receipts'],                       // no auth by default: the marker is the proof
        ],
        'rate_limit' => [
            'per_ip' => 120,                                                           // requests per minute, 0 = no limit
            'per_marker' => 10,                                                        // reports per minute, 0 = no limit
        ],
        // Map version (1..255) => base64 key (>= 32 bytes). Empty = version 1 derived from APP_KEY.
        'keys' => $versionedKey('MOBILE_PUSH_RECEIPTS_KEY'),
        'key_current' => (int) env('MOBILE_PUSH_RECEIPTS_KEY_VERSION', 0),             // 0 = highest version
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics and health
    |--------------------------------------------------------------------------
    | Counters live in the package cache (cache.store) with bounded labels only: connection, provider,
    | environment, status, reason code, traffic class; never a user, device or campaign id. The Prometheus
    | endpoint is off by default and, when on, refuses every request until a token is configured.
    */
    'metrics' => [
        'enabled' => (bool) env('MOBILE_PUSH_METRICS_ENABLED', true),
        'max_series' => 1000,                                                          // >= 10
        'prometheus' => [
            'enabled' => (bool) env('MOBILE_PUSH_PROMETHEUS_ENABLED', false),
            'path' => 'mobile-push/metrics',
            'middleware' => [RequireMetricsToken::class],                              // [] makes the endpoint public
            'token' => env('MOBILE_PUSH_METRICS_TOKEN'),                               // sent as a bearer token
        ],
    ],

    'health' => [
        'ledger_backlog_seconds' => 300,                                               // oldest due delivery older than this: warning
        'ledger_backlog_failed_seconds' => 3600,                                       // ... older than this: failed
        'spatie' => [
            'register' => true,                                                        // add the check to spatie/laravel-health when installed
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational alerts (optional)
    |--------------------------------------------------------------------------
    | Mail and/or Slack (incoming webhook) when a circuit opens, a credential fails over, expires or is about to,
    | the error rate of a connection is too high, the ledger backlog grows or a log sink keeps failing. Each alert
    | is sent at most once per cooldown. Evaluated every minute by the scheduler when enabled.
    */
    'alerts' => [
        'enabled' => (bool) env('MOBILE_PUSH_ALERTS_ENABLED', false),
        'mail' => array_values(array_filter(array_map('trim', explode(',', (string) env('MOBILE_PUSH_ALERT_MAIL', ''))))),
        'slack_webhook' => env('MOBILE_PUSH_ALERT_SLACK'),                             // https://hooks.slack.com/...
        'cooldown_seconds' => 3600,
        'send_in_simulate' => false,                                                   // in simulate mode alerts are only logged
        'error_rate' => [
            'threshold' => 0.5,                                                        // share of errors, 0..1
            'min_sample' => 50,
            'window_seconds' => 300,                                                   // >= 60
        ],
    ],

    'credentials' => [
        'warn_days' => 30,                                                             // warn this many days before a certificate expires
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Activities (optional)
    |--------------------------------------------------------------------------
    | Activity registry, push-to-start and update tokens, broadcast channels. "binding" is the APNs connection used
    | by default. "types" maps a key to a class implementing Core\Apns\LiveActivity\LiveActivityType.
    */
    'live_activities' => [
        'enabled' => (bool) env('MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED', false),
        'binding' => env('MOBILE_PUSH_LA_BINDING', 'apns'),
        'connection' => env('MOBILE_PUSH_LA_DB_CONNECTION'),                           // database connection of the tables
        'table' => 'mobile_push_live_activities',
        'push_to_start_table' => 'mobile_push_live_activity_push_to_start_tokens',
        'channels_table' => 'mobile_push_broadcast_channels',
        'models' => [
            'activity' => PushActivity::class,
            'push_to_start_token' => PushToStartToken::class,
            'broadcast_channel' => BroadcastChannel::class,
        ],
        'types' => [
            // 'delivery' => App\LiveActivities\DeliveryActivity::class,
        ],
        'default_priority' => env('MOBILE_PUSH_LA_PRIORITY', 'high'),                  // high | normal
        'high_priority' => [
            'max_per_hour' => (int) env('MOBILE_PUSH_LA_HIGH_PRIORITY_PER_HOUR', 20),  // above it updates go at priority 5
        ],
        'local_expiry' => [
            'hours' => 8,                                                              // iOS ends an activity after 8 hours
            'from' => 'start',                                                         // start | last_update
        ],
        'max_update_retries' => 3,
        'prune' => [
            'closed_after_days' => 7,
        ],
        'channels' => [
            'max_per_environment' => (int) env('MOBILE_PUSH_LA_CHANNEL_CAP', 9500),    // local cap, Apple allows 10,000
            'cleanup_after_hours' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frequency cap and server-side badge (optional)
    |--------------------------------------------------------------------------
    | The cap is decided once per logical effect and per person (all devices of a user count once), never per
    | device or retry. A message picks its bucket with ->policy('marketing'); a message without a policy uses
    | no_policy_bucket (null = not capped). Never applied to topics, broadcasts or Live Activity transitions, and
    | bypassFrequencyCap() never bypasses consent. Off by default: enable it and adapt the table below.
    |   limits: bucket => ['per_hour' => n, 'per_day' => n] (either or both), or 'unlimited'
    |   on_store_failure: allow (send when the counters cannot be read) | deny
    | The table and on_store_failure are validated only while the cap is enabled (a ConfigurationException at
    | the first send then names the wrong key).
    |
    | The badge counter acts only for messages that ask for ->incrementBadge(): every device of the owner gets the
    | same number once per effect. Reset it when the app opens: MobilePush::resetBadge($user).
    */
    'frequency' => [
        'enabled' => (bool) env('MOBILE_PUSH_FREQUENCY_ENABLED', false),
        'limits' => [
            'default' => ['per_hour' => 5, 'per_day' => 20],
            'marketing' => ['per_day' => 2],
            'security' => 'unlimited',
        ],
        'no_policy_bucket' => env('MOBILE_PUSH_FREQUENCY_NO_POLICY_BUCKET', 'default'),
        'on_store_failure' => env('MOBILE_PUSH_FREQUENCY_ON_STORE_FAILURE', 'allow'),
    ],

    'badge' => [
        'enabled' => (bool) env('MOBILE_PUSH_BADGE_ENABLED', true),
        'ttl_days' => (int) env('MOBILE_PUSH_BADGE_TTL_DAYS', 90),                     // counter forgotten after this idle time
        'per_binding' => (bool) env('MOBILE_PUSH_BADGE_PER_BINDING', false),           // one counter per connection instead of per owner
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback to another notification channel
    |--------------------------------------------------------------------------
    | Used only by notifications that opt in (pushFallback()): when every push of the notification ended without
    | being accepted (never after an unknown outcome or an opt-out), the fallback channel is sent once.
    */
    'fallback' => [
        'enabled' => (bool) env('MOBILE_PUSH_FALLBACK_ENABLED', true),
        'when_no_destination' => (bool) env('MOBILE_PUSH_FALLBACK_WHEN_NO_DESTINATION', true),
        'idempotency_ttl' => (int) env('MOBILE_PUSH_FALLBACK_IDEMPOTENCY_TTL', 86400),  // seconds "this fallback was sent" is remembered
        'cache_store' => env('MOBILE_PUSH_FALLBACK_CACHE_STORE'),                      // null = cache.store
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaigns (optional, durable profile only)
    |--------------------------------------------------------------------------
    */
    'campaigns' => [
        'enabled' => (bool) env('MOBILE_PUSH_CAMPAIGNS_ENABLED', false),
        'table' => 'mobile_push_campaigns',
        'connection' => null,                                                          // null = default database connection
        'slice' => 1000,                                                               // destinations per planner job, 1..5000
        'default_rate_per_minute' => null,                                             // null = as fast as the planner can write
        'retention_days' => 30,
        'sweeper' => [
            'schedule' => true,
        ],
        'audiences' => [
            // 'newsletter' => App\Push\NewsletterAudience::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Pulse and Telescope
    |--------------------------------------------------------------------------
    */
    'pulse' => [
        'enabled' => (bool) env('MOBILE_PUSH_PULSE_ENABLED', false),
        'sample_rate' => (float) env('MOBILE_PUSH_PULSE_SAMPLE_RATE', 1.0),            // 0..1
        'max_connections' => 50,                                                       // distinct connection names in Pulse keys
    ],

    /*
    | Test fakes (MobilePush::fake(), fakeTransport()) refuse to start outside APP_ENV=testing unless allowed here.
    */
    'testing' => [
        'allow_fakes' => false,
    ],

    'telescope' => [
        'enabled' => env('MOBILE_PUSH_TELESCOPE_ENABLED', 'auto'),                     // auto (when Telescope is enabled) | true | false
    ],

];
