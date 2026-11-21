<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesConfig;

/** Shared parts of the broadcast channel commands. */
abstract class ChannelCommand extends LiveActivityCommand
{
    protected const MANAGEMENT_REMEDY = 'Channel management is its own operation in safe mode: outside production set live.allow=true and live.allow_channel_management=true on the connection (or run in production).';

    /** A channel by its local row id or by its APNs channel id (channel ids are not secret). */
    protected function findChannel(string $reference): BroadcastChannel
    {
        $query = BroadcastChannel::blank()->newQuery();
        $row = $query->where(static function ($q) use ($reference): void {
            $q->where('id', $reference)->orWhere('channel_id', $reference);
        })->first();

        if (! $row instanceof BroadcastChannel) {
            throw new InvalidInputException('No broadcast channel "'.$reference.'": list them with mobile-push:channels:list.');
        }

        return $row;
    }

    /** @return array{count: int, cap: int, apple_limit: int} */
    protected function capOf(BindingRef $binding): array
    {
        $count = BroadcastChannel::blank()->newQuery()
            ->where('app_id', $binding->appId)
            ->where('environment', $binding->environment->value)
            ->whereIn('state', ChannelState::counted())
            ->count();

        return ['count' => $count, 'cap' => $this->config()->channelCap, 'apple_limit' => LiveActivitiesConfig::APPLE_CHANNEL_LIMIT];
    }

    protected function capLine(BindingRef $binding): string
    {
        $cap = $this->capOf($binding);

        return sprintf(
            'Channels counted against the cap for %s (%s): %d of the local cap %d (Apple allows %s per app and environment).',
            $binding->appId, $binding->environment->value, $cap['count'], $cap['cap'], number_format($cap['apple_limit']),
        );
    }

    protected const UNKNOWN_CREATE = 'The outcome of the creation is unknown: the request left but the answer was lost, so a channel may exist at APNs that this system never learned about. '
        .'It will NOT retry blindly (every blind retry could create one more channel and burn the 10,000 limit). '
        .'Resolve it with: php artisan mobile-push:channels:sync (a single orphan is adopted automatically; with several, pass --adopt=<channel-id>).';
}
