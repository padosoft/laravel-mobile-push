<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Psr\Clock\ClockInterface;

/** Shared "paused until" instant per provider account (quota, provider-wide slowdown). */
final class ThrottleGate
{
    public function __construct(
        private readonly GuardStore $store,
        private readonly ClockInterface $clock,
    ) {}

    public function isOpen(string $accountKey, ?\DateTimeImmutable $now = null): bool
    {
        $until = $this->pausedUntil($accountKey);

        return $until === null || Millis::of($until) <= Millis::of($now ?? $this->clock->now());
    }

    public function pausedUntil(string $accountKey): ?\DateTimeImmutable
    {
        $until = StateCell::read($this->store, $this->key($accountKey))['until'] ?? null;
        if ($until === null || $until <= Millis::of($this->clock->now())) {
            return null;
        }

        return Millis::toDate($until);
    }

    /** A later pause never shortens an existing one. @return \DateTimeImmutable the effective pause end */
    public function pause(string $accountKey, \DateTimeImmutable $until): \DateTimeImmutable
    {
        $ms = Millis::of($until);
        // The stored pause must outlive the pause itself: a fixed day would silently end a longer one.
        $ttl = max(86400, intdiv(max(0, $ms - Millis::of($this->clock->now())), 1000) + 60);
        /** @var int $effective */
        $effective = StateCell::update($this->store, $this->key($accountKey), $ttl, static function (array $st) use ($ms): array {
            $effective = max($ms, $st['until'] ?? 0);

            return [['until' => $effective], $effective];
        });

        return Millis::toDate($effective);
    }

    private function key(string $accountKey): string
    {
        return 'mp:gate:'.$accountKey;
    }
}
