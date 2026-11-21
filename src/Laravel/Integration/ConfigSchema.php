<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Health\RequireMetricsToken;
use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushToStartToken;

/**
 * The single, machine-readable description of every key of `config/mobile-push.php`: dotted key, environment
 * variable, default, type, allowed values, one-line description and owning module.
 *
 * docs/configuration.md is rendered from it (ConfigReference). The test suite fails when a key the package reads is
 * missing here, when a default here differs from the shipped config file, or when the generated document is stale.
 */
final class ConfigSchema
{
    /** @var list<array{id: string, title: string, intro: string, keys: list<ConfigKey>}>|null */
    private static ?array $sections = null;

    /**
     * Sections in document order.
     *
     * @return list<array{id: string, title: string, intro: string, keys: list<ConfigKey>}>
     */
    public static function sections(): array
    {
        return self::$sections ??= self::build();
    }

    /** @return list<ConfigKey> */
    public static function keys(): array
    {
        $out = [];
        foreach (self::sections() as $section) {
            foreach ($section['keys'] as $key) {
                $out[] = $key;
            }
        }

        return $out;
    }

    public static function find(string $key): ?ConfigKey
    {
        foreach (self::keys() as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Whether a dotted key (relative to `mobile-push`) is documented: it is a schema key, a section on the way to
     * one (`log`, `connections.ios`), or lies inside a map/list key (`log.levels.accepted`). `*` in the schema
     * matches any one segment.
     */
    public static function covers(string $dotted): bool
    {
        $wanted = explode('.', trim($dotted, '.'));
        foreach (self::keys() as $entry) {
            $segments = $entry->segments();
            $matches = true;
            $n = min(count($wanted), count($segments));
            for ($i = 0; $i < $n; $i++) {
                if ($segments[$i] !== '*' && $segments[$i] !== $wanted[$i]) {
                    $matches = false;

                    break;
                }
            }
            if (! $matches) {
                continue;
            }
            if (count($wanted) <= count($segments) || ($entry->isContainer() && ! self::hasChildren($entry))) {
                return true;
            }
        }

        return false;
    }

    /** A map whose entries are documented one by one (connections.*) does not cover undocumented children. */
    private static function hasChildren(ConfigKey $parent): bool
    {
        foreach (self::keys() as $entry) {
            if (str_starts_with($entry->key, $parent->key.'.')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{id: string, title: string, intro: string, keys: list<ConfigKey>}> */
    private static function build(): array
    {
        return [
            self::section('general', 'General', 'Tenant scope, default connection, routing and execution mode.', [
                self::k('scope', 'string', 'default', 'MOBILE_PUSH_SCOPE', 'Tenant scope used when the application supplies none (MobilePush::scopeUsing() / ->scope()); never read from client payload.', 'sending', constraint: 'letters, digits, ".", "_", ":", "-"; max 64'),
                self::k('default', 'string|null', null, 'MOBILE_PUSH_CONNECTION', 'Connection used by MobilePush::connection() without a name; null = route by provider (see routes).', 'sending'),
                self::k('routes', 'list<string>|null', null, 'MOBILE_PUSH_ROUTES', 'Connections used when none is named (comma-separated in the env var); null = every configured connection.', 'sending'),
                self::k('mode', 'string', 'auto', 'MOBILE_PUSH_MODE', 'Execution mode: auto = live only when APP_ENV=production, otherwise simulate (no network).', 'sending', ['auto', 'live', 'simulate']),
            ]),
            self::section('connections', 'Connections', 'One entry per app and provider under `connections.<name>`. Secrets are SOURCES with exactly one of `path`, `inline`, `base64` or `resolver` + `reference`; never put a closure here (config:cache). Each connection is validated at first use: a wrong key, an expired certificate or a secondary credential of another team/project is a ConfigurationException before any request.', [
                self::k('connections', 'map<string, array>', [], null, 'The connections, by name.', 'sending'),
                self::k('connections.*.driver', 'string', null, null, 'Provider of the connection (required).', 'sending', ['apns', 'fcm']),
                self::k('connections.*.auth', 'string', null, null, 'APNs: token (a .p8 key, default) or certificate (mTLS). FCM: service_account (default) or adc (Application Default Credentials, needs google/auth).', 'sending', ['token', 'certificate', 'service_account', 'adc']),
                self::k('connections.*.team_id', 'string', null, null, 'APNs: Apple team id (10 characters).', 'sending'),
                self::k('connections.*.key_id', 'string', null, null, 'APNs token auth: id of the .p8 key.', 'sending'),
                self::k('connections.*.key', 'secret source', null, null, 'APNs token auth: the .p8 key.', 'sending'),
                self::k('connections.*.signer', 'string|null', null, null, 'APNs token auth: container binding or class name of a Padosoft\\MobilePush\\Core\\Auth\\Signer (algorithm ES256, signature as raw 64-byte R||S, keyId()) that keeps the key in a KMS/HSM. When set, key is not required and never read, the key id comes from the signer (a key_id that differs is a ConfigurationException) and team_id is still required. Not available for FCM. The credential cannot be inspected: expiry not verifiable.', 'sending'),
                self::k('connections.*.certificate', 'secret source', null, null, 'APNs certificate auth: the PEM certificate (may also contain the key).', 'sending'),
                self::k('connections.*.certificate_key', 'secret source', null, null, 'APNs certificate auth: the private key when it is a separate file.', 'sending'),
                self::k('connections.*.passphrase', 'string|secret source', null, null, 'APNs certificate auth: the key passphrase.', 'sending'),
                self::k('connections.*.bundle_id', 'string', null, null, 'APNs: the app bundle id (the APNs topic).', 'sending'),
                self::k('connections.*.environment', 'string', 'production', null, 'APNs: environment of the app build; never guessed.', 'sending', ['production', 'sandbox']),
                self::k('connections.*.port', 'int', 443, null, 'APNs port.', 'sending', ['443', '2197']),
                self::k('connections.*.broadcast_host', 'string', 'standard', null, 'APNs: host used to publish Live Activity broadcasts.', 'sending', ['standard', 'api-broadcast']),
                self::k('connections.*.credentials', 'secret source', null, null, 'FCM service_account auth: the service-account JSON.', 'sending'),
                self::k('connections.*.project_id', 'string|null', null, null, 'FCM: Firebase project id; null = from the service-account JSON (required with adc).', 'sending'),
                self::k('connections.*.http_version', 'string', '2', null, 'FCM: HTTP version.', 'sending', ['2', '1.1']),
                self::k('connections.*.secondary', 'map', null, null, 'Optional rotation twin with the same keys as the primary credential; must belong to the same team/environment (APNs) or project (FCM).', 'sending'),
                self::k('connections.*.live.allow', 'bool', false, null, 'Allow live sends from this connection OUTSIDE production.', 'sending'),
                self::k('connections.*.live.only_to', 'list<string|array>', [], null, 'Raw TEST device tokens allowed to receive live sends outside production (or [kind, token] pairs); converted to keyed indexes at load, never stored raw.', 'sending'),
                self::k('connections.*.live.allow_topics', 'bool', false, null, 'Outside production, also allow FCM topics and conditions.', 'sending'),
                self::k('connections.*.live.allow_broadcast', 'bool', false, null, 'Outside production, also allow APNs broadcast channels.', 'sending'),
                self::k('connections.*.live.allow_channel_management', 'bool', false, null, 'Outside production, also allow creating/deleting broadcast channels.', 'sending'),
                self::k('connections.*.window', 'int', null, null, 'Per-connection override of transport.window.', 'sending', constraint: '1..10000'),
                self::k('connections.*.max_connections', 'int', null, null, 'Per-connection override of transport.max_connections.', 'sending', constraint: '>= 1'),
                self::k('connections.*.connect_timeout_ms', 'int', null, null, 'Per-connection override of transport.connect_timeout_ms.', 'sending', constraint: '>= 1'),
                self::k('connections.*.timeout_ms', 'int', null, null, 'Per-connection override of transport.timeout_ms (also read by the queue timeout chain).', 'sending', constraint: '>= 1'),
                self::k('connections.*.max_idle_seconds', 'int', null, null, 'Per-connection override of transport.max_idle_seconds.', 'sending', constraint: '>= 1'),
                self::k('connections.*.proxy', 'string|null', null, null, 'Per-connection override of transport.proxy.', 'sending'),
                self::k('connections.*.ca_bundle', 'string|null', null, null, 'Per-connection override of transport.ca_bundle.', 'sending'),
            ]),
            self::section('transport', 'Transport', 'HTTP/2 transport defaults for every connection that does not set its own value.', [
                self::k('transport.window', 'int', 100, 'MOBILE_PUSH_WINDOW', 'Requests multiplexed per window.', 'sending', constraint: '1..10000'),
                self::k('transport.max_connections', 'int', 1, 'MOBILE_PUSH_MAX_CONNECTIONS', 'HTTP/2 connections per pool.', 'sending', constraint: '>= 1'),
                self::k('transport.connect_timeout_ms', 'int', 5000, 'MOBILE_PUSH_CONNECT_TIMEOUT_MS', 'Connect timeout, milliseconds.', 'sending', constraint: '>= 1'),
                self::k('transport.timeout_ms', 'int', 30000, 'MOBILE_PUSH_TIMEOUT_MS', 'Request timeout, milliseconds (first link of the queue timeout chain).', 'sending', constraint: '>= connect_timeout_ms'),
                self::k('transport.max_idle_seconds', 'int', 3600, 'MOBILE_PUSH_MAX_IDLE_SECONDS', 'An idle pooled connection is closed after this many seconds.', 'sending', constraint: '>= 1'),
                self::k('transport.proxy', 'string|null', null, 'MOBILE_PUSH_PROXY', 'HTTP proxy URL, e.g. http://proxy.internal:3128.', 'sending'),
                self::k('transport.max_pools', 'int', 32, 'MOBILE_PUSH_TRANSPORT_MAX_POOLS', 'Cap of the per-process registry of HTTP/2 connection pools (one pool per provider endpoint, credential and settings). When a new pool would exceed it, the least recently used idle pool is closed. Raise it when one process serves many distinct connections (multi-tenant).', 'sending', constraint: '>= 1'),
                self::k('transport.ca_bundle', 'string|null', null, 'MOBILE_PUSH_CA_BUNDLE', 'Path of a CA bundle; null = the system store.', 'sending'),
            ]),
            self::section('retry', 'Retry budget', 'One budget per destination, shared by every component that may retry.', [
                self::k('retry.max_attempts', 'int', 5, 'MOBILE_PUSH_RETRY_MAX_ATTEMPTS', 'Attempts per destination, the first included.', 'sending', constraint: '>= 1'),
                self::k('retry.max_elapsed', 'int', 3600, 'MOBILE_PUSH_RETRY_MAX_ELAPSED', 'Seconds from the first attempt after which nothing is retried.', 'sending', constraint: '>= 0'),
                self::k('retry.backoff_base_ms', 'int', 1000, 'MOBILE_PUSH_RETRY_BACKOFF_BASE_MS', 'First backoff, doubled at every retry.', 'sending', constraint: '>= 0'),
                self::k('retry.backoff_max_ms', 'int', 300000, 'MOBILE_PUSH_RETRY_BACKOFF_MAX_MS', 'Longest backoff.', 'sending', constraint: '>= 0'),
                self::k('retry.jitter', 'float', 0.2, 'MOBILE_PUSH_RETRY_JITTER', 'Random share added to a backoff (never earlier than a provider minimum).', 'sending', constraint: '0..1'),
                self::k('retry.inline_threshold_ms', 'int', 0, 'MOBILE_PUSH_RETRY_INLINE_THRESHOLD_MS', 'Longest wait done in-process; anything longer becomes deferred. 0 = never wait.', 'sending', constraint: '>= 0'),
                self::k('unknown_outcome.retry', 'string', 'replaceable', 'MOBILE_PUSH_UNKNOWN_OUTCOME_RETRY', 'Retry after an unknown outcome (answer lost): never, replaceable pushes only, or always.', 'sending', ['never', 'replaceable', 'always']),
                self::k('apns.server_error_retry_delay', 'int', 900, 'MOBILE_PUSH_APNS_SERVER_ERROR_RETRY_DELAY', 'Seconds before retrying an APNs 5xx (Apple: 15 minutes).', 'sending', constraint: '>= 0'),
                self::k('apns.device_rate_limit_delay', 'int', 60, 'MOBILE_PUSH_APNS_DEVICE_RATE_LIMIT_DELAY', 'Seconds before retrying after APNs TooManyRequests.', 'sending', constraint: '>= 0'),
                self::k('fcm.throttled_min_delay', 'int', 60, 'MOBILE_PUSH_FCM_THROTTLED_MIN_DELAY', 'Minimum seconds before retrying FCM QUOTA_EXCEEDED / UNAVAILABLE (Retry-After wins when longer).', 'sending', constraint: '>= 0'),
            ]),
            self::section('guards', 'Guards: rate limits and circuit breakers', 'Rates count messages per minute per provider ACCOUNT (Firebase project, Apple team), never per connection alias: two aliases of one Firebase project share one budget. They are application ceilings, not the provider quota.', [
                self::k('guards.rate.fcm', 'int|null', 500000, 'MOBILE_PUSH_RATE_FCM', 'Messages per minute per Firebase project; null = unlimited.', 'sending', constraint: '>= 1'),
                self::k('guards.rate.apns', 'int|null', null, 'MOBILE_PUSH_RATE_APNS', 'Messages per minute per Apple team; null = unlimited.', 'sending', constraint: '>= 1'),
                self::k('guards.rate.reserved_share', 'float', 0.2, 'MOBILE_PUSH_RATE_RESERVED_SHARE', 'Share of the rate reserved to transactional traffic (bulk never uses it).', 'sending', constraint: '0..1'),
                self::k('guards.rate.burst', 'int|null', null, 'MOBILE_PUSH_RATE_BURST', 'Bucket capacity; null = one minute of traffic.', 'sending', constraint: '>= 1'),
                self::k('guards.auth.threshold', 'int', 5, 'MOBILE_PUSH_AUTH_BREAKER_THRESHOLD', 'Consecutive credential failures that open the authentication breaker.', 'sending', constraint: '>= 1'),
                self::k('guards.auth.cooldown', 'int', 60, 'MOBILE_PUSH_AUTH_BREAKER_COOLDOWN', 'Seconds before a probe of an open authentication breaker.', 'sending', constraint: '>= 1'),
                self::k('guards.destination_quality.min_sample', 'int', 200, 'MOBILE_PUSH_QUALITY_MIN_SAMPLE', 'Minimum results before the destination-quality breaker can open.', 'sending', constraint: '>= 1'),
                self::k('guards.destination_quality.threshold', 'float', 0.5, 'MOBILE_PUSH_QUALITY_THRESHOLD', 'Share of bad destinations that opens the destination-quality breaker.', 'sending', constraint: '0.01..0.99'),
                self::k('guards.destination_quality.cooldown', 'int', 300, 'MOBILE_PUSH_QUALITY_COOLDOWN', 'Seconds before a probe of an open destination-quality breaker.', 'sending', constraint: '>= 1'),
            ]),
            self::section('delivery', 'Delivery profile and events', 'The light profile is best effort and keeps no tables; the durable profile records every queued effect in the ledger first. Only queue() uses the ledger: send() is synchronous in both profiles.', [
                self::k('delivery.profile', 'string', 'light', 'MOBILE_PUSH_DELIVERY_PROFILE', 'Delivery profile of queue().', 'sending', ['light', 'durable']),
                self::k('events.per_result', 'string', 'auto', 'MOBILE_PUSH_EVENTS_PER_RESULT', 'When to dispatch PushResultProduced per destination: auto = transactional sends only. Pulse needs always to see the reason codes of bulk traffic.', 'sending', ['auto', 'always', 'never']),
                self::k('connection_cache.size', 'int', 64, 'MOBILE_PUSH_CONNECTION_CACHE_SIZE', 'Resolved connections (drivers, pools) kept per process.', 'sending', constraint: '1..100000'),
            ]),
            self::section('migrations', 'Migrations', 'Where the tables of the modules come from. By default the migrations are published into your application (`mobile-push:install` does it) and the modules never register their own copies with `migrate`.', [
                self::k('migrations.autoload', 'bool', false, 'MOBILE_PUSH_MIGRATIONS_AUTOLOAD', 'false (default): module migrations are published by mobile-push:install and `migrate` runs the copies. true: enabled modules register their package files with `migrate` and nothing is published (a copy already published wins).', 'install'),
            ]),
            self::section('cache', 'Shared cache', 'Provider tokens, breakers, rate limits, throttle gates, metrics, frequency counters and badges are shared between workers through this store. Cross-process guarantees need a lock-capable shared store (redis, memcached, database, dynamodb).', [
                self::k('cache.store', 'string|null', null, 'MOBILE_PUSH_CACHE_STORE', 'Cache store name from config/cache.php; null = the default store.', 'cache'),
                self::k('cache.prefix', 'string', 'mobile-push', 'MOBILE_PUSH_CACHE_PREFIX', 'Prefix of every cache key of the package.', 'cache'),
            ]),
            self::section('privacy', 'Privacy keys', 'Two independent key rings: the HMAC lookup index of device tokens and the encryption at rest (also keys the ledger payload fingerprint). Each is a map version => base64 key (>= 32 bytes). Empty = version 1 derived from APP_KEY with a purpose-specific HKDF label. To rotate, add a version and keep the old ones until every row is re-indexed / re-encrypted.', [
                self::k('privacy.index_keys', 'map<int, string>', [], 'MOBILE_PUSH_INDEX_KEY', 'Index key ring (the env var sets version 1).', 'privacy', defaultNote: 'derived from APP_KEY'),
                self::k('privacy.index_key_current', 'int', 0, 'MOBILE_PUSH_INDEX_KEY_VERSION', 'Version used for new writes; 0 = the highest.', 'privacy'),
                self::k('privacy.encryption_keys', 'map<int, string>', [], 'MOBILE_PUSH_ENCRYPTION_KEY', 'Encryption key ring (the env var sets version 1).', 'privacy', defaultNote: 'derived from APP_KEY'),
                self::k('privacy.encryption_key_current', 'int', 0, 'MOBILE_PUSH_ENCRYPTION_KEY_VERSION', 'Version used for new writes; 0 = the highest.', 'privacy'),
            ]),
            self::section('devices', 'Device registry', 'Installations stored per owner so MobilePush::to($user) works. Tokens are encrypted at rest and looked up through the keyed index; the environment is the one the app declares at registration.', [
                self::k('devices.enabled', 'bool', false, 'MOBILE_PUSH_DEVICES_ENABLED', 'Enable the registry (its migration is published by mobile-push:install, or auto-loaded with migrations.autoload).', 'devices'),
                self::k('devices.table', 'string', 'mobile_push_devices', 'MOBILE_PUSH_DEVICES_TABLE', 'Table name.', 'devices'),
                self::k('devices.connection', 'string|null', null, 'MOBILE_PUSH_DEVICES_CONNECTION', 'Database connection; null = default.', 'devices'),
                self::k('devices.model', 'class-string', PushDevice::class, null, 'Eloquent model (extend PushDevice to customise).', 'devices'),
                self::k('devices.probe.cooldown_hours', 'int', 24, 'MOBILE_PUSH_DEVICES_PROBE_COOLDOWN_HOURS', 'mobile-push:devices:probe-environment contacts a device at most once per this many hours (0 = no cooldown).', 'console', constraint: '>= 0'),
                self::k('devices.prune.invalidated_days', 'int', 30, null, 'mobile-push:devices:prune deletes invalidated rows older than this.', 'console', constraint: '>= 0'),
                self::k('devices.prune.inactive_days', 'int', 365, null, 'mobile-push:devices:prune deletes rows not seen for this many days.', 'console', constraint: '>= 0'),
            ]),
            self::section('log', 'Send log', 'Any combination of sinks. Nothing sensitive is written: a destination is only its keyed index and masked form; bearers and receipt markers are redacted. A failing sink never affects a send.', [
                self::k('log.sinks', 'list<string>', [], 'MOBILE_PUSH_LOG_SINKS', 'Sinks, comma-separated in the env var; empty = no send log.', 'logging', ['log', 'database', 'otel']),
                self::k('log.payload', 'string', 'none', 'MOBILE_PUSH_LOG_PAYLOAD', 'How much of the message is recorded (full is redacted).', 'logging', ['none', 'keys', 'full']),
                self::k('log.channel', 'string|null', null, 'MOBILE_PUSH_LOG_CHANNEL', 'Laravel log channel of the "log" sink; null = default.', 'logging'),
                self::k('log.levels', 'map<string, string>', [], null, 'Status => PSR-3 level overrides (defaults: accepted/simulated/validated debug; deferred/skipped info; the rest warning).', 'logging'),
                self::k('log.buffer.size', 'int', 100, 'MOBILE_PUSH_LOG_BUFFER_SIZE', 'Records buffered before a flush.', 'logging', constraint: '>= 0'),
                self::k('log.buffer.max', 'int', 10000, 'MOBILE_PUSH_LOG_BUFFER_MAX', 'Buffer ceiling; older records are dropped (counted) beyond it.', 'logging', constraint: '>= 1'),
                self::k('log.database.table', 'string', 'mobile_push_logs', 'MOBILE_PUSH_LOG_TABLE', 'Table of the database sink.', 'logging'),
                self::k('log.database.connection', 'string|null', null, 'MOBILE_PUSH_LOG_CONNECTION', 'Database connection of the database sink.', 'logging'),
                self::k('log.database.mode', 'string', 'failures', 'MOBILE_PUSH_LOG_DB_MODE', 'Which outcomes the database sink keeps.', 'logging', ['all', 'failures', 'sampled']),
                self::k('log.database.sample_rate', 'float', 0.01, 'MOBILE_PUSH_LOG_DB_SAMPLE_RATE', 'Share of routine successes kept in sampled mode.', 'logging', constraint: '0..1'),
                self::k('log.database.retention_days', 'int', 30, 'MOBILE_PUSH_LOG_RETENTION_DAYS', 'Rows older than this are pruned (mobile-push:logs:prune).', 'logging', constraint: '>= 1'),
                self::k('log.database.insert_chunk', 'int', 100, null, 'Rows per INSERT.', 'logging', constraint: '>= 1'),
            ]),
            self::section('ledger', 'Durable ledger', 'Used by queue() when delivery.profile = durable: one row per effect and per destination, written before dispatch (inside the caller transaction on this connection).', [
                self::k('ledger.connection', 'string|null', null, 'MOBILE_PUSH_LEDGER_CONNECTION', 'Database connection; null = default.', 'ledger'),
                self::k('ledger.tables.effects', 'string', 'mobile_push_effects', 'MOBILE_PUSH_LEDGER_EFFECTS_TABLE', 'Effects table.', 'ledger'),
                self::k('ledger.tables.deliveries', 'string', 'mobile_push_deliveries', 'MOBILE_PUSH_LEDGER_DELIVERIES_TABLE', 'Deliveries table.', 'ledger'),
                self::k('ledger.lease_seconds', 'int', 120, 'MOBILE_PUSH_LEDGER_LEASE_SECONDS', 'Lease of a claimed delivery; must exceed queue.chunk_deadline.', 'ledger', constraint: '>= 1'),
                self::k('ledger.dedupe_seconds', 'int', 86400, 'MOBILE_PUSH_LEDGER_DEDUPE_SECONDS', 'Window in which the same effect key is the same effect.', 'ledger', constraint: '>= 0'),
                self::k('ledger.chunk_size', 'int', 500, 'MOBILE_PUSH_LEDGER_CHUNK_SIZE', 'Rows per insert / claim.', 'ledger', constraint: '1..5000'),
                self::k('ledger.retention_days', 'int', 30, 'MOBILE_PUSH_LEDGER_RETENTION_DAYS', 'mobile-push:deliveries:prune keeps finished deliveries this many days.', 'console', constraint: '>= 0'),
                self::k('ledger.claim_strategy', 'string', 'auto', 'MOBILE_PUSH_LEDGER_CLAIM_STRATEGY', 'How workers claim rows (auto = SKIP LOCKED where the database supports it).', 'ledger', ['auto', 'skip_locked', 'optimistic']),
            ]),
            self::section('queue', 'Queue', 'The QUEUE connection and names used by queue() (distinct from the push connection). Timeout chain checked by mobile-push:doctor: transport timeout < chunk_deadline < timeout < worker timeout < retry_after (SQS: visibility timeout).', [
                self::k('queue.connection', 'string|null', null, 'MOBILE_PUSH_QUEUE_CONNECTION', 'Queue connection; null = the application default.', 'queue'),
                self::k('queue.queue', 'string', 'push', 'MOBILE_PUSH_QUEUE', 'Queue of transactional traffic.', 'queue'),
                self::k('queue.bulk_queue', 'string', 'push-bulk', 'MOBILE_PUSH_BULK_QUEUE', 'Queue of bulk() traffic.', 'queue'),
                self::k('queue.chunk', 'int', 500, 'MOBILE_PUSH_QUEUE_CHUNK', 'Destinations per light job / deliveries per relay claim.', 'queue', constraint: '1..10000'),
                self::k('queue.chunk_bytes', 'int', 98304, 'MOBILE_PUSH_QUEUE_CHUNK_BYTES', 'Estimated bytes of one light job before encryption.', 'queue', constraint: '>= 4096'),
                self::k('queue.timeout', 'int', 50, 'MOBILE_PUSH_QUEUE_TIMEOUT', 'Job timeout, seconds.', 'queue', constraint: '>= 1'),
                self::k('queue.chunk_deadline', 'int', 40, 'MOBILE_PUSH_QUEUE_CHUNK_DEADLINE', 'Wall time of one relay run before it hands over, seconds.', 'queue', constraint: '>= 1'),
                self::k('queue.after_commit', 'bool', true, 'MOBILE_PUSH_QUEUE_AFTER_COMMIT', 'Dispatch light jobs after the open transaction commits.', 'queue'),
                self::k('queue.follow_up_bucket', 'int', 60, 'MOBILE_PUSH_QUEUE_FOLLOW_UP_BUCKET', 'Deferred destinations whose next attempts are this close share one continuation job, seconds.', 'queue', constraint: '>= 0'),
                self::k('queue.worker_timeout', 'int|null', null, 'MOBILE_PUSH_QUEUE_WORKER_TIMEOUT', 'Declared worker timeout when it is not readable from Horizon.', 'queue'),
                self::k('queue.visibility_timeout', 'int|null', null, 'MOBILE_PUSH_QUEUE_VISIBILITY_TIMEOUT', 'Declared SQS visibility timeout.', 'queue'),
                self::k('queue.sweeper.schedule', 'bool', true, 'MOBILE_PUSH_QUEUE_SWEEPER', 'Durable profile: schedule the ledger sweeper every minute.', 'queue'),
                self::k('queue.sweeper.scopes_per_run', 'int', 100, null, 'Scopes the sweeper visits per run.', 'queue', constraint: '>= 1'),
            ]),
            self::section('receipts', 'Client receipts', 'Durable profile only. The app reports received/opened with the opaque `mp_r` marker; the HMAC key stays on the server and the marker is never logged. No receipt exists for topics and broadcasts.', [
                self::k('receipts.enabled', 'bool', false, 'MOBILE_PUSH_RECEIPTS_ENABLED', 'Enable markers and the receipts endpoint.', 'receipts'),
                self::k('receipts.marker_ttl', 'int', 604800, 'MOBILE_PUSH_RECEIPTS_TTL', 'Seconds a marker is accepted.', 'receipts', constraint: '>= 60'),
                self::k('receipts.max_batch', 'int', 20, null, 'Reports per request.', 'receipts', constraint: '>= 1'),
                self::k('receipts.route.prefix', 'string', 'mobile-push', 'MOBILE_PUSH_RECEIPTS_PREFIX', 'Route prefix: POST {prefix}/receipts.', 'receipts'),
                self::k('receipts.route.domain', 'string|null', null, null, 'Route domain.', 'receipts'),
                self::k('receipts.route.middleware', 'list<string>', ['throttle:mobile-push-receipts'], null, 'Route middleware (no auth by default: the marker is the proof).', 'receipts'),
                self::k('receipts.rate_limit.per_ip', 'int', 120, null, 'Requests per minute per IP; 0 = no limit.', 'receipts', constraint: '>= 0'),
                self::k('receipts.rate_limit.per_marker', 'int', 10, null, 'Reports per minute per marker; 0 = no limit.', 'receipts', constraint: '>= 0'),
                self::k('receipts.keys', 'map<int, string>', [], 'MOBILE_PUSH_RECEIPTS_KEY', 'Marker key ring, version (1..255) => base64 key >= 32 bytes (the env var sets version 1).', 'receipts', defaultNote: 'derived from APP_KEY'),
                self::k('receipts.key_current', 'int', 0, 'MOBILE_PUSH_RECEIPTS_KEY_VERSION', 'Version used for new markers; 0 = the highest.', 'receipts'),
            ]),
            self::section('metrics', 'Metrics and health', 'Counters in the package cache with bounded labels only (connection, provider, environment, status, reason code, traffic class). The Prometheus endpoint is off by default and refuses everything until a token is set.', [
                self::k('metrics.enabled', 'bool', true, 'MOBILE_PUSH_METRICS_ENABLED', 'Record metrics for every send.', 'health'),
                self::k('metrics.max_series', 'int', 1000, null, 'Ceiling of distinct series.', 'health', constraint: '>= 10'),
                self::k('metrics.prometheus.enabled', 'bool', false, 'MOBILE_PUSH_PROMETHEUS_ENABLED', 'Expose GET {path} in the Prometheus text format.', 'health'),
                self::k('metrics.prometheus.path', 'string', 'mobile-push/metrics', null, 'Endpoint path.', 'health'),
                self::k('metrics.prometheus.middleware', 'list<string>', [RequireMetricsToken::class], null, 'Endpoint middleware; [] makes it public.', 'health'),
                self::k('metrics.prometheus.token', 'string|null', null, 'MOBILE_PUSH_METRICS_TOKEN', 'Bearer token required by RequireMetricsToken.', 'health'),
                self::k('health.ledger_backlog_seconds', 'int', 300, null, 'Oldest due delivery older than this: readiness warning.', 'health', constraint: '>= 1'),
                self::k('health.ledger_backlog_failed_seconds', 'int', 3600, null, 'Oldest due delivery older than this: readiness failed.', 'health', constraint: '>= ledger_backlog_seconds'),
                self::k('health.spatie.register', 'bool', true, null, 'Register the check with spatie/laravel-health when installed.', 'health'),
                self::k('credentials.warn_days', 'int', 30, null, 'Warn this many days before a certificate expires.', 'health', constraint: '>= 0'),
            ]),
            self::section('alerts', 'Operational alerts', 'Mail and/or Slack when a circuit opens, a credential fails over, expires or is about to, the error rate is too high, the ledger backlog grows or a sink keeps failing. Once per cooldown; evaluated every minute by the scheduler when enabled.', [
                self::k('alerts.enabled', 'bool', false, 'MOBILE_PUSH_ALERTS_ENABLED', 'Enable alerts (and their scheduled evaluation).', 'health'),
                self::k('alerts.mail', 'list<string>', [], 'MOBILE_PUSH_ALERT_MAIL', 'Recipients, comma-separated in the env var.', 'health'),
                self::k('alerts.slack_webhook', 'string|null', null, 'MOBILE_PUSH_ALERT_SLACK', 'Slack incoming-webhook URL (https).', 'health'),
                self::k('alerts.cooldown_seconds', 'int', 3600, null, 'Minimum seconds between two alerts of the same kind and subject.', 'health', constraint: '>= 1'),
                self::k('alerts.send_in_simulate', 'bool', false, null, 'Also deliver alerts in simulate mode (otherwise only logged).', 'health'),
                self::k('alerts.error_rate.threshold', 'float', 0.5, null, 'Error share of a connection that raises an alert.', 'health', constraint: '0..1'),
                self::k('alerts.error_rate.min_sample', 'int', 50, null, 'Minimum results in the window.', 'health', constraint: '>= 1'),
                self::k('alerts.error_rate.window_seconds', 'int', 300, null, 'Error-rate window.', 'health', constraint: '>= 60'),
            ]),
            self::section('live_activities', 'Live Activities', 'Activity registry, push-to-start and update tokens, broadcast channels. Coalesced same-second updates and planned retries are flushed every minute by the scheduler when enabled.', [
                self::k('live_activities.enabled', 'bool', false, 'MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED', 'Enable the module (its migrations are published by mobile-push:install, or auto-loaded with migrations.autoload).', 'live_activities'),
                self::k('live_activities.binding', 'string', 'apns', 'MOBILE_PUSH_LA_BINDING', 'APNs connection used by default.', 'live_activities'),
                self::k('live_activities.connection', 'string|null', null, 'MOBILE_PUSH_LA_DB_CONNECTION', 'Database connection of the tables.', 'live_activities'),
                self::k('live_activities.table', 'string', 'mobile_push_live_activities', null, 'Activities table.', 'live_activities'),
                self::k('live_activities.push_to_start_table', 'string', 'mobile_push_live_activity_push_to_start_tokens', null, 'Push-to-start tokens table.', 'live_activities'),
                self::k('live_activities.channels_table', 'string', 'mobile_push_broadcast_channels', null, 'Broadcast channels table.', 'live_activities'),
                self::k('live_activities.models.activity', 'class-string', PushActivity::class, null, 'Activity model.', 'live_activities'),
                self::k('live_activities.models.push_to_start_token', 'class-string', PushToStartToken::class, null, 'Push-to-start token model.', 'live_activities'),
                self::k('live_activities.models.broadcast_channel', 'class-string', BroadcastChannel::class, null, 'Broadcast channel model.', 'live_activities'),
                self::k('live_activities.types', 'map<string, class-string>', [], null, 'Key => class implementing LiveActivityType.', 'live_activities'),
                self::k('live_activities.default_priority', 'string', 'high', 'MOBILE_PUSH_LA_PRIORITY', 'Priority of updates that do not choose one.', 'live_activities', ['high', 'normal']),
                self::k('live_activities.high_priority.max_per_hour', 'int|null', 20, 'MOBILE_PUSH_LA_HIGH_PRIORITY_PER_HOUR', 'High-priority updates per activity per hour; beyond it they go at priority 5. null = no limit.', 'live_activities'),
                self::k('live_activities.local_expiry.hours', 'int', 8, null, 'Hours after which iOS ends an activity: nothing is sent after it.', 'live_activities', constraint: '>= 1'),
                self::k('live_activities.local_expiry.from', 'string', 'start', null, 'Instant the local expiry counts from.', 'live_activities', ['start', 'last_update']),
                self::k('live_activities.max_update_retries', 'int', 3, null, 'Retries of a failed replaceable update.', 'live_activities', constraint: '>= 0'),
                self::k('live_activities.prune.closed_after_days', 'int', 7, null, 'Closed activities are pruned after this many days.', 'live_activities', constraint: '>= 0'),
                self::k('live_activities.channels.max_per_environment', 'int', 9500, 'MOBILE_PUSH_LA_CHANNEL_CAP', 'Local cap of broadcast channels per app and environment (Apple allows 10,000).', 'live_activities', constraint: '1..9999'),
                self::k('live_activities.channels.cleanup_after_hours', 'int', 24, null, 'Unused channels are deleted after this many hours.', 'live_activities', constraint: '>= 0'),
            ]),
            self::section('policies', 'Frequency cap and badge', 'Decided once per logical effect and per person (all devices of a user count once), never per device or retry. Never applied to topics, broadcasts or Live Activity transitions; bypassFrequencyCap() never bypasses consent. The badge counter acts only for messages with incrementBadge(): every device of the owner gets the same number once per effect; reset it with MobilePush::resetBadge($owner).', [
                self::k('frequency.enabled', 'bool', false, 'MOBILE_PUSH_FREQUENCY_ENABLED', 'Enable the frequency cap.', 'policies'),
                self::k('frequency.limits', 'map<string, array|string>', ['default' => ['per_hour' => 5, 'per_day' => 20], 'marketing' => ['per_day' => 2], 'security' => 'unlimited'], null, 'Bucket => [per_hour, per_day] (either or both) or "unlimited"; a message picks its bucket with ->policy().', 'policies'),
                self::k('frequency.no_policy_bucket', 'string|null', 'default', 'MOBILE_PUSH_FREQUENCY_NO_POLICY_BUCKET', 'Bucket of messages without a policy; null or "" = not capped.', 'policies'),
                self::k('frequency.on_store_failure', 'string', 'allow', 'MOBILE_PUSH_FREQUENCY_ON_STORE_FAILURE', 'What to do when the counters cannot be read.', 'policies', ['allow', 'deny']),
                self::k('badge.enabled', 'bool', true, 'MOBILE_PUSH_BADGE_ENABLED', 'Resolve incrementBadge() on the server.', 'policies'),
                self::k('badge.ttl_days', 'int', 90, 'MOBILE_PUSH_BADGE_TTL_DAYS', 'An idle counter is forgotten after this many days.', 'policies', constraint: '>= 1'),
                self::k('badge.per_binding', 'bool', false, 'MOBILE_PUSH_BADGE_PER_BINDING', 'One counter per connection instead of one per owner.', 'policies'),
            ]),
            self::section('fallback', 'Fallback to another channel', 'Only for notifications that opt in (pushFallback()): when every push ended without being accepted (never after an unknown outcome or an opt-out), the fallback channel is sent once.', [
                self::k('fallback.enabled', 'bool', true, 'MOBILE_PUSH_FALLBACK_ENABLED', 'Master switch (a notification still needs the opt-in).', 'notifications'),
                self::k('fallback.when_no_destination', 'bool', true, 'MOBILE_PUSH_FALLBACK_WHEN_NO_DESTINATION', 'Also fall back when the notifiable has no push destination at all.', 'notifications'),
                self::k('fallback.idempotency_ttl', 'int', 86400, 'MOBILE_PUSH_FALLBACK_IDEMPOTENCY_TTL', 'Seconds "this fallback was sent" is remembered.', 'notifications', constraint: '>= 1'),
                self::k('fallback.cache_store', 'string|null', null, 'MOBILE_PUSH_FALLBACK_CACHE_STORE', 'Cache store of that memory; null = cache.store.', 'notifications'),
            ]),
            self::section('campaigns', 'Campaigns', 'Durable profile only: a stable upper bound fixed at planning time, a final eligibility check before the network, pause/resume/cancel.', [
                self::k('campaigns.enabled', 'bool', false, 'MOBILE_PUSH_CAMPAIGNS_ENABLED', 'Enable campaigns (the migration is published by mobile-push:install, or auto-loaded with migrations.autoload).', 'campaigns'),
                self::k('campaigns.table', 'string', 'mobile_push_campaigns', null, 'Campaigns table.', 'campaigns'),
                self::k('campaigns.connection', 'string|null', null, null, 'Database connection; null = default.', 'campaigns'),
                self::k('campaigns.slice', 'int', 1000, null, 'Destinations per planner job.', 'campaigns', constraint: '1..5000'),
                self::k('campaigns.default_rate_per_minute', 'int|null', null, null, 'Planner pacing when a campaign sets none; null = as fast as possible.', 'campaigns'),
                self::k('campaigns.retention_days', 'int', 30, null, 'Finished campaigns are pruned after this many days (also the ledger dedupe window of a campaign).', 'campaigns', constraint: '>= 1'),
                self::k('campaigns.sweeper.schedule', 'bool', true, null, 'Schedule the campaign sweeper every minute.', 'campaigns'),
                self::k('campaigns.audiences', 'map<string, class-string>', [], null, 'Alias => Audience class.', 'campaigns'),
            ]),
            self::section('maintenance', 'Scheduled maintenance', 'Once a day the package schedules the prune commands of the modules that are ENABLED (`mobile-push:deliveries:prune` on the durable profile, `mobile-push:devices:prune`, `mobile-push:logs:prune` with the database log sink, `mobile-push:live-activity:prune`, `mobile-push:campaign:prune` with campaigns on the durable profile) and `mobile-push:credentials:check`, which runs whenever scheduling is on. With `alerts.enabled` off, `credentials:check` sends nothing: it only computes the findings, visible through its exit code (a failed scheduled task on warnings or errors) and `mobile-push:health`. Each event uses withoutOverlapping() (one-hour lock) and onOneServer() only when the default cache store is shared between servers (redis, memcached, database, dynamodb). The application needs the scheduler running (`schedule:run` every minute). The minutely sweepers and the alert evaluation are scheduled separately and are not affected.', [
                self::k('maintenance.schedule', 'bool', true, 'MOBILE_PUSH_MAINTENANCE_SCHEDULE', 'Schedule the daily maintenance commands; false = schedule none of them (run the commands yourself).', 'integration'),
                self::k('maintenance.daily_at', 'string', '03:17', 'MOBILE_PUSH_MAINTENANCE_DAILY_AT', 'Time of day of the maintenance run, in the scheduler time zone.', 'integration', constraint: 'HH:MM, 24 hours'),
            ]),
            self::section('observability', 'Laravel Pulse, Telescope and test fakes', 'Optional integrations, active only when the package is installed, and the guard of the test fakes.', [
                self::k('pulse.enabled', 'bool', false, 'MOBILE_PUSH_PULSE_ENABLED', 'Feed the Pulse card.', 'pulse'),
                self::k('pulse.sample_rate', 'float', 1.0, 'MOBILE_PUSH_PULSE_SAMPLE_RATE', 'Share of events recorded.', 'pulse', constraint: '0..1'),
                self::k('pulse.max_connections', 'int', 50, null, 'Distinct connection names used in Pulse keys.', 'pulse', constraint: '>= 1'),
                self::k('testing.allow_fakes', 'bool', false, null, 'Allow MobilePush::fake() / fakeTransport() outside APP_ENV=testing (never in a real deployment).', 'testing'),
                self::k('telescope.enabled', 'string|bool', 'auto', 'MOBILE_PUSH_TELESCOPE_ENABLED', 'Record package entries in Telescope: auto = when Telescope is enabled.', 'telescope', ['auto', 'true', 'false']),
            ]),
        ];
    }

    /**
     * @param  list<ConfigKey>  $keys
     * @return array{id: string, title: string, intro: string, keys: list<ConfigKey>}
     */
    private static function section(string $id, string $title, string $intro, array $keys): array
    {
        return ['id' => $id, 'title' => $title, 'intro' => $intro, 'keys' => $keys];
    }

    /** @param  list<string>|null  $allowed */
    private static function k(string $key, string $type, mixed $default, ?string $env, string $description, string $module, ?array $allowed = null, ?string $constraint = null, ?string $defaultNote = null): ConfigKey
    {
        return new ConfigKey($key, $type, $default, $env, $description, $module, $allowed, $constraint, $defaultNote);
    }
}
