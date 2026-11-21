<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Writes ONE slice of a campaign's audience to the ledger, then queues the next one (CampaignPlanner).
 *
 * Carries ids only: the audience, the cursor and the message are read again from storage. `expect` is the number of
 * slices the campaign had when this job was queued: a job whose slice was already written by someone else does
 * nothing but make sure the chain continues, so duplicated or redelivered jobs never multiply the chain and never
 * write a slice twice.
 */
final class PlanCampaignJob implements ShouldQueue
{
    use Queueable;

    /** Safe to retry: a rerun resumes from the stored cursor and the ledger ignores rows it already has. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $scope,
        public readonly string $campaignId,
        public readonly int $expect = 0,
        /** How many times this job found itself early for its pacing slot and re-queued itself. */
        public readonly int $early = 0,
        int $timeout = 120,
    ) {
        $this->timeout = $timeout;
    }

    public function handle(CampaignPlanner $planner): void
    {
        $planner->run($this->scope, $this->campaignId, $this->expect, $this->early);
    }

    /** Called by the queue once the tries are used up: keep the campaign resumable, note the error class. */
    public function failed(?\Throwable $e = null): void
    {
        app(CampaignPlanner::class)->noteError($this->scope, $this->campaignId, $e);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['mobile-push', 'campaign', 'scope:'.$this->scope, 'campaign:'.$this->campaignId];
    }

    public function displayName(): string
    {
        return 'mobile-push campaign slice '.$this->campaignId.' #'.($this->expect + 1);
    }
}
