<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/**
 * The campaign invariant checked against the ledger:
 *
 *   planned (the planner's committed checkpoint) = deliveries in the ledger with this campaign id
 *                                                = deliveries of the campaign's effect
 *
 * counted over every state (waiting, in flight, deferred, unknown and every terminal state). A difference while a
 * slice is being written (`sliceInProgress`) is expected and closes when that slice commits its checkpoint, or when
 * a planner takes the expired lease over and recounts. Any other difference is a defect and is reported, never
 * silently corrected.
 */
final readonly class ReconcileReport
{
    public function __construct(
        public string $campaignId,
        public int $planned,
        /** Deliveries in the ledger with this campaign id. */
        public int $ledger,
        /** Deliveries of the campaign's single effect. */
        public int $effect,
        public bool $sliceInProgress,
    ) {}

    /** Ledger rows not covered by the checkpoint (positive) or checkpoint rows missing from the ledger (negative). */
    public function difference(): int
    {
        return $this->ledger - $this->planned;
    }

    /** Deliveries carrying the campaign id that do not belong to its effect (should never happen). */
    public function stray(): int
    {
        return $this->ledger - $this->effect;
    }

    public function clean(): bool
    {
        return $this->difference() === 0 && $this->stray() === 0;
    }

    /** A difference explained by a slice being written right now. */
    public function explained(): bool
    {
        return $this->clean() || ($this->sliceInProgress && $this->difference() > 0 && $this->stray() === 0);
    }

    public function describe(): string
    {
        if ($this->clean()) {
            return 'Clean: '.$this->planned.' unique destinations planned, '.$this->ledger.' deliveries in the ledger.';
        }

        return sprintf(
            'Difference: planned %d, ledger %d (effect %d)%s.',
            $this->planned,
            $this->ledger,
            $this->effect,
            $this->sliceInProgress ? '; a slice is being written, the difference should close when it commits' : '; no slice in progress: this is a defect to investigate',
        );
    }
}
