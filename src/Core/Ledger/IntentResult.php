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
        /**
         * Set when the key had been used before by an effect whose deduplication window has ended: that effect's id.
         * `created` is then true as well: a NEW effect now owns the key ("window expired, created again"), the
         * previous one is left as it was. Null for a first use of the key, a replay, or a call without a key.
         */
        public ?string $previousEffectId = null,
    ) {}

    /** True when this call created a second effect under a key whose earlier deduplication window had ended. */
    public function reusedExpiredKey(): bool
    {
        return $this->created && $this->previousEffectId !== null;
    }
}
