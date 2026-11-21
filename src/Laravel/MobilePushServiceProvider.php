<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel;

use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Clock\SystemClock;
use Psr\Clock\ClockInterface;

final class MobilePushServiceProvider extends ServiceProvider
{
    /**
     * Modules of the Laravel layer, in registration order. A class that does not exist yet is skipped,
     * so modules can be developed independently.
     *
     * @var list<string>
     */
    public const MODULES = [
        'Padosoft\MobilePush\Laravel\Privacy\PrivacyModule',
        'Padosoft\MobilePush\Laravel\Cache\CacheModule',
        'Padosoft\MobilePush\Laravel\Logging\LoggingModule',
        'Padosoft\MobilePush\Laravel\Devices\DevicesModule',
        'Padosoft\MobilePush\Laravel\Ledger\LedgerModule',
        'Padosoft\MobilePush\Laravel\Sending\SendingModule',
        'Padosoft\MobilePush\Laravel\Queue\QueueModule',
        'Padosoft\MobilePush\Laravel\Notifications\NotificationsModule',
        'Padosoft\MobilePush\Laravel\Policies\PoliciesModule',
        'Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule',
        'Padosoft\MobilePush\Laravel\Campaigns\CampaignsModule',
        'Padosoft\MobilePush\Laravel\Receipts\ReceiptsModule',
        'Padosoft\MobilePush\Laravel\Health\HealthModule',
        'Padosoft\MobilePush\Laravel\Pulse\PulseModule',
        'Padosoft\MobilePush\Laravel\Telescope\TelescopeModule',
        'Padosoft\MobilePush\Laravel\Integration\IntegrationModule',
        'Padosoft\MobilePush\Laravel\Testing\TestingModule',
        'Padosoft\MobilePush\Laravel\Console\ConsoleModule',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/mobile-push.php', 'mobile-push');

        $this->app->bindIf(ClockInterface::class, SystemClock::class);

        foreach ($this->modules() as $module) {
            $module::register($this->app);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/mobile-push.php' => $this->app->configPath('mobile-push.php'),
            ], 'mobile-push-config');
        }

        foreach ($this->modules() as $module) {
            $module::boot($this->app);
        }
    }

    /**
     * @return list<class-string<Module>>
     */
    private function modules(): array
    {
        $modules = [];
        foreach (self::candidates() as $module) {
            if (class_exists($module) && is_subclass_of($module, Module::class)) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * @return list<string> class names that may or may not exist yet
     */
    private static function candidates(): array
    {
        return self::MODULES;
    }
}
