<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel;

use Illuminate\Support\ServiceProvider;

final class MobilePushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/mobile-push.php', 'mobile-push');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/mobile-push.php' => $this->app->configPath('mobile-push.php'),
            ], 'mobile-push-config');
        }
    }
}
