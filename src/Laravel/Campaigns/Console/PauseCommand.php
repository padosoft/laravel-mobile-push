<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignControl;

final class PauseCommand extends ControlCommand
{
    protected $signature = 'mobile-push:campaign:pause
        {campaign : The campaign id}
        {--scope= : The tenant scope (default: the current scope)}
        {--json : Print the result as JSON}';

    protected $description = 'Pause planning of a campaign (exit 0 done or already paused; 1 refused or not allowed in this state; 2 invalid input or campaign not found)';

    protected function control(): CampaignControl
    {
        return CampaignControl::Pause;
    }

    protected function verb(): string
    {
        return 'paused';
    }

    protected function apply(string $scope, string $id): Campaign
    {
        return $this->manager()->pause($scope, $id);
    }

    /** @return list<string> */
    protected function afterLines(Campaign $campaign): array
    {
        return ['No further slice is planned. Deliveries already written keep being sent. Resume with mobile-push:campaign:resume '.$campaign->id.'.'];
    }
}
