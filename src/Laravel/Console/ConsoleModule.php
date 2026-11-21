<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Laravel\Console\Commands\CircuitCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\ConnectionsCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\DoctorCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\ExplainCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\InstallCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\PreviewCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\SendCommand;
use Padosoft\MobilePush\Laravel\Console\Commands\SimulatorCommand;
use Padosoft\MobilePush\Laravel\Console\Support\StdinReader;
use Padosoft\MobilePush\Laravel\Console\Support\XcrunRunner;
use Padosoft\MobilePush\Laravel\Module;

/**
 * The `mobile-push:*` Artisan commands: install, doctor, send, preview, explain, simulator, connections, circuit, plus the
 * operational commands of Console\Ops\OpsCommands when that class exists. Registered only when running in the console.
 */
final class ConsoleModule implements Module
{
    public const COMMANDS = [
        InstallCommand::class,
        DoctorCommand::class,
        SendCommand::class,
        PreviewCommand::class,
        ExplainCommand::class,
        SimulatorCommand::class,
        ConnectionsCommand::class,
        CircuitCommand::class,
    ];

    private const OPS = 'Padosoft\MobilePush\Laravel\Console\Ops\OpsCommands';

    public static function register(Application $app): void
    {
        $app->singleton(StdinReader::class, static fn (): StdinReader => new StdinReader);
        $app->singleton(XcrunRunner::class, static fn (): XcrunRunner => new XcrunRunner);
    }

    public static function boot(Application $app): void
    {
        if (! $app->runningInConsole()) {
            return;
        }

        $commands = self::commands();
        Artisan::starting(static function (Artisan $artisan) use ($commands): void {
            $artisan->resolveCommands($commands);
        });
    }

    /** @return list<class-string> */
    public static function commands(): array
    {
        $commands = self::COMMANDS;

        if (class_exists(self::OPS) && method_exists(self::OPS, 'all')) {
            $extra = call_user_func([self::OPS, 'all']);
            foreach (is_array($extra) ? $extra : [] as $class) {
                if (is_string($class) && class_exists($class) && ! in_array($class, $commands, true)) {
                    $commands[] = $class;
                }
            }
        }

        return array_values($commands);
    }
}
