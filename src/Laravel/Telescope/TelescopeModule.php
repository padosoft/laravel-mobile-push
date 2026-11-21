<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Telescope;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Laravel\Module;

/**
 * Laravel Telescope integration: one entry per completed batch and one per problematic result.
 *
 * The package's HTTP transport does not use Laravel's `Http` client, so Telescope's client-request watcher never sees
 * a push; this module records its own entries instead. They are recorded as Telescope "event" entries (the only
 * generic type with a screen in the UI that takes free-form content): see PushWatcher.
 *
 * Active only when Telescope is installed and enabled (`telescope.enabled`) and `mobile-push.telescope.enabled` is
 * not false. That key is `auto` (default: on when Telescope is installed and enabled), true, or false.
 *
 * Whenever Telescope is installed the module also keeps Telescope's own event watcher from capturing the package's
 * raw events (which carry provider reason text): the sanitised entries are the only trace.
 */
final class TelescopeModule implements Module
{
    private const TELESCOPE = 'Laravel\Telescope\Telescope';

    public static function telescopeInstalled(): bool
    {
        return class_exists(self::TELESCOPE);
    }

    /** `auto` (the default) and true mean on, false/"false"/"0"/"off" mean off. */
    public static function wanted(Repository $config): bool
    {
        $value = $config->get('mobile-push.telescope.enabled', 'auto');
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['false', '0', 'off', 'no'], true);
        }

        return $value !== false && $value !== 0 && $value !== null;
    }

    public static function active(Application $app): bool
    {
        if (! self::telescopeInstalled()) {
            return false;
        }
        $config = $app->make('config');

        return self::wanted($config) && filter_var($config->get('telescope.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function register(Application $app): void
    {
        // Nothing to bind: boot() wires the listeners.
    }

    public static function boot(Application $app): void
    {
        if (! self::telescopeInstalled()) {
            return;
        }

        PushWatcher::hideRawEvents();

        if (! self::active($app)) {
            return;
        }

        if ($app->bound(PushWatcher::class)) {
            return; // already wired (boot is idempotent)
        }

        $events = $app->make('events');
        $config = $app->make('config');
        $watcher = new PushWatcher($config);
        $app->instance(PushWatcher::class, $watcher);
        $watcher->register($events);
    }
}
