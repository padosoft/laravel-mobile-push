<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

/** A store that stores nothing: what an unavailable backend looks like. Locks are never granted. */
final class NullTokenStore implements TokenStore
{
    public function get(string $cacheKey): ?AuthToken
    {
        return null;
    }

    public function compareAndSwap(string $cacheKey, ?string $expectedGeneration, AuthToken $new, int $ttlSeconds): AuthToken
    {
        return $new;
    }

    public function acquireRefreshLock(string $cacheKey, int $seconds): bool
    {
        return false;
    }

    public function releaseRefreshLock(string $cacheKey): void {}

    public function forget(string $cacheKey): void {}
}
