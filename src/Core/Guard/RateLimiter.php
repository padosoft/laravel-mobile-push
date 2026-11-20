<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Psr\Clock\ClockInterface;

/**
 * Token bucket counted in messages (not jobs), keyed by provider account.
 *
 * The bucket refills continuously (rolling, not aligned to the clock minute) at $ratePerMinute and holds at most
 * $burst permits (default: one minute of traffic). Over any window of T seconds at most burst + rate*T/60 permits
 * are granted. Bulk traffic can never take the last `reservedShare` of the capacity, which stays available to
 * transactional traffic. Arithmetic is integer only: permits are scaled by 60000 (rate is permits per 60000 ms).
 */
final class RateLimiter
{
    private const SCALE = 60000;

    /**
     * @param  int|null  $ratePerMinute  application ceiling; null = unlimited
     * @param  float  $reservedShare  fraction of the capacity reserved to transactional traffic (0..1)
     * @param  int|null  $burst  bucket capacity in messages; null = $ratePerMinute
     * @param  array<string, int|null>  $rateOverrides  per account key rate; an explicit null means unlimited
     */
    public function __construct(
        private readonly GuardStore $store,
        private readonly ClockInterface $clock,
        private readonly ?int $ratePerMinute = 500_000,
        private readonly float $reservedShare = 0.2,
        private readonly ?int $burst = null,
        private readonly array $rateOverrides = [],
    ) {
        if ($reservedShare < 0.0 || $reservedShare > 1.0) {
            throw new \InvalidArgumentException('reservedShare must be between 0 and 1.');
        }
    }

    /** @return int how many of the $n requested permits are granted now (0..n) */
    public function acquire(string $accountKey, int $n, TrafficClass $class): int
    {
        if ($n <= 0) {
            return 0;
        }
        $rate = $this->rateFor($accountKey);
        if ($rate === null) {
            return $n;
        }
        $cap = $this->capacity($rate);
        $now = Millis::of($this->clock->now());
        $reserve = $this->reserve($cap, $class);

        /** @var int $granted */
        $granted = StateCell::update($this->store, $this->key($accountKey), $this->ttl($rate, $cap), function (array $st) use ($rate, $cap, $now, $reserve, $n): array {
            $tokens = $this->refilled($st, $rate, $cap, $now);
            $granted = min($n, max(0, intdiv($tokens - $reserve * self::SCALE, self::SCALE)));

            return [['t' => $tokens - $granted * self::SCALE, 'ms' => max($now, $st['ms'] ?? $now)], $granted];
        });

        return $granted;
    }

    /** Earliest instant at which min($n, usable capacity) permits are available for this class (now when already so). */
    public function availableAt(string $accountKey, int $n, TrafficClass $class): \DateTimeImmutable
    {
        $nowDate = $this->clock->now();
        $rate = $this->rateFor($accountKey);
        if ($rate === null || $n <= 0) {
            return $nowDate;
        }
        $cap = $this->capacity($rate);
        $reserve = $this->reserve($cap, $class);
        $needed = min($n, max(1, $cap - $reserve));
        $now = Millis::of($nowDate);
        $tokens = $this->refilled(StateCell::read($this->store, $this->key($accountKey)), $rate, $cap, $now);
        $deficit = ($needed + $reserve) * self::SCALE - $tokens;
        if ($deficit <= 0) {
            return $nowDate;
        }

        return Millis::toDate($now + intdiv($deficit + $rate - 1, $rate));
    }

    private function rateFor(string $accountKey): ?int
    {
        return array_key_exists($accountKey, $this->rateOverrides) ? $this->rateOverrides[$accountKey] : $this->ratePerMinute;
    }

    private function capacity(int $rate): int
    {
        return max(1, $this->burst ?? $rate);
    }

    private function reserve(int $cap, TrafficClass $class): int
    {
        return $class === TrafficClass::Bulk ? (int) floor($cap * $this->reservedShare) : 0;
    }

    /** @param array<string, int> $st */
    private function refilled(array $st, int $rate, int $cap, int $now): int
    {
        if (! isset($st['t'], $st['ms'])) {
            return $cap * self::SCALE;
        }

        return min($cap * self::SCALE, $st['t'] + max(0, $now - $st['ms']) * $rate);
    }

    private function ttl(int $rate, int $cap): int
    {
        return intdiv($cap * 60 + $rate - 1, $rate) + 60;
    }

    private function key(string $accountKey): string
    {
        return 'mp:rate:'.$accountKey;
    }
}
