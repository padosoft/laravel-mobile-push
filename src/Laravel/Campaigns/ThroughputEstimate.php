<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/**
 * AN ESTIMATE of the time left, from how fast deliveries became terminal recently. It assumes the recent pace
 * continues (it does not know about rate limits that will open, deferrals that will come due, or slices not yet
 * planned). Never use it for a decision.
 */
final readonly class ThroughputEstimate
{
    public const BASIS_RECENT = 'recent_window';

    public const BASIS_SINCE_START = 'since_start';

    public function __construct(
        /** Terminal deliveries per second over the basis window. */
        public float $perSecond,
        /** Seconds until the currently known remaining deliveries are terminal; null when the pace is zero. */
        public ?int $remainingSeconds,
        /** recent_window or since_start */
        public string $basis,
        /** Length of the window the pace was measured on. */
        public int $windowSeconds,
        /** False while planning: deliveries not yet planned are not in the estimate. */
        public bool $coversWholeAudience,
    ) {}
}
