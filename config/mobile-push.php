<?php

declare(strict_types=1);

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

];
