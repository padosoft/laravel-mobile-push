<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignControl;

final class ResumeCommand extends ControlCommand
{
    protected $signature = 'mobile-push:campaign:resume
        {campaign : The campaign id}
        {--scope= : The tenant scope (default: the current scope)}
        {--json : Print the result as JSON}';

    protected $description = 'Resume a paused or failed campaign from its stored cursor (exit 0 done or already running; 1 refused or not allowed in this state; 2 invalid input or campaign not found)';

    protected function control(): CampaignControl
    {
        return CampaignControl::Resume;
    }

    protected function verb(): string
    {
        return 'resumed';
    }

    protected function apply(string $scope, string $id): Campaign
    {
        return $this->manager()->resume($scope, $id);
    }

    /** @return list<string> */
    protected function afterLines(Campaign $campaign): array
    {
        return ['Planning continues from the stored cursor; nothing is recomputed from zero.'];
    }
}
