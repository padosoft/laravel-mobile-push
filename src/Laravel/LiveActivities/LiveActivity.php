<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;

/**
 * Static entry point:
 *
 *     LiveActivity::start(DeliveryActivity::class, attributes: [...], state: [...])->alert('Rider assigned', 'Tap to follow')->to($user)->send();
 *     LiveActivity::for($activity)->update(['eta' => 5])->send();
 *     LiveActivity::for($activity)->end(['eta' => 0])->dismissAt(now()->addMinutes(15))->send();
 *     LiveActivity::broadcast($channel)->update(['score' => '2-1'])->send();
 *
 * Every call goes to the ActivityManager singleton of the container.
 */
final class LiveActivity
{
    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>  $state
     */
    public static function start(LiveActivityType|string $type, array $attributes, array $state): PendingStart
    {
        return self::manager()->start($type, $attributes, $state);
    }

    public static function for(PushActivity|string $activity): ActivityHandle
    {
        return self::manager()->for($activity);
    }

    public static function broadcast(BroadcastChannel|string $channel, ?string $connection = null, ?string $scope = null): ChannelHandle
    {
        return self::manager()->broadcast($channel, $connection, $scope);
    }

    public static function replay(ActivityTicket $ticket): TransitionResult
    {
        return self::manager()->replay($ticket);
    }

    public static function flush(PushActivity|BroadcastChannel $row): TransitionResult
    {
        return self::manager()->flush($row);
    }

    public static function manager(): ActivityManager
    {
        return app(ActivityManager::class);
    }
}
