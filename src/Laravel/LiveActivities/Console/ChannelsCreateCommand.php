<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelLimitReachedException;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;

final class ChannelsCreateCommand extends ChannelCommand
{
    protected $signature = 'mobile-push:channels:create
        {--connection= : The push connection (default: mobile-push.live_activities.binding)}
        {--scope= : The scope (default: the current one)}
        {--storage=none : Message storage policy, fixed at creation: none or recent (keeps the latest message up to 8 hours)}
        {--label= : A local label}
        {--type= : A Live Activity type key to record on the channel}';

    protected $description = 'Create a broadcast channel at APNs (iOS 18+)';

    protected function perform(): int
    {
        $policy = match (strtolower($this->optionString('storage') ?? 'none')) {
            'none' => StoragePolicy::NoStorage,
            'recent' => StoragePolicy::MostRecent,
            default => throw new InvalidInputException('--storage must be "none" or "recent".'),
        };
        $binding = $this->binding();

        $decision = $this->safeMode()->decide($binding, OperationKind::ChannelManagement);
        if (! $decision->allowed) {
            $wouldDo = sprintf('create a broadcast channel (storage %s) for %s (%s)', $policy->name, $binding->appId, $binding->environment->value);
            $exit = $this->reportDecision($decision, $wouldDo);
            if ($decision->isBlocked() && ! $this->wantsJson()) {
                $this->line('Remedy: '.self::MANAGEMENT_REMEDY);
            }

            return $exit;
        }

        try {
            $channel = $this->manager()->channels()->create($binding, $policy, $this->optionString('label'), $this->optionString('type'));
        } catch (ChannelLimitReachedException $e) {
            return $this->refuse($e->getMessage(), self::EXIT_FAILED);
        }

        if ($channel->state === ChannelState::Unknown) {
            return $this->refuse(self::UNKNOWN_CREATE.' Local row: '.$channel->id.'.', self::EXIT_FAILED);
        }

        $this->info('Channel created.');
        $this->line('  Local id:        '.$channel->id);
        $this->line('  APNs channel id: '.$channel->channel_id);
        $this->line('  Storage:         '.$channel->storage_policy->name);
        $this->line($this->capLine($binding));

        return self::EXIT_OK;
    }
}
