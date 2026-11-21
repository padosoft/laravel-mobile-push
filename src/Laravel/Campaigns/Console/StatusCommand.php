<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Padosoft\MobilePush\Laravel\Campaigns\CampaignProgress;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\Sleeper;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\StatusView;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\SystemSleeper;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\Watch;

final class StatusCommand extends CampaignCommand
{
    protected $signature = 'mobile-push:campaign:status
        {campaign : The campaign id}
        {--scope= : The tenant scope (default: the current scope)}
        {--watch= : Refresh every N seconds (1-3600) until the campaign is completed, cancelled or failed}
        {--json : Print the result as JSON (one compact line per refresh with --watch)}';

    protected $description = 'Show where a campaign is, from the ledger (exit 0 shown; 1 refused; 2 invalid input or campaign not found)';

    protected function perform(): int
    {
        $scope = $this->scope();
        $id = $this->campaignId();
        $watch = $this->intOption('watch', 1, 3600);
        $json = $this->wantsJson();

        $render = function () use ($scope, $id, $watch, $json): bool {
            $progress = $this->manager()->progress($scope, $id);
            if ($json) {
                $this->json(['ok' => true] + StatusView::data($progress), $watch === null);
            } else {
                foreach (StatusView::lines($progress) as $line) {
                    $this->line($line);
                }
            }

            return $watch === null || self::stops($progress);
        };

        if ($watch === null) {
            $render();

            return self::EXIT_OK;
        }

        $sleeper = $this->laravel->bound(Sleeper::class) ? $this->laravel->make(Sleeper::class) : new SystemSleeper;
        if (! $json) {
            $this->line(sprintf('Watching every %ds until the campaign is completed, cancelled or failed (Ctrl+C to stop).', $watch));
        }
        $first = true;
        Watch::run(function () use ($render, &$first, $json): bool {
            if (! $first && ! $json) {
                $this->line(str_repeat('-', 60));
            }
            $first = false;

            return $render();
        }, $sleeper, $watch);

        return self::EXIT_OK;
    }

    private static function stops(CampaignProgress $progress): bool
    {
        return $progress->campaign->status->isFinal() || $progress->campaign->status === CampaignStatus::Failed;
    }
}
