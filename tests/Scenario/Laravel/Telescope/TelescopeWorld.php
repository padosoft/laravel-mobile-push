<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Telescope;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Laravel\Telescope\Watchers\EventWatcher;
use Padosoft\MobilePush\Laravel\Telescope\TelescopeModule;

/**
 * Adds Telescope (SQLite in memory, its own migration, only the generic event watcher on, like a real host that
 * records events) to the Testbench application and boots the Telescope module.
 */
final class TelescopeWorld
{
    /** @param  array<string, mixed>  $config */
    public static function build(Application $app, array $config = []): void
    {
        // mergeConfigFrom() is shallow: seed the whole `telescope` array.
        $telescope = require dirname(__DIR__, 4).'/vendor/laravel/telescope/config/telescope.php';
        $telescope['enabled'] = true;
        $telescope['watchers'] = [EventWatcher::class => ['enabled' => true, 'ignore' => []]];
        $app['config']->set('telescope', $telescope);
        foreach ($config as $key => $value) {
            $app['config']->set($key, $value);
        }

        $app->register(TelescopeServiceProvider::class);

        $migration = include dirname(__DIR__, 4).'/vendor/laravel/telescope/database/migrations/2018_08_08_100000_create_telescope_entries_table.php';
        $migration->up();

        Telescope::flushEntries();
        Telescope::startRecording(false);

        TelescopeModule::boot($app);
    }

    /** Stores what the request would store on termination and returns the event entries of the package. */
    public static function stored(Application $app, ?string $tag = null): Collection
    {
        $repository = $app->make(EntriesRepository::class);
        Telescope::store($repository);

        return collect($repository->get(EntryType::EVENT, (new EntryQueryOptions)->tag($tag)->limit(500)))
            ->map(fn ($entry) => (object) ['type' => $entry->type, 'content' => $entry->content, 'tags' => DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries_tags')->where('entry_uuid', $entry->id)->pluck('tag')->all()])
            ->filter(fn ($entry) => str_starts_with((string) ($entry->content['name'] ?? ''), 'mobile-push.'))
            ->values();
    }
}
