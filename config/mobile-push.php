<?php

declare(strict_types=1);
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/*
 * Laravel Mobile Push configuration.
 *
 * This file grows milestone by milestone. docs/configuration.md is GENERATED from the config schema:
 * never edit that document by hand.
 */

$versionedKey = static fn (string $name): array => array_filter([1 => env($name)], static fn ($value): bool => is_string($value) && $value !== '');

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    */
    'default' => env('MOBILE_PUSH_CONNECTION'),

    'connections' => [],

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
