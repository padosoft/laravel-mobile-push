<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

/**
 * What the card shows for one period (cached by Pulse's `remember`, hence plain serialisable values).
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
}
