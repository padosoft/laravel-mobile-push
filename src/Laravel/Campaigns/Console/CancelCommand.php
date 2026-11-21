<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignControl;

final class CancelCommand extends ControlCommand
{
    protected $signature = 'mobile-push:campaign:cancel
        {campaign : The campaign id}
        {--scope= : The tenant scope (default: the current scope)}
        {--force : Do not ask for confirmation}
        {--json : Print the result as JSON (needs --force)}';

    protected $description = 'Cancel a campaign: nothing more is planned or sent; what was already accepted or is in flight cannot be undone (exit 0 done or already cancelled; 1 declined, refused or not allowed in this state; 2 invalid input or campaign not found)';

    protected function control(): CampaignControl
    {
        return CampaignControl::Cancel;
    }

    protected function verb(): string
    {
        return 'cancelled';
    }

    protected function confirmed(Campaign $campaign): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }
        if ($this->wantsJson()) {
            return false;
        }
        $this->warn(sprintf('Cancelling campaign %s (%s), now %s.', $campaign->name, $campaign->id, $campaign->status->value));
        $this->line('Every delivery not yet sent will be cancelled. This CANNOT be undone for anything already accepted by the provider or in flight right now (a push in flight may still be delivered); the cancelled deliveries are not sent later either.');

        return $this->confirm('Cancel this campaign?', false);
    }

    protected function apply(string $scope, string $id): Campaign
    {
        return $this->manager()->cancel($scope, $id);
    }

    /** @return array<string, mixed> */
    protected function extraJson(Campaign $campaign): array
    {
        $p = $this->manager()->progress($campaign->scope, $campaign->id);

        return ['cancelled' => $p->cancelled, 'not_undone' => ['accepted' => $p->accepted, 'unknown_outcome' => $p->unknown, 'in_flight' => $p->inFlight]];
    }

    /** @return list<string> */
    protected function afterLines(Campaign $campaign): array
    {
        $p = $this->manager()->progress($campaign->scope, $campaign->id);

        return [
            sprintf('%s deliveries were cancelled before being sent.', number_format($p->cancelled)),
            sprintf('Cannot be undone: %s already accepted, %s unknown outcomes (they may have been accepted), %s in flight.', number_format($p->accepted), number_format($p->unknown), number_format($p->inFlight)),
        ];
    }
}
