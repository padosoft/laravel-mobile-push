<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The single rule for the migrations of every module: they are PUBLISHED, not auto-loaded.
 *
 * - Default (`mobile-push.migrations.autoload` = false): the module registers a publish tag and nothing else;
 *   `mobile-push:install` (or `vendor:publish --tag=...`) copies the files into database/migrations and `migrate` runs
 *   the copies.
 * - Opt-in (`autoload` = true): an enabled module registers its package files with the migrator and no publish tag
 *   exists. A copy already published (same file name after the date prefix) wins: the package file is then not
 *   registered, so a table is never created twice.
 *
 * Every migration is also idempotent (hasTable / hasColumn guards) as a second line of defence.
 */
final class ModuleMigrations
{
    /** Length of the "2026_11_20_000001_" prefix of a migration file name. */
    private const PREFIX = 18;

    public static function autoload(): bool
    {
        return (bool) config('mobile-push.migrations.autoload', false);
    }

    /**
     * @param  list<string>  $files  absolute paths of the package migration files, in run order
     */
    public static function wire(Application $app, array $files, string $tag, bool $moduleEnabled): void
    {
        if (self::autoload()) {
            if ($moduleEnabled && $app->bound('migrator')) {
                foreach ($files as $file) {
                    if (! self::hasPublishedCopy($app, $file)) {
                        // A single file path is accepted by the migrator: other modules' files are not loaded by accident.
                        $app->make('migrator')->path($file);
                    }
                }
            }

            return;
        }

        if (! $app->runningInConsole()) {
            return;
        }

        $targets = [];
        $now = time();
        foreach ($files as $i => $file) {
            $targets[$file] = $app->databasePath('migrations/'.date('Y_m_d_His', $now + $i).'_'.self::suffix($file));
        }

        // publishes() is protected: a throwaway provider registers the tag without touching the main provider.
        (new class($app) extends ServiceProvider
        {
            /** @param  array<string, string>  $paths */
            public function publishTagged(array $paths, string $tag): void
            {
                $this->publishes($paths, $tag);
            }
        })->publishTagged($targets, $tag);
    }

    /** The file name without the date prefix: what identifies a migration whatever date it was published on. */
    public static function suffix(string $file): string
    {
        return substr(basename($file), self::PREFIX);
    }

    /** Whether database/migrations already holds a published copy of this package file. */
    public static function hasPublishedCopy(Application $app, string $file): bool
    {
        $found = glob($app->databasePath('migrations').'/*_'.self::suffix($file));

        return $found !== false && $found !== [];
    }
}
