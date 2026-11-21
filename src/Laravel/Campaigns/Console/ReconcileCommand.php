<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

final class ReconcileCommand extends CampaignCommand
{
    protected $signature = 'mobile-push:campaign:reconcile
        {campaign : The campaign id}
        {--scope= : The tenant scope (default: the current scope)}
        {--json : Print the result as JSON}';

    protected $description = 'Check planned destinations against the deliveries in the ledger; never corrects (exit 0 consistent; 1 difference found or refused; 2 invalid input or campaign not found)';

    protected function perform(): int
    {
        $report = $this->manager()->reconcile($this->scope(), $this->campaignId());

        if ($this->wantsJson()) {
            $this->json([
                'ok' => $report->clean(),
                'campaign' => $report->campaignId,
                'planned' => $report->planned,
                'ledger' => $report->ledger,
                'effect' => $report->effect,
                'difference' => $report->difference(),
                'stray' => $report->stray(),
                'slice_in_progress' => $report->sliceInProgress,
                'explained' => $report->explained(),
                'message' => $report->describe(),
            ]);

            return $report->clean() ? self::EXIT_OK : self::EXIT_REFUSED;
        }

        if ($report->clean()) {
            $this->info($report->describe());

            return self::EXIT_OK;
        }
        $this->error($report->describe());
        $this->line(sprintf('Difference (ledger - planned): %d; deliveries of this campaign outside its effect: %d.', $report->difference(), $report->stray()));
        $this->line('Nothing was corrected: this command only reports.');

        return self::EXIT_REFUSED;
    }
}
