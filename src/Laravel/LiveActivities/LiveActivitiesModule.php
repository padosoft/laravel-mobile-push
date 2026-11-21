<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Integration\ModuleMigrations;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\ConsoleCommands;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\Rows;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;

/**
 * Live Activities: registry, transitions, broadcast channels. Needs the Privacy module (TokenIndex, TokenCipher)
 * and a PushDispatcher (the push manager). Migrations are loaded only when mobile-push.live_activities.enabled is true.
 */
final class LiveActivitiesModule implements Module
{
    public const PUBLISH_TAG = 'mobile-push-migrations-live-activities';

    public const MIGRATIONS = [
        '2026_11_20_000030_create_mobile_push_live_activities_table.php',
        '2026_11_20_000031_create_mobile_push_live_activity_push_to_start_tokens_table.php',
        '2026_11_20_000032_create_mobile_push_broadcast_channels_table.php',
    ];

    public static function register(Application $app): void
    {
        $app->singleton(LiveActivitiesConfig::class, static fn (): LiveActivitiesConfig => LiveActivitiesConfig::fromConfig());
        $app->singleton(Rows::class, static fn (Application $app): Rows => new Rows($app->make(ClockInterface::class)));
        $app->singleton(TypeRegistry::class, static fn (Application $app): TypeRegistry => new TypeRegistry($app));
        $app->singleton(ActivityRegistry::class, static fn (Application $app): ActivityRegistry => new ActivityRegistry(
            $app->make(TokenIndex::class),
            $app->make(TokenCipher::class),
            $app->make(TypeRegistry::class),
            $app->make(LiveActivitiesConfig::class),
            $app->make(Rows::class),
        ));
        $app->bindIf(ChannelClientFactory::class, static fn (Application $app): ChannelClientFactory => new DispatcherChannelClientFactory(
            $app->make(PushDispatcher::class), $app,
        ), true);
        $app->singleton(ChannelRegistry::class, static fn (Application $app): ChannelRegistry => new ChannelRegistry(
            $app->make(ChannelClientFactory::class),
            $app->make(TypeRegistry::class),
            $app->make(LiveActivitiesConfig::class),
            $app->make(Rows::class),
            new ChannelManagementGate($app->make(PushDispatcher::class), $app->make(TokenIndex::class)),
        ));
        $app->singleton(ActivityManager::class, static fn (Application $app): ActivityManager => new ActivityManager(
            $app->make(PushDispatcher::class),
            $app->make(ActivityRegistry::class),
            $app->make(ChannelRegistry::class),
            $app->make(TypeRegistry::class),
            $app->make(LiveActivitiesConfig::class),
            $app->make(Rows::class),
        ));
        $app->singleton(ActivityPruner::class, static fn (Application $app): ActivityPruner => new ActivityPruner(
            $app->make(ActivityRegistry::class), $app->make(LiveActivitiesConfig::class), $app->make(Rows::class),
        ));
    }

    public static function boot(Application $app): void
    {
        $enabled = (bool) config('mobile-push.live_activities.enabled', false);

        ModuleMigrations::wire($app, self::migrationPaths(), self::PUBLISH_TAG, $enabled);

        if ($app->runningInConsole() && $enabled) {
            ConsoleCommands::register();
        }
    }

    /** @return list<string> */
    public static function migrationPaths(): array
    {
        $dir = dirname(__DIR__, 3).'/database/migrations/';

        return array_map(static fn (string $file): string => $dir.$file, self::MIGRATIONS);
    }
}
