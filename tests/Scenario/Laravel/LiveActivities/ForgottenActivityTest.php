<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityPruner;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\ClientActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

/*
 * Nobody ended the activity. The server keeps three facts apart: its own local expiry (it stops sending), an end
 * accepted by APNs, and what the app reports the device shows.
 */
it('forgotten activity: past the local expiry the pruner marks it expired and later updates send nothing', function (): void {
    $world = LiveWorld::install(['local_expiry.hours' => 8]);
    $activity = LiveWorld::rider(4)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('f1'), new ActivityReport(clientActivityId: 'F-1'));
    $world->registry()->reportClientState($activity, ClientActivityState::Active);

    $world->kit->clock->advance(7 * 3600);
    expect(app(ActivityPruner::class)->prune()['expired'])->toBe(0);
    LiveActivity::for($activity)->update(['eta' => 1])->send();

    $world->kit->clock->advance(3600 + 1);
    expect(app(ActivityPruner::class)->prune())->toBe(['expired' => 1, 'deleted' => 0]);

    $row = $activity->fresh();
    expect($row->state)->toBe(ActivityState::Expired)
        ->and($row->closed_reason?->value)->toBe('local_expiry')
        ->and($row->end_accepted_at)->toBeNull()
        ->and($row->client_reported_state)->toBe(ClientActivityState::Active);

    $late = LiveActivity::for($activity)->update(['eta' => 0])->send();
    expect($late->status)->toBe(SendStatus::Skipped)->and($world->pushes())->toHaveCount(1);

    // The app later reports that the system ended it: recorded, and it changes nothing else.
    $world->registry()->reportClientState($activity->fresh(), ClientActivityState::Ended);
    $row = $activity->fresh();
    expect($row->client_reported_state)->toBe(ClientActivityState::Ended)
        ->and($row->state)->toBe(ActivityState::Expired)
        ->and($row->end_accepted_at)->toBeNull();

    // After the retention, the closed row is deleted.
    $world->kit->clock->advance(8 * 86_400);
    expect(app(ActivityPruner::class)->prune()['deleted'])->toBe(1)
        ->and(DB::table('mobile_push_live_activities')->count())->toBe(0);
});

it('an accepted end and the device state are separate facts: the app may report the activity still visible', function (): void {
    $world = LiveWorld::install();
    $activity = LiveWorld::rider(4)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('f2'), new ActivityReport(clientActivityId: 'F-2'));
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->end(['eta' => 0])->send();

    $world->kit->clock->advance(5);
    $world->registry()->reportClientState($activity->fresh(), ClientActivityState::Active, $world->kit->clock->now());
    // An older report arriving late does not overwrite the newer one.
    $world->registry()->reportClientState($activity->fresh(), ClientActivityState::Pending, $world->kit->clock->now()->modify('-1 minute'));

    $row = $activity->fresh();
    expect($row->state)->toBe(ActivityState::Ended)
        ->and($row->end_accepted_at)->not->toBeNull()
        ->and($row->client_reported_state)->toBe(ClientActivityState::Active);
});

it('with expiry counted from the last update, an accepted update extends the local expiry', function (): void {
    $world = LiveWorld::install(['local_expiry.hours' => 1, 'local_expiry.from' => 'last_update']);
    $activity = LiveWorld::rider(4)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('f3'), new ActivityReport(clientActivityId: 'F-3'));

    $world->kit->clock->advance(50 * 60);
    LiveActivity::for($activity)->update(['eta' => 1])->send();
    $world->kit->clock->advance(50 * 60);

    expect(app(ActivityPruner::class)->prune()['expired'])->toBe(0)
        ->and($activity->fresh()->state)->toBe(ActivityState::Active);
});
