<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Events;

/**
 * Every delivery of the campaign is terminal. Fired once. The counters come from the ledger; an unknown outcome is
 * reported apart from the failures (it may have been accepted).
 */
final readonly class CampaignCompleted
{
    /** @param  array<string, int>  $counts  ledger counters by delivery state */
    public function __construct(
        public string $scope,
        public string $campaignId,
        public string $name,
        public int $planned,
        public array $counts,
        public int $accepted,
        /** Known not accepted: rejected + failed + expired. */
        public int $notAccepted,
        /** Response lost or worker died after sending: may or may not have been accepted. NOT a failure. */
        public int $unknown,
        /** Skipped by a local rule (consent, eligibility, binding) or cancelled. */
        public int $skipped,
    ) {}
}
