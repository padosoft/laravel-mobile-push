<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Psr\Clock\ClockInterface;

/** Process-local TokenStore with TTL, compare-and-swap and expiring refresh locks. */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, \stdClass> key anchors; values live in so var_export() shows no token */
    private array $anchors = [];

    /** @var \SplObjectStorage<\stdClass, array{token: AuthToken, expires: int}> */
    private \SplObjectStorage $entries;

    /** @var array<string, int> */
    private array $locks = [];

    public function __construct(private readonly ClockInterface $clock)
    {
        $this->entries = new \SplObjectStorage;
    }

    public function get(string $cacheKey): ?AuthToken
    {
        $anchor = $this->anchors[$cacheKey] ?? null;
        if ($anchor === null || ! $this->entries->offsetExists($anchor)) {
            return null;
        }
        $entry = $this->entries[$anchor];
        if ($entry['expires'] <= $this->now()) {
            $this->forget($cacheKey);

            return null;
        }

        return $entry['token'];
    }

    public function compareAndSwap(string $cacheKey, ?string $expectedGeneration, AuthToken $new, int $ttlSeconds): AuthToken
    {
        $current = $this->get($cacheKey);
        if (($current?->generation()) !== $expectedGeneration) {
            return $current ?? $new;
        }

        $anchor = $this->anchors[$cacheKey] ??= new \stdClass;
        $this->entries[$anchor] = ['token' => $new, 'expires' => $this->now() + max(1, $ttlSeconds)];

        return $new;
    }

    public function acquireRefreshLock(string $cacheKey, int $seconds): bool
    {
        $until = $this->locks[$cacheKey] ?? 0;
        if ($until > $this->now()) {
            return false;
        }
        $this->locks[$cacheKey] = $this->now() + max(1, $seconds);

        return true;
    }

    public function releaseRefreshLock(string $cacheKey): void
    {
        unset($this->locks[$cacheKey]);
    }

    public function forget(string $cacheKey): void
    {
        if (isset($this->anchors[$cacheKey])) {
            $this->entries->detach($this->anchors[$cacheKey]);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
