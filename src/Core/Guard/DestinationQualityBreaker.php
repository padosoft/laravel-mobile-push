<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Psr\Clock\ClockInterface;

/**
 * Per app binding: opens when routing-suspicion outcomes (Quarantine) dominate a large enough sample.
 *
 * The sample is a time-bounded window (counters reset after $windowSeconds without reset) that is halved when it
 * reaches $maxSample, which keeps the ratio and favours recent outcomes. Invalidate (410 / UNREGISTERED) and every
 * outcome that says nothing about the destination (auth, throttle, unknown, 5xx) is not part of the sample.
 * Only bulk traffic is gated; half-open grants a bounded probe batch and decides on its result.
 */
final class DestinationQualityBreaker
{
    private const CLOSED = 0;

    private const OPEN = 1;

    private const HALF_OPEN = 2;

    public function __construct(
        private readonly GuardStore $store,
        private readonly ClockInterface $clock,
        private readonly int $minSample = 200,
        private readonly float $threshold = 0.5,
        private readonly int $cooldownSeconds = 300,
        private readonly int $probeSize = 20,
        private readonly int $windowSeconds = 600,
        private readonly int $maxSample = 2000,
    ) {
        if ($minSample < 1 || $probeSize < 1 || $cooldownSeconds < 1 || $threshold <= 0.0 || $threshold >= 1.0) {
            throw new \InvalidArgumentException('Invalid destination quality breaker configuration.');
        }
    }

    /** Whether a decision belongs to the sample: 1 = bad, 0 = good, null = not counted. */
    public static function classify(Decision $decision): ?int
    {
        if ($decision->destinationAction === DestinationAction::Quarantine) {
            return 1;
        }
        if ($decision->destinationAction === DestinationAction::Invalidate || $decision->throttleProvider
            || $decision->credentialAction !== CredentialAction::None) {
            return null;
        }

        return in_array($decision->status, [SendStatus::Accepted, SendStatus::Validated, SendStatus::Rejected], true) ? 0 : null;
    }

    public function isOpen(string $binding): bool
    {
        return (StateCell::read($this->store, $this->key($binding))['s'] ?? self::CLOSED) !== self::CLOSED;
    }

    /**
     * How many of $n sends may go out now. Transactional traffic is never gated.
     *
     * @return array{0: int, 1: BreakerTransition, 2: ?\DateTimeImmutable} granted, transition (HalfOpened), retry-at when nothing granted
     */
    public function allow(string $binding, int $n, TrafficClass $class): array
    {
        if ($class === TrafficClass::Transactional || $n <= 0) {
            return [max(0, $n), BreakerTransition::None, null];
        }
        $now = Millis::of($this->clock->now());
        $cooldownMs = $this->cooldownSeconds * 1000;
        $probeSize = $this->probeSize;

        /** @var array{0: int, 1: BreakerTransition, 2: ?\DateTimeImmutable} $r */
        $r = StateCell::update($this->store, $this->key($binding), 86400, static function (array $st) use ($n, $now, $cooldownMs, $probeSize): array {
            $state = $st['s'] ?? self::CLOSED;
            if ($state === self::CLOSED) {
                return [$st, [$n, BreakerTransition::None, null]];
            }
            $at = $st['at'] ?? $now;
            $transition = BreakerTransition::None;
            if ($state === self::OPEN) {
                if ($now - $at < $cooldownMs) {
                    return [$st, [0, BreakerTransition::None, Millis::toDate($at + $cooldownMs)]];
                }
                $st = ['s' => self::HALF_OPEN, 'at' => $now, 'pg' => 0, 'pt' => 0, 'pb' => 0];
                $transition = BreakerTransition::HalfOpened;
            } elseif ($now - $at >= $cooldownMs) {
                // Probe results never came back: start a fresh probe batch.
                $st = ['s' => self::HALF_OPEN, 'at' => $now, 'pg' => 0, 'pt' => 0, 'pb' => 0];
            }
            $granted = min($n, max(0, $probeSize - ($st['pg'] ?? 0)));
            $st['pg'] = ($st['pg'] ?? 0) + $granted;

            return [$st, [$granted, $transition, $granted === 0 ? Millis::toDate(($st['at'] ?? $now) + $cooldownMs) : null]];
        });

        return $r;
    }

    /** Feeds $total counted outcomes of which $bad were routing suspicions. */
    public function record(string $binding, int $total, int $bad): BreakerTransition
    {
        if ($total <= 0) {
            return BreakerTransition::None;
        }
        $now = Millis::of($this->clock->now());
        [$minSample, $threshold, $probeSize] = [$this->minSample, $this->threshold, $this->probeSize];
        [$windowMs, $maxSample] = [$this->windowSeconds * 1000, $this->maxSample];

        /** @var BreakerTransition $t */
        $t = StateCell::update($this->store, $this->key($binding), 86400, static function (array $st) use ($total, $bad, $now, $minSample, $threshold, $probeSize, $windowMs, $maxSample): array {
            $state = $st['s'] ?? self::CLOSED;
            if ($state === self::OPEN) {
                return [$st, BreakerTransition::None];
            }
            if ($state === self::HALF_OPEN) {
                $st['pt'] = ($st['pt'] ?? 0) + $total;
                $st['pb'] = ($st['pb'] ?? 0) + $bad;
                if ($st['pb'] > $threshold * $probeSize) {
                    return [['s' => self::OPEN, 'at' => $now], BreakerTransition::Reopened];
                }
                if ($st['pt'] >= $probeSize) {
                    return [['s' => self::CLOSED, 'since' => $now, 'n' => 0, 'b' => 0], BreakerTransition::Closed];
                }

                return [$st, BreakerTransition::None];
            }
            if (! isset($st['since']) || $now - $st['since'] > $windowMs) {
                $st = ['s' => self::CLOSED, 'since' => $now, 'n' => 0, 'b' => 0];
            }
            $st['n'] += $total;
            $st['b'] += $bad;
            if ($st['n'] >= $minSample && $threshold < $st['b'] / $st['n']) {
                return [['s' => self::OPEN, 'at' => $now], BreakerTransition::Opened];
            }
            if ($st['n'] >= $maxSample) {
                $st['n'] = intdiv($st['n'], 2);
                $st['b'] = intdiv($st['b'], 2);
            }

            return [$st, BreakerTransition::None];
        });

        return $t;
    }

    private function key(string $binding): string
    {
        return 'mp:dest:'.$binding;
    }
}
