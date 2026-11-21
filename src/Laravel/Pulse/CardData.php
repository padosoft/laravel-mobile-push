<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

/**
 * What the card shows for one period. Pulse's `remember` caches it between polls: it is cached as a plain array
 * (toArray / fromArray), never as this object, because a cache store may refuse to rebuild objects (Laravel 13 ships
 * `cache.serializable_classes = false`, and an object read back then is an incomplete class).
 *
 * @phpstan-type Row array{label: string, accepted: int, rejected: int, failed: int, unknown: int, deferred: int, skipped: int, rate: float|null, max_ms: int|null, avg_ms: int|null}
 */
final readonly class CardData
{
    /**
     * @param  list<Row>  $connections
     * @param  list<array{reason: string, count: int}>  $reasons
     * @param  list<string>  $openCircuits
     * @param  list<string>  $throttled
     */
    public function __construct(
        public array $connections = [],
        public array $reasons = [],
        public array $openCircuits = [],
        public array $throttled = [],
        public int $throttleEvents = 0,
        public int $circuitTransitions = 0,
    ) {}

    /** @return array{connections: list<Row>, reasons: list<array{reason: string, count: int}>, openCircuits: list<string>, throttled: list<string>, throttleEvents: int, circuitTransitions: int} */
    public function toArray(): array
    {
        return [
            'connections' => $this->connections,
            'reasons' => $this->reasons,
            'openCircuits' => $this->openCircuits,
            'throttled' => $this->throttled,
            'throttleEvents' => $this->throttleEvents,
            'circuitTransitions' => $this->circuitTransitions,
        ];
    }

    /** Anything that is not the array toArray() made (an entry cached by another version, an incomplete class) reads as empty. */
    public static function fromArray(mixed $cached): self
    {
        if (! is_array($cached)
            || ! is_array($cached['connections'] ?? null) || ! is_array($cached['reasons'] ?? null)
            || ! is_array($cached['openCircuits'] ?? null) || ! is_array($cached['throttled'] ?? null)
            || ! is_int($cached['throttleEvents'] ?? null) || ! is_int($cached['circuitTransitions'] ?? null)) {
            return new self;
        }

        /** @var array{connections: list<Row>, reasons: list<array{reason: string, count: int}>, openCircuits: list<string>, throttled: list<string>, throttleEvents: int, circuitTransitions: int} $cached */
        return new self($cached['connections'], $cached['reasons'], $cached['openCircuits'], $cached['throttled'], $cached['throttleEvents'], $cached['circuitTransitions']);
    }
}
