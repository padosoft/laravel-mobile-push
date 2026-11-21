<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Illuminate\Console\Application as Artisan;

/** The commands of the Live Activities module, registered by LiveActivitiesModule when it is enabled in the console. */
final class ConsoleCommands
{
    /** @return list<class-string<LiveActivityCommand>> */
    public static function all(): array
    {
        return [
            StartCommand::class,
            UpdateCommand::class,
            EndCommand::class,
            ListCommand::class,
            FlushCommand::class,
            PruneCommand::class,
            ChannelsListCommand::class,
            ChannelsCreateCommand::class,
            ChannelsDeleteCommand::class,
            ChannelsSyncCommand::class,
            ChannelsPublishCommand::class,
        ];
    }

    /** Add the commands to every console application built from now on (the flag is read again when it is built). */
    public static function register(): void
    {
        Artisan::starting(static function (Artisan $artisan): void {
            if ((bool) config('mobile-push.live_activities.enabled', false)) {
                $artisan->resolveCommands(self::all());
            }
        });
    }
}
