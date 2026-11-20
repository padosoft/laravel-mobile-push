<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/**
 * Minimal shared state behind the limiter, the gate and the breakers. A Laravel-cache-backed implementation
 * (Cache::add / Cache::put with an atomic lock or a Lua compare-and-set) can be swapped in by the framework layer.
 *
 * Values are opaque strings. Every read-modify-write in this package goes through compareAndSet().
 */
interface GuardStore
{
    public function get(string $key): ?string;

    public function put(string $key, string $value, int $ttlSeconds): void;

    /**
     * Atomically replaces the value only if the current one equals $expected (null = key absent or expired).
     *
     * @return bool true when the value was written
     */
    public function compareAndSet(string $key, ?string $expected, string $value, int $ttlSeconds): bool;
}
