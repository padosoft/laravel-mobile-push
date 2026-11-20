<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

/*
 * The server cannot see the budget the device grants to an activity; it only refuses to spend priority 10 beyond a
 * configured number of attempts per activity and hour. Beyond it updates still go out, at priority 5.
 */
it('too many high-priority updates: beyond the hourly limit they go out at priority 5, and the counter resets after the hour', function (): void {
    $world = LiveWorld::install(['high_priority.max_per_hour' => 2]);
    $activity = LiveWorld::rider(2)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('h1'), new ActivityReport(clientActivityId: 'HP-1'));

    $results = [];
    foreach (range(1, 4) as $eta) {
        $world->kit->clock->advance(60);
        $results[] = LiveActivity::for($activity)->update(['eta' => $eta])->send();
    }

    expect(array_map(static fn ($r) => $r->headers['apns-priority'], $world->pushes()))->toBe(['10', '10', '5', '5'])
        ->and(array_map(static fn ($r) => $r->downgraded, $results))->toBe([false, false, true, true])
        ->and($results[2]->priority)->toBe(Priority::Normal);

    // An explicit low-priority update never consumes the allowance.
    $world->kit->clock->advance(60);
    LiveActivity::for($activity)->update(['eta' => 5])->lowPriority()->send();

    // One hour after the window opened, priority 10 is available again.
    $world->kit->clock->advance(3600);
    $fresh = LiveActivity::for($activity)->update(['eta' => 6])->send();

    expect(array_slice(array_map(static fn ($r) => $r->headers['apns-priority'], $world->pushes()), 4))->toBe(['5', '10'])
        ->and($fresh->downgraded)->toBeFalse()
        ->and($activity->fresh()->hp_count)->toBe(1);
});

it('the end of an activity is never downgraded by the update limiter', function (): void {
    $world = LiveWorld::install(['high_priority.max_per_hour' => 0]);
    $activity = LiveWorld::rider(2)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('h2'), new ActivityReport(clientActivityId: 'HP-2'));

    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->update(['eta' => 1])->send();
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->end(['eta' => 0])->send();

    expect(array_map(static fn ($r) => $r->headers['apns-priority'], $world->pushes()))->toBe(['5', '10']);
});
