<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
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
        $paths = self::migrationPaths();

        // Single file paths: other modules' migrations in the same directory are not loaded by accident.
        if ((bool) config('mobile-push.live_activities.enabled', false) && $app->bound('migrator')) {
            foreach ($paths as $path) {
                $app->make('migrator')->path($path);
            }
        }

        if ($app->runningInConsole()) {
            $published = [];
            foreach ($paths as $i => $path) {
                $published[$path] = $app->databasePath('migrations/'.date('Y_m_d_His', time() + $i).'_'.substr(basename($path), 18));
            }

            // publishes() is protected: a throwaway provider registers the tag without touching the main provider.
            (new class($app) extends ServiceProvider
            {
                /** @param  array<string, string>  $paths */
                public function publishTagged(array $paths, string $tag): void
                {
                    $this->publishes($paths, $tag);
                }
            })->publishTagged($published, self::PUBLISH_TAG);
        }
    }

    /** @return list<string> */
    public static function migrationPaths(): array
    {
        $dir = dirname(__DIR__, 3).'/database/migrations/';

        return array_map(static fn (string $file): string => $dir.$file, self::MIGRATIONS);
    }
}
