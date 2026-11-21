<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Illuminate\Console\Command;

/** The operational commands, for the console module to register: `$this->commands(OpsCommands::all())`. */
final class OpsCommands
{
    /** @return list<class-string<Command>> */
    public static function all(): array
    {
        return [
            RelayCommand::class,
            SweepCommand::class,
            PruneDeliveriesCommand::class,
            PruneDevicesCommand::class,
            PruneLogsCommand::class,
            HealthCommand::class,
            CredentialsCheckCommand::class,
            ForgetCommand::class,
            ExportCommand::class,
            ProbeEnvironmentCommand::class,
            FcmTopicSubscriptionCommand::class,
        ];
    }
}
