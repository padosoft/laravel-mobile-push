<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

final readonly class IntentResult
{
    public function __construct(
        public Effect $effect,
        /** False when the same key and fingerprint were already recorded (idempotent replay). */
        public bool $created,
        /** Delivery rows written by this call. */
        public int $added,
        /** Destinations of this call that already had a row (duplicates in the input, or recorded earlier). */
        public int $existing,
    ) {}
}
