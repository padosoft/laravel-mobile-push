<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

/**
 * TokenStore on a Laravel cache repository.
 *
 * - The cache key is only a name (hashed under the prefix); the VALUE is a bearer credential, so it is stored as an
 *   AES-256-GCM envelope (TokenCipher, dedicated key ring) bound to the cache key: a value copied under another key
 *   fails authentication and reads as a miss.
 * - compareAndSwap runs read-compare-write under a cache lock (bounded wait of ~100 ms).
 * - A broken backend, a missing lock capability, an undecryptable entry: the store behaves as an EMPTY store and
 *   never throws; the problem is reported through CacheHealth (warning, at most once a minute, no token values).
 *
 * Cross-process guarantees depend on the driver: redis, memcached, database and dynamodb are atomic across hosts;
 * file only on one host; array only inside one process; null stores nothing.
 */
final class CacheTokenStore implements TokenStore
{
    private const CAS_LOCK_SECONDS = 5;

    /** @var array<string, Lock> refresh locks held by THIS instance */
    private array $held = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly TokenCipher $cipher,
        private readonly CacheHealth $health,
        private readonly string $prefix = 'mobile-push',
    ) {}

    public function get(string $cacheKey): ?AuthToken
    {
        try {
            return $this->read($cacheKey);
        } catch (\Throwable $e) {
            $this->health->failed('token.get', $e);

            return null;
        }
    }

    public function compareAndSwap(string $cacheKey, ?string $expectedGeneration, AuthToken $new, int $ttlSeconds): AuthToken
    {
        $lock = null;

        try {
            if (! CacheLocks::supported($this->cache)) {
                $this->health->unsupported('token.compareAndSwap', 'cache store without lock support');

                return $this->read($cacheKey) ?? $new;
            }

            $lock = CacheLocks::acquire($this->cache, $this->lockName('cas', $cacheKey), self::CAS_LOCK_SECONDS);
            if ($lock === null) {
                // Contended: do not write; the caller keeps its own token or the winner already stored.
                return $this->read($cacheKey) ?? $new;
            }

            $current = $this->read($cacheKey);
            if ($current?->generation() !== $expectedGeneration) {
                return $current ?? $new;
            }

            $this->cache->put($this->entryKey($cacheKey), $this->encode($cacheKey, $new), max(1, $ttlSeconds));

            return $new;
        } catch (\Throwable $e) {
            $this->health->failed('token.compareAndSwap', $e);

            return $new;
        } finally {
            $this->quietRelease($lock);
        }
    }

    public function acquireRefreshLock(string $cacheKey, int $seconds): bool
    {
        try {
            $lock = CacheLocks::tryOnce($this->cache, $this->lockName('refresh', $cacheKey), $seconds);
        } catch (\Throwable $e) {
            $this->health->failed('token.acquireRefreshLock', $e);

            return false;
        }

        if ($lock === null) {
            return false;
        }
        $this->held[$cacheKey] = $lock;

        return true;
    }

    public function releaseRefreshLock(string $cacheKey): void
    {
        $lock = $this->held[$cacheKey] ?? null;
        unset($this->held[$cacheKey]);
        $this->quietRelease($lock);
    }

    public function forget(string $cacheKey): void
    {
        try {
            $this->cache->forget($this->entryKey($cacheKey));
        } catch (\Throwable $e) {
            $this->health->failed('token.forget', $e);
        }
    }

    /** @throws \Throwable */
    private function read(string $cacheKey): ?AuthToken
    {
        $raw = $this->cache->get($this->entryKey($cacheKey));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $json = $this->cipher->decrypt($raw, $cacheKey, 'token-store');
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // Tampered, copied from another key, or written with a key that is no longer configured.
            $this->health->failed('token.decode', $e);

            return null;
        }

        if (! is_array($data) || ! is_string($data['v'] ?? null) || ! is_string($data['i'] ?? null)) {
            return null;
        }

        $issued = \DateTimeImmutable::createFromFormat('U.u', $data['i']);
        $expires = is_string($data['e'] ?? null) ? \DateTimeImmutable::createFromFormat('U.u', $data['e']) : false;

        if ($issued === false) {
            return null;
        }

        return new AuthToken($data['v'], $issued, $expires === false ? null : $expires);
    }

    private function encode(string $cacheKey, AuthToken $token): string
    {
        $payload = json_encode([
            'v' => $token->value,
            'i' => $token->issuedAt->format('U.u'),
            'e' => $token->expiresAt?->format('U.u'),
        ], JSON_THROW_ON_ERROR);

        return $this->cipher->encrypt($payload, $cacheKey, 'token-store');
    }

    private function entryKey(string $cacheKey): string
    {
        return $this->prefix.':token:'.hash('sha256', $cacheKey);
    }

    private function lockName(string $kind, string $cacheKey): string
    {
        return $this->prefix.':lock:token-'.$kind.':'.hash('sha256', $cacheKey);
    }

    private function quietRelease(?Lock $lock): void
    {
        if ($lock === null) {
            return;
        }

        try {
            $lock->release();
        } catch (\Throwable $e) {
            $this->health->failed('token.release', $e);
        }
    }
}
