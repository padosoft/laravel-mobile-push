<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Illuminate\Console\Application as Artisan;

/** The commands of the campaigns module, registered by CampaignsModule when it is enabled in the console. */
final class ConsoleCommands
{
    /** @return list<class-string<CampaignCommand>> */
    public static function all(): array
    {
        return [
            ListCommand::class,
            StatusCommand::class,
            PauseCommand::class,
            ResumeCommand::class,
            CancelCommand::class,
            ReconcileCommand::class,
            SweepCommand::class,
            PruneCommand::class,
        ];
    }

    /** Add the commands to every console application built from now on (the flag is read again when it is built). */
    public static function register(): void
    {
        Artisan::starting(static function (Artisan $artisan): void {
            if ((bool) config('mobile-push.campaigns.enabled', false)) {
                $artisan->resolveCommands(self::all());
            }
        });
    }
}
