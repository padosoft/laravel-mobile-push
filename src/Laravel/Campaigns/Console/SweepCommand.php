<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

final class SweepCommand extends CampaignCommand
{
    protected $signature = 'mobile-push:campaign:sweep
        {--json : Print the result as JSON}';

    protected $description = 'Restart stalled planner chains and complete finished campaigns, every scope; safe to schedule (exit 0 done; 1 refused; 2 invalid input)';

    protected function perform(): int
    {
        $result = $this->manager()->sweep();

        if ($this->wantsJson()) {
            $this->json(['ok' => true] + $result);

            return self::EXIT_OK;
        }
        $this->info(sprintf('Restarted %d stalled planner chain(s); completed %d finished campaign(s).', $result['restarted'], $result['completed']));

        return self::EXIT_OK;
    }
}
