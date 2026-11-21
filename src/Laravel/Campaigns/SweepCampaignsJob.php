<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Scheduled every minute (mobile-push.campaigns.sweeper.schedule): restarts planners whose worker died after a
 * checkpoint or whose lease expired, and marks campaigns completed once every delivery is terminal.
 */
final class SweepCampaignsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    public function handle(CampaignManager $campaigns): void
    {
        $campaigns->sweep();
    }

    public function displayName(): string
    {
        return 'mobile-push campaign sweep';
    }
}
