<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Badge;

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\ReportsAvailability;
use Psr\Clock\ClockInterface;

/**
 * Server-side unread counter per (scope, subject). `increment()` is idempotent per effect key: the value each effect
 * got is remembered in the same store value as the counter, so a retry returns the same number and two workers
 * incrementing at once are serialised by the compare-and-set (no lost update).
 *
 * The idempotency markers are bounded twice: they expire after `$markerTtlSeconds` and only the newest MAX_MARKERS
 * are kept. A retry later than that, or after more than MAX_MARKERS other effects of the same subject, would
 * increment again; retries happen within minutes, so this is the documented trade-off. The counter itself expires
 * after `$ttlSeconds` without an increment or a set.
 *
 * `reset()` keeps the markers: a retry of an effect that already counted still reports the number it was given.
 */
final class BadgeCounter
{
    private const MAX_MARKERS = 256;

    public function __construct(
        private readonly GuardStore $store,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 90 * 86400,
        private readonly int $markerTtlSeconds = 3 * 86400,
        private readonly int $maxAttempts = 32,
    ) {
        if ($ttlSeconds < 1 || $markerTtlSeconds < 1 || $maxAttempts < 1) {
            throw new \InvalidArgumentException('Badge TTLs and attempts must be positive.');
        }
    }

    /**
     * Adds one unread item for this effect, once, and returns the resulting number. `$effectKey` null means there
     * is no stable identity: every call increments.
     *
     * @throws BadgeStoreUnavailable
     */
    public function increment(string $scope, string $subject, ?string $effectKey): int
    {
        $effectId = $effectKey === null ? bin2hex(random_bytes(8)) : substr(hash('sha256', $effectKey), 0, 16);

        $result = 0;
        $this->update($scope, $subject, function (int $value, array $markers, int $now) use ($effectId, &$result): ?array {
            foreach ($markers as $marker) {
                if ($marker[0] === $effectId) {
                    $result = $marker[1];

                    return null;
                }
            }
            $result = $value + 1;
            $markers[] = [$effectId, $result, $now];

            return [$result, array_slice($markers, -self::MAX_MARKERS)];
        });

        return $result;
    }

    /** The current value (0 when there is none). A store that cannot be read behaves as empty. */
    public function current(string $scope, string $subject): int
    {
        try {
            return $this->parse($this->store->get($this->key($scope, $subject)), $this->clock->now()->getTimestamp())[0];
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @throws BadgeStoreUnavailable
     */
    public function set(string $scope, string $subject, int $value): void
    {
        $value = max(0, $value);
        $this->update($scope, $subject, static fn (int $current, array $markers): array => [$value, $markers]);
    }

    /**
     * The app opened: back to zero.
     *
     * @throws BadgeStoreUnavailable
     */
    public function reset(string $scope, string $subject): void
    {
        $this->set($scope, $subject, 0);
    }

    /**
     * @param  callable(int, list<array{0: string, 1: int, 2: int}>, int): ?array{0: int, 1: list<array{0: string, 1: int, 2: int}>}  $change  null = nothing to write
     */
    private function update(string $scope, string $subject, callable $change): void
    {
        $key = $this->key($scope, $subject);

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $now = $this->clock->now()->getTimestamp();
            try {
                $raw = $this->store->get($key);
            } catch (\Throwable) {
                throw new BadgeStoreUnavailable('The badge store could not be read.');
            }
            [$value, $markers] = $this->parse($raw, $now);

            $next = $change($value, $markers, $now);
            if ($next === null) {
                return;
            }
            $encoded = json_encode(['v' => $next[0], 'm' => $next[1]], JSON_THROW_ON_ERROR);

            try {
                if ($this->store->compareAndSet($key, $raw, $encoded, $this->ttlSeconds)) {
                    return;
                }
            } catch (\Throwable) {
                throw new BadgeStoreUnavailable('The badge store could not be written.');
            }
            if ($this->store instanceof ReportsAvailability && ! $this->store->available()) {
                throw new BadgeStoreUnavailable('The badge store is unavailable.');
            }
            if ($attempt < $this->maxAttempts) {
                // Another writer won. A short random pause keeps several workers from retrying in lock-step.
                usleep(random_int(100, 2000));
            }
        }

        throw new BadgeStoreUnavailable('The badge counter is too contended to update.');
    }

    /**
     * @return array{0: int, 1: list<array{0: string, 1: int, 2: int}>}
     */
    private function parse(?string $raw, int $now): array
    {
        $data = $raw === null ? null : json_decode($raw, true);
        if (! is_array($data)) {
            return [0, []];
        }
        $markers = [];
        foreach (is_array($data['m'] ?? null) ? $data['m'] : [] as $e) {
            if (is_array($e) && isset($e[0], $e[1], $e[2]) && is_string($e[0]) && is_int($e[1]) && is_int($e[2]) && $e[2] > $now - $this->markerTtlSeconds) {
                $markers[] = [$e[0], $e[1], $e[2]];
            }
        }

        return [is_int($data['v'] ?? null) ? max(0, $data['v']) : 0, $markers];
    }

    private function key(string $scope, string $subject): string
    {
        return 'badge:c:'.hash('sha256', $scope."\0".$subject);
    }
}
