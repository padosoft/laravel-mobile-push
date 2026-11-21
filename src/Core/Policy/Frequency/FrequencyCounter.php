<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Psr\Clock\ClockInterface;

/**
 * Rolling-window frequency counting on a GuardStore.
 *
 * State per (scope, subject, bucket) is ONE store value updated by compare-and-set: the timestamps of the allowed
 * effects inside the longest window, and the recently capped effects. The effect id of every decision lives in that
 * same value, so two workers deciding the same effect at the same moment are serialised by the compare-and-set and
 * the second one finds the first one's decision instead of consuming again. A separate per-effect decision key
 * (TTL: longest window + one hour) is written after the decision and read first, so a retry long after the window
 * would have had room still gets the original answer.
 *
 * Storage per subject is bounded: allowed entries never exceed the largest configured limit (an effect is only
 * recorded when every window had room), capped ids are limited to MAX_CAPPED. Subjects and effect keys are hashed
 * into the store keys; no raw owner id or effect key is stored.
 */
final class FrequencyCounter
{
    private const MAX_CAPPED = 256;

    private const DECISION_MARGIN = 3600;

    public function __construct(
        private readonly GuardStore $store,
        private readonly ClockInterface $clock,
        private readonly int $maxAttempts = 16,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }
    }

    /**
     * Decides (and, when allowed, consumes one unit) for the effect. `$effectKey` null means there is no stable
     * identity: the call is its own effect and a retry may consume again.
     *
     * @throws FrequencyStoreUnavailable
     */
    public function decide(string $scope, string $subject, string $bucket, BucketLimits $limits, ?string $effectKey): FrequencyDecision
    {
        if ($limits->isUnlimited()) {
            return FrequencyDecision::allowed();
        }

        $base = hash('sha256', $scope."\0".$subject."\0".$bucket);
        $ttl = $limits->longestWindowSeconds() + self::DECISION_MARGIN;
        $decisionKey = $effectKey === null ? null : 'freq:d:'.hash('sha256', $base."\0".$effectKey);

        if ($decisionKey !== null) {
            $known = FrequencyDecision::decode($this->read(fn (): ?string => $this->store->get($decisionKey)));
            if ($known !== null) {
                return $known;
            }
        }

        $effectId = $effectKey === null ? bin2hex(random_bytes(8)) : substr(hash('sha256', $effectKey), 0, 16);
        $decision = $this->consume('freq:s:'.$base, $limits, $effectId, $ttl);

        if ($decisionKey === null) {
            return $decision;
        }
        if ($this->swallow(fn (): bool => $this->store->compareAndSet($decisionKey, null, $decision->encode(), $ttl))) {
            return $decision;
        }

        // Somebody stored a decision for this effect first: theirs is the answer (it matches ours unless the
        // store lost a write, in which case the stored one is still the one the other devices got).
        return FrequencyDecision::decode($this->read(fn (): ?string => $this->store->get($decisionKey))) ?? $decision;
    }

    private function consume(string $stateKey, BucketLimits $limits, string $effectId, int $ttl): FrequencyDecision
    {
        for ($attempt = 0; $attempt < $this->maxAttempts; $attempt++) {
            $now = $this->clock->now()->getTimestamp();
            $raw = $this->read(fn (): ?string => $this->store->get($stateKey));
            [$allowed, $capped] = $this->parse($raw);

            $longest = $limits->longestWindowSeconds();
            $allowed = array_values(array_filter($allowed, static fn (array $e): bool => $e[1] > $now - $longest));
            $capped = array_values(array_filter($capped, static fn (array $e): bool => $e[1] > $now - $ttl));

            foreach ($allowed as $entry) {
                if ($entry[0] === $effectId) {
                    return FrequencyDecision::allowed();
                }
            }
            foreach ($capped as $entry) {
                if ($entry[0] === $effectId) {
                    return FrequencyDecision::capped($entry[2], $entry[3]);
                }
            }

            $decision = FrequencyDecision::allowed();
            foreach ($limits->windows as $window) {
                $inside = 0;
                foreach ($allowed as $entry) {
                    if ($window->contains($entry[1], $now)) {
                        $inside++;
                    }
                }
                if ($inside >= $window->limit) {
                    $decision = FrequencyDecision::capped($window->name, $window->limit);
                    break;
                }
            }

            if ($decision->allowed) {
                $allowed[] = [$effectId, $now];
            } else {
                $capped[] = [$effectId, $now, (string) $decision->window, (int) $decision->limit];
                $capped = array_slice($capped, -self::MAX_CAPPED);
            }

            $encoded = json_encode(['a' => $allowed, 'c' => $capped], JSON_THROW_ON_ERROR);
            if ($this->swallow(fn (): bool => $this->store->compareAndSet($stateKey, $raw, $encoded, $ttl))) {
                return $decision;
            }
        }

        throw new FrequencyStoreUnavailable('The frequency counter could not be updated.');
    }

    /**
     * @return array{0: list<array{0: string, 1: int}>, 1: list<array{0: string, 1: int, 2: string, 3: int}>}
     */
    private function parse(?string $raw): array
    {
        $data = $raw === null ? null : json_decode($raw, true);
        $allowed = [];
        $capped = [];
        if (is_array($data)) {
            foreach (is_array($data['a'] ?? null) ? $data['a'] : [] as $e) {
                if (is_array($e) && isset($e[0], $e[1]) && is_string($e[0]) && is_int($e[1])) {
                    $allowed[] = [$e[0], $e[1]];
                }
            }
            foreach (is_array($data['c'] ?? null) ? $data['c'] : [] as $e) {
                if (is_array($e) && isset($e[0], $e[1], $e[2], $e[3]) && is_string($e[0]) && is_int($e[1]) && is_string($e[2]) && is_int($e[3])) {
                    $capped[] = [$e[0], $e[1], $e[2], $e[3]];
                }
            }
        }

        return [$allowed, $capped];
    }

    /** @param  callable(): ?string  $read */
    private function read(callable $read): ?string
    {
        try {
            return $read();
        } catch (\Throwable) {
            throw new FrequencyStoreUnavailable('The frequency store could not be read.');
        }
    }

    /** @param  callable(): bool  $write */
    private function swallow(callable $write): bool
    {
        try {
            return $write();
        } catch (\Throwable) {
            throw new FrequencyStoreUnavailable('The frequency store could not be written.');
        }
    }
}
