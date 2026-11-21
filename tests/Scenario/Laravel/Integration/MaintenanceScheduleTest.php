<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Laravel\Integration\IntegrationModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * The daily maintenance: the prune commands of the ENABLED modules, plus the credentials check, at the configured
 * time. The oracle is the scheduler's own event list: expression, command, overlap and one-server flags.
 */

/** @return array<string, array{command: string, expression: string, overlap: bool, oneServer: bool}> by command name */
function mpMaintenance(Schedule $schedule): array
{
    $out = [];
    foreach ($schedule->events() as $event) {
        $name = (string) $event->description;
        if (! str_starts_with($name, IntegrationModule::MAINTENANCE_PREFIX)) {
            continue;
        }
        $out[substr($name, strlen(IntegrationModule::MAINTENANCE_PREFIX))] = [
            'command' => (string) $event->command,
            'expression' => $event->expression,
            'overlap' => $event->withoutOverlapping,
            'oneServer' => $event->onOneServer,
        ];
    }

    return $out;
}

function mpScheduled(array $config, ?Closure $before = null): array
{
    IntegrationWorld::install($config);
    if ($before !== null) {
        $before();
    }
    IntegrationModule::schedule(app(), $schedule = new Schedule);

    return mpMaintenance($schedule);
}

it('schedules only the credentials check when no optional module is on', function () {
    $scheduled = mpScheduled([]);

    expect(array_keys($scheduled))->toBe(['mobile-push:credentials:check'])
        ->and($scheduled['mobile-push:credentials:check']['expression'])->toBe('17 3 * * *')
        ->and($scheduled['mobile-push:credentials:check']['command'])->toContain('mobile-push:credentials:check')
        ->and($scheduled['mobile-push:credentials:check']['overlap'])->toBeTrue();
});

it('schedules the prunes of the durable profile and the devices module at the configured time, and nothing for modules that are off', function () {
    $scheduled = mpScheduled(['delivery.profile' => 'durable', 'devices.enabled' => true, 'maintenance.daily_at' => '04:45']);

    expect(array_keys($scheduled))->toBe(['mobile-push:deliveries:prune', 'mobile-push:devices:prune', 'mobile-push:credentials:check'])
        ->and(array_unique(array_column($scheduled, 'expression')))->toBe(['45 4 * * *']);
});

it('follows every module: database log sink, Live Activities and campaigns (campaigns only on the durable profile)', function () {
    $all = mpScheduled(['delivery.profile' => 'durable', 'devices.enabled' => true, 'log.sinks' => ['log', 'database'], 'live_activities.enabled' => true, 'campaigns.enabled' => true]);
    $light = mpScheduled(['delivery.profile' => 'light', 'devices.enabled' => false, 'log.sinks' => ['log'], 'live_activities.enabled' => true, 'campaigns.enabled' => true]);

    expect(array_keys($all))->toBe([
        'mobile-push:deliveries:prune', 'mobile-push:devices:prune', 'mobile-push:logs:prune',
        'mobile-push:live-activity:prune', 'mobile-push:campaign:prune', 'mobile-push:credentials:check',
    ])->and(array_keys($light))->toBe(['mobile-push:live-activity:prune', 'mobile-push:credentials:check']);
});

it('schedules nothing of this when maintenance.schedule is false, and leaves the alert and sweeper events alone', function () {
    $scheduled = mpScheduled(['maintenance.schedule' => false, 'delivery.profile' => 'durable', 'alerts.enabled' => true]);
    IntegrationModule::schedule(app(), $schedule = new Schedule);
    $names = array_map(static fn ($e): string => (string) $e->description, $schedule->events());

    expect($scheduled)->toBe([])
        ->and($names)->toBe([IntegrationModule::ALERTS_EVENT]);
});

it('is idempotent: scheduling twice adds each command once', function () {
    IntegrationWorld::install(['devices.enabled' => true]);
    $schedule = new Schedule;
    IntegrationModule::schedule(app(), $schedule);
    IntegrationModule::schedule(app(), $schedule);

    expect(array_keys(mpMaintenance($schedule)))->toBe(['mobile-push:devices:prune', 'mobile-push:credentials:check']);
});

it('uses onOneServer only when the default cache store is shared between servers', function (string $driver, bool $oneServer) {
    $scheduled = mpScheduled([], static function () use ($driver): void {
        config()->set('cache.default', 'probe');
        config()->set('cache.stores.probe', ['driver' => $driver]);
    });

    expect($scheduled['mobile-push:credentials:check']['oneServer'])->toBe($oneServer)
        ->and($scheduled['mobile-push:credentials:check']['overlap'])->toBeTrue();
})->with([
    'array' => ['array', false],
    'file' => ['file', false],
    'redis' => ['redis', true],
    'database' => ['database', true],
    'memcached' => ['memcached', true],
]);

it('refuses a daily_at that is not a time of day, naming the key', function (string $value) {
    expect(fn () => mpScheduled(['maintenance.daily_at' => $value]))
        ->toThrow(ConfigurationException::class, 'mobile-push.maintenance.daily_at must be a time of day as HH:MM');
})->with(['3am', '25:00', '03:60', '']);

it('is on the application\'s own scheduler by default', function () {
    $names = array_map(static fn ($e): string => (string) $e->description, app(Schedule::class)->events());

    expect($names)->toContain(IntegrationModule::MAINTENANCE_PREFIX.'mobile-push:credentials:check');
});
