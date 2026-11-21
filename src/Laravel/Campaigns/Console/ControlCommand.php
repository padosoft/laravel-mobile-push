<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignControl;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignNotFoundException;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStateException;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\StatusView;

/** pause, resume, cancel: the same flow, each with its own verb and its own words. */
abstract class ControlCommand extends CampaignCommand
{
    abstract protected function control(): CampaignControl;

    abstract protected function verb(): string;

    abstract protected function apply(string $scope, string $id): Campaign;

    /**
     * Extra lines printed after a change.
     *
     * @return list<string>
     */
    protected function afterLines(Campaign $campaign): array
    {
        return [];
    }

    /** Called with the campaign before the change; return false to stop (exit 1) without changing anything. */
    protected function confirmed(Campaign $campaign): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function extraJson(Campaign $campaign): array
    {
        return [];
    }

    final protected function perform(): int
    {
        $scope = $this->scope();
        $id = $this->campaignId();
        $before = $this->manager()->find($scope, $id)
            ?? throw new CampaignNotFoundException('No campaign '.$id.' in scope '.$scope.'.');

        $next = $before->status->after($this->control());
        if ($next === null) {
            throw new CampaignStateException(sprintf('Campaign %s is %s and cannot be %s: nothing was changed.', $before->name, $before->status->value, $this->verb()));
        }
        $changes = $next !== $before->status;
        if ($changes && ! $this->confirmed($before)) {
            return $this->wantsJson()
                ? $this->refuse('Not confirmed: pass --force to run without a prompt. Nothing was changed.', self::EXIT_REFUSED)
                : $this->declined();
        }

        $after = $changes ? $this->apply($scope, $id) : $before;

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'changed' => $changes, 'campaign' => StatusView::summary($after)] + $this->extraJson($after));

            return self::EXIT_OK;
        }
        if (! $changes) {
            $this->line(sprintf('Campaign %s is already %s: nothing to do.', $after->name, $after->status->value));

            return self::EXIT_OK;
        }
        $this->info(sprintf('Campaign %s (%s) is now %s.', $after->name, $after->id, strtoupper($after->status->value)));
        foreach ($this->afterLines($after) as $line) {
            $this->line($line);
        }

        return self::EXIT_OK;
    }

    private function declined(): int
    {
        $this->line('Not confirmed: nothing was changed.');

        return self::EXIT_REFUSED;
    }
}
