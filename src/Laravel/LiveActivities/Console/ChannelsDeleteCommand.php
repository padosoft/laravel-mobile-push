<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;

final class ChannelsDeleteCommand extends ChannelCommand
{
    protected $signature = 'mobile-push:channels:delete
        {channel : The local row id or the APNs channel id}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete a broadcast channel at APNs (irreversible)';

    protected function perform(): int
    {
        $channel = $this->findChannel($this->argString('channel'));
        $binding = $this->safeMode()->bindingOf($channel);

        if ($binding === null) {
            return $this->refuse('The connection this channel was created for no longer resolves to the same app identity: nothing was deleted.', self::EXIT_FAILED);
        }
        if ($channel->state === ChannelState::Deleted) {
            $this->line('Channel '.$channel->id.' is already deleted.');

            return self::EXIT_OK;
        }
        if ($channel->channel_id === null) {
            return $this->refuse('This channel has no APNs id (its creation outcome is unknown): nothing can be deleted.', self::EXIT_FAILED, 'Run mobile-push:channels:sync first.');
        }

        $decision = $this->safeMode()->decide($binding, OperationKind::ChannelManagement);
        if (! $decision->allowed) {
            $exit = $this->reportDecision($decision, 'delete broadcast channel '.$channel->channel_id);
            if ($decision->isBlocked()) {
                $this->line('Remedy: '.self::MANAGEMENT_REMEDY);
            }

            return $exit;
        }

        $open = PushActivity::blank()->newQuery()->open()->where('broadcast_channel_id', $channel->channel_id)->count();
        if (! (bool) $this->option('force')) {
            $warning = $open > 0 ? " {$open} open activit".($open === 1 ? 'y is' : 'ies are').' subscribed to it.' : '';
            if (! $this->confirm('Delete channel '.$channel->channel_id.' at APNs? This is irreversible and the id can never be reused.'.$warning, false)) {
                return $this->refuse('Aborted: nothing was deleted. Pass --force to skip the confirmation.', self::EXIT_FAILED);
            }
        }

        $deleted = $this->manager()->channels()->delete($channel);
        $this->info('Channel '.$deleted->channel_id.' deleted.');
        $this->line($this->capLine($binding));

        return self::EXIT_OK;
    }
}
