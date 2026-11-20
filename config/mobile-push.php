<?php

declare(strict_types=1);
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/*
 * Laravel Mobile Push configuration.
 *
 * This file grows milestone by milestone. docs/configuration.md is GENERATED from the config schema:
 * never edit that document by hand.
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
    | delivery.profile: light (default, best effort, no tables) | durable (ledger; queued path)
    |                                                                     env MOBILE_PUSH_DELIVERY_PROFILE
    | events.per_result: when to dispatch PushResultProduced for every destination:
    |   auto (default: transactional sends only) | always | never         env MOBILE_PUSH_EVENTS_PER_RESULT
    | connection_cache.size: resolved connections (drivers, pools) kept per process; a long-lived worker
    |   serving many tenants never keeps more than this many.              env MOBILE_PUSH_CONNECTION_CACHE_SIZE
    */
    'delivery' => [
        'profile' => env('MOBILE_PUSH_DELIVERY_PROFILE', 'light'),
    ],

    'events' => [
        'per_result' => env('MOBILE_PUSH_EVENTS_PER_RESULT', 'auto'),
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

];
