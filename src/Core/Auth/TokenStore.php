<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

/**
 * Shared storage for provider tokens. `$cacheKey` is only a NAME derived from non-secret identifiers
 * (scope, provider, identity, OAuth scopes, delegation context): it is not cryptographic material.
 * Implementations protect the stored value with authenticated encryption under a separate secret key.
 *
 * Implementations must tolerate an unavailable backend by behaving as an empty store (never throw).
 */
interface TokenStore
{
    public function get(string $cacheKey): ?AuthToken;

    /**
     * Store `$new` only if the currently stored token has generation `$expectedGeneration`
     * (null = nothing stored). Returns the token that is in the store afterwards: the winner.
     */
    public function compareAndSwap(string $cacheKey, ?string $expectedGeneration, AuthToken $new, int $ttlSeconds): AuthToken;

    /** Try to become the single refresher for a while. Non-blocking. */
    public function acquireRefreshLock(string $cacheKey, int $seconds): bool;

    public function releaseRefreshLock(string $cacheKey): void;

    public function forget(string $cacheKey): void;
}
