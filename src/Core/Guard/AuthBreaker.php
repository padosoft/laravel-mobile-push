<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Psr\Clock\ClockInterface;

/**
 * Authentication breaker per credential identity, and owner of the failover decision.
 *
 * closed -> (threshold consecutive failures) -> open -> (cooldown) -> half-open -> success: closed / failure: open.
 * With a secondary credential available, the threshold switches to the secondary instead of opening, once:
 * failures reported for a generation that is no longer active are stale and ignored.
 * SlowTokenRefresh / RefreshToken are never reported here: the facade feeds only ReportAuthFailure.
 */
final class AuthBreaker
{
    private const CLOSED = 0;

    private const OPEN = 1;

    private const HALF_OPEN = 2;

    public function __construct(
        private readonly GuardStore $store,
        private readonly ClockInterface $clock,
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 60,
    ) {
        if ($failureThreshold < 1 || $cooldownSeconds < 1) {
            throw new \InvalidArgumentException('failureThreshold and cooldownSeconds must be positive.');
        }
    }

    /** Credential generation currently active for this identity: 0 = primary, 1 = secondary. */
    public function activeGeneration(string $credential): int
    {
        return StateCell::read($this->store, $this->key($credential))['gen'] ?? 0;
    }

    public function isOpen(string $credential): bool
    {
        return (StateCell::read($this->store, $this->key($credential))['s'] ?? self::CLOSED) !== self::CLOSED;
    }

    /** Asks whether a send may reach the provider. In half-open exactly one caller gets the probe. */
    public function check(string $credential): AuthCheck
    {
        $now = Millis::of($this->clock->now());
        $cooldownMs = $this->cooldownSeconds * 1000;

        /** @var AuthCheck $check */
        $check = StateCell::update($this->store, $this->key($credential), $this->ttl(), function (array $st) use ($now, $cooldownMs): array {
            $gen = $st['gen'] ?? 0;
            $state = $st['s'] ?? self::CLOSED;
            if ($state === self::CLOSED) {
                return [$st, new AuthCheck(true, false, null, $gen, BreakerTransition::None)];
            }
            $openedAt = $st['at'] ?? $now;
            if ($state === self::OPEN) {
                if ($now - $openedAt < $cooldownMs) {
                    return [$st, new AuthCheck(false, false, Millis::toDate($openedAt + $cooldownMs), $gen, BreakerTransition::None)];
                }
                $st['s'] = self::HALF_OPEN;
                $st['at'] = $now;
                $st['probe'] = 1;

                return [$st, new AuthCheck(true, true, null, $gen, BreakerTransition::HalfOpened)];
            }
            // Half-open: the probe is out; a lost probe is re-granted after another cooldown.
            if (($st['probe'] ?? 0) === 0 || $now - $openedAt >= $cooldownMs) {
                $st['probe'] = 1;
                $st['at'] = $now;

                return [$st, new AuthCheck(true, true, null, $gen, BreakerTransition::None)];
            }

            return [$st, new AuthCheck(false, false, Millis::toDate($openedAt + $cooldownMs), $gen, BreakerTransition::None)];
        });

        return $check;
    }

    public function recordSuccess(string $credential, int $generation): BreakerTransition
    {
        /** @var BreakerTransition $t */
        $t = StateCell::update($this->store, $this->key($credential), $this->ttl(), static function (array $st) use ($generation): array {
            if (($st['gen'] ?? 0) !== $generation) {
                return [$st, BreakerTransition::None];
            }
            if (($st['s'] ?? self::CLOSED) === self::HALF_OPEN) {
                return [['gen' => $generation, 's' => self::CLOSED, 'f' => 0], BreakerTransition::Closed];
            }
            if (($st['s'] ?? self::CLOSED) === self::CLOSED) {
                $st['f'] = 0;
            }

            return [$st, BreakerTransition::None];
        });

        return $t;
    }

    /**
     * @param  int  $generation  credential generation the failing attempt used
     * @param  bool  $hasSecondary  a secondary credential for the same identity exists and is not yet in use
     */
    public function recordFailure(string $credential, int $generation, bool $hasSecondary = false): BreakerTransition
    {
        $now = Millis::of($this->clock->now());
        $threshold = $this->failureThreshold;

        /** @var BreakerTransition $t */
        $t = StateCell::update($this->store, $this->key($credential), $this->ttl(), static function (array $st) use ($generation, $hasSecondary, $now, $threshold): array {
            $active = $st['gen'] ?? 0;
            $state = $st['s'] ?? self::CLOSED;
            if ($generation !== $active || $state === self::OPEN) {
                return [$st, BreakerTransition::None];   // stale (old generation) or already open
            }
            if ($state === self::HALF_OPEN) {
                return [['gen' => $active, 's' => self::OPEN, 'f' => $threshold, 'at' => $now], BreakerTransition::Reopened];
            }
            $failures = ($st['f'] ?? 0) + 1;
            if ($failures < $threshold) {
                return [['gen' => $active, 's' => self::CLOSED, 'f' => $failures], BreakerTransition::None];
            }
            if ($hasSecondary && $active === 0) {
                return [['gen' => 1, 's' => self::CLOSED, 'f' => 0], BreakerTransition::FailedOver];
            }

            return [['gen' => $active, 's' => self::OPEN, 'f' => $failures, 'at' => $now], BreakerTransition::Opened];
        });

        return $t;
    }

    private function key(string $credential): string
    {
        return 'mp:auth:'.$credential;
    }

    /** State outlives the cooldown: a fixed day would let a breaker with a longer cooldown close by expiry. */
    private function ttl(): int
    {
        return max(86400, $this->cooldownSeconds * 2);
    }
}
