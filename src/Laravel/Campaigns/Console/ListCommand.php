<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\StatusView;

final class ListCommand extends CampaignCommand
{
    protected $signature = 'mobile-push:campaign:list
        {--scope= : The tenant scope (default: the current scope)}
        {--status= : Only campaigns in this status (draft, planning, running, paused, completed, cancelled, failed)}
        {--limit=20 : How many campaigns to show, newest first (1-200)}
        {--json : Print the result as JSON}';

    protected $description = 'List campaigns, newest first (exit 0 done; 1 refused; 2 invalid input)';

    protected function perform(): int
    {
        $scope = $this->scope();
        $limit = $this->intOption('limit', 1, 200) ?? 20;
        $statusName = $this->optionString('status');
        $status = $statusName === null ? null : (CampaignStatus::tryFrom(strtolower($statusName))
            ?? throw new InvalidInputException('--status must be one of: '.implode(', ', CampaignStatus::values(...CampaignStatus::cases())).'.'));

        $campaigns = $this->manager()->list($scope, $status, $limit);

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'scope' => $scope, 'campaigns' => array_map(StatusView::summary(...), $campaigns)]);

            return self::EXIT_OK;
        }
        if ($campaigns === []) {
            $this->line('No campaigns in scope '.$scope.($status === null ? '' : ' with status '.$status->value).'.');

            return self::EXIT_OK;
        }
        $this->table(
            ['Id', 'Name', 'Status', 'Connection', 'Planned', 'Started', 'Finished'],
            array_map(static fn (Campaign $c): array => [
                $c->id, $c->name, $c->status->value, $c->binding->name, number_format($c->planned),
                $c->startedAt?->format('Y-m-d H:i:s') ?? '-', $c->finishedAt?->format('Y-m-d H:i:s') ?? '-',
            ], $campaigns),
        );

        return self::EXIT_OK;
    }
}
