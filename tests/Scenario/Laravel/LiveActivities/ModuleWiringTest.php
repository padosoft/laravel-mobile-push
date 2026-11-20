<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

it('is off by default: no Live Activities migration is registered, and the publish tag lists the three stubs', function (): void {
    $paths = app('migrator')->paths();
    foreach (LiveActivitiesModule::migrationPaths() as $path) {
        expect($paths)->not->toContain($path)->and(is_file($path))->toBeTrue();
    }

    $published = ServiceProvider::pathsToPublish(null, LiveActivitiesModule::PUBLISH_TAG);
    expect(array_keys($published))->toBe(LiveActivitiesModule::migrationPaths());
    foreach ($published as $target) {
        expect($target)->toMatch('/\d{4}_\d{2}_\d{2}_\d{6}_create_mobile_push_(live_activit|broadcast_channel)/');
    }
});

it('the migrations run up and down, with table names from config', function (): void {
    config()->set('mobile-push.live_activities.table', 'la_custom');
    foreach (LiveActivitiesModule::migrationPaths() as $path) {
        (require $path)->up();
    }
    expect(Schema::hasTable('la_custom'))->toBeTrue()
        ->and(Schema::hasTable('mobile_push_live_activity_push_to_start_tokens'))->toBeTrue()
        ->and(Schema::hasTable('mobile_push_broadcast_channels'))->toBeTrue();

    foreach (array_reverse(LiveActivitiesModule::migrationPaths()) as $path) {
        (require $path)->down();
    }
    expect(Schema::hasTable('la_custom'))->toBeFalse();
});

it('the facade entry point resolves the manager singleton', function (): void {
    LiveWorld::install();
    expect(LiveActivity::manager())->toBe(app(ActivityManager::class));
});

it('a connection reconfigured to another app identity skips the transition without any request', function (): void {
    $world = LiveWorld::install();
    $activity = LiveWorld::rider(1)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('b1'), new ActivityReport(clientActivityId: 'BM-1'));

    $world->dispatcher->bundle = 'com.example.other';
    $world->kit->clock->advance(1);
    $result = LiveActivity::for($activity)->update(['eta' => 1])->send();

    expect($result->status)->toBe(SendStatus::Skipped)
        ->and($result->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($world->transport->requests())->toBe([]);
});
