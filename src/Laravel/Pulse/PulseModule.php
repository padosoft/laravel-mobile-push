<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Pulse;
use Padosoft\MobilePush\Laravel\Module;

/**
 * Laravel Pulse integration: a recorder fed by the package events and the `mobile-push.pulse-card` Livewire card.
 *
 * Optional. Nothing here extends or type-hints a Pulse class: the recorder and the card live in files that PHP only
 * loads once Pulse is known to be installed (`pulseInstalled()`), so the package works without Pulse.
 *
 * Config (read with defaults): `mobile-push.pulse.enabled` (false), `mobile-push.pulse.sample_rate` (1.0),
 * `mobile-push.pulse.max_connections` (50).
 *
 * The host adds `<livewire:mobile-push.pulse-card cols="6" />` to its published Pulse dashboard view.
 * Views publish under the tag `mobile-push-views`.
 */
final class PulseModule implements Module
{
    public const PUBLISH_TAG = 'mobile-push-views';

    public const CARD_NAME = 'mobile-push.pulse-card';

    public const VIEW_NAMESPACE = 'mobile-push';

    private const PULSE = 'Laravel\Pulse\Pulse';

    private const LIVEWIRE = 'Livewire\Component';

    /** Whether the optional dependencies exist. Safe to call without Pulse installed. */
    public static function pulseInstalled(): bool
    {
        return class_exists(self::PULSE) && class_exists(self::LIVEWIRE);
    }

    public static function enabled(Application $app): bool
    {
        $config = $app->make('config');

        return PulseSettings::fromConfig($config)->enabled;
    }

    public static function register(Application $app): void
    {
        // Nothing to bind: the recorder is built lazily by boot() only when Pulse is present and the module is enabled.
    }

    public static function boot(Application $app): void
    {
        if (! self::pulseInstalled()) {
            return;
        }

        $views = dirname(__DIR__, 3).'/resources/views';

        if ($app->runningInConsole()) {
            (new class($app) extends ServiceProvider
            {
                /** @param  array<string, string>  $paths */
                public function publishTagged(array $paths, string $tag): void
                {
                    $this->publishes($paths, $tag);
                }
            })->publishTagged([$views.'/pulse' => $app->resourcePath('views/vendor/mobile-push/pulse')], self::PUBLISH_TAG);
        }

        if (! self::enabled($app)) {
            return;
        }

        self::registerViews($app, $views);
        self::registerCard($app);
        self::registerRecorder($app);
    }

    private static function registerViews(Application $app, string $views): void
    {
        $paths = [];
        $configured = $app->make('config')->get('view.paths', []);
        foreach (is_array($configured) ? $configured : [] as $path) {
            if (is_string($path) && is_dir($override = $path.'/vendor/'.self::VIEW_NAMESPACE)) {
                $paths[] = $override;
            }
        }
        $paths[] = $views;

        $app->make('view')->addNamespace(self::VIEW_NAMESPACE, $paths);
    }

    private static function registerCard(Application $app): void
    {
        $register = static function (object $livewire): void {
            if (method_exists($livewire, 'component')) {
                $livewire->component(self::CARD_NAME, Livewire\MobilePushCard::class);
            }
        };

        $app->afterResolving('livewire', $register);
        if ($app->resolved('livewire')) {
            $register($app->make('livewire'));
        }
    }

    private static function registerRecorder(Application $app): void
    {
        $config = $app->make('config');
        if (! filter_var($config->get('pulse.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if ($app->bound(MobilePushRecorder::class)) {
            return; // already wired (boot is idempotent)
        }

        $app->singleton(MobilePushRecorder::class, static function (Application $app): MobilePushRecorder {
            $config = $app->make('config');
            $pulse = $app->make(self::PULSE);

            return new MobilePushRecorder($pulse, $config);
        });

        $pulse = $app->make(self::PULSE);
        $pulse->register([MobilePushRecorder::class => true]);
    }
}
