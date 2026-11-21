<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Integration\IntegrationModule;
use Padosoft\MobilePush\Laravel\Integration\SendLiveActivityTransitionJob;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once dirname(__DIR__).'/LiveActivities/LiveWorld.php';

/*
 * Live Activities through the queue and the scheduler: a transition queued as SendLiveActivityTransitionJob is
 * applied in dispatch order (a ticket made stale by a later end sends nothing), and the scheduler flushes the
 * coalesced same-second updates every minute.
 */
function mpStartedDelivery(LiveWorld $world): PushActivity
{
    $rider = LiveWorld::rider(7);
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('p1'));
    $start = LiveActivity::start(DeliveryActivity::class, ['order' => '42'], ['eta' => 20])->alert('Order confirmed', 'Follow your rider')->inputPushToken()->to($rider)->send();
    $rider->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('a1'), new ActivityReport(
        clientActivityId: 'B3D2C1A0-0000-4000-8000-000000000001',
        pushToStartToken: LiveWorld::pushToStart('p1'),
    ));

    return $start->activities[0]->fresh();
}

function mpIntegrationBooted(): void
{
    if (! in_array(IntegrationModule::class, MobilePushServiceProvider::MODULES, true)) {
        IntegrationModule::register(app());
        IntegrationModule::boot(app());
    }
}

it('a queued update job applies its transition, and a job made stale by a later end sends nothing', function () {
    $world = LiveWorld::install();
    $activity = mpStartedDelivery($world);

    $world->kit->clock->advance(30);
    $update = SendLiveActivityTransitionJob::for(LiveActivity::for($activity)->update(['eta' => 10])->ticket());
    $stale = SendLiveActivityTransitionJob::for(LiveActivity::for($activity)->update(['eta' => 5])->ticket());
    $payload = serialize($stale);   // what the queue stores (encrypted on a real queue)

    $applied = app()->call([$update, 'handle']);
    $world->kit->clock->advance(30);
    LiveActivity::for($activity)->end(['eta' => 0])->send();
    $before = count($world->pushes());

    $world->kit->clock->advance(5);
    $late = app()->call([unserialize($payload), 'handle']);

    expect($applied->status)->toBe(SendStatus::Accepted)
        ->and($late->outcome)->toBe(TransitionOutcome::Skipped)
        ->and($late->status)->toBe(SendStatus::Skipped)
        ->and($world->pushes())->toHaveCount($before)
        ->and($payload)->not->toContain(LiveWorld::activityToken('a1')->value());
});

it('the scheduler flushes pending Live Activity transitions every minute when the module is on', function () {
    $world = LiveWorld::install();
    mpIntegrationBooted();
    $activity = mpStartedDelivery($world);
    $world->kit->clock->advance(10);

    // Two updates in the same second: the second one waits for the next second.
    LiveActivity::for($activity)->update(['eta' => 9])->send();
    $coalesced = LiveActivity::for($activity)->update(['eta' => 8])->send();
    $sentBefore = count($world->pushes());
    $world->kit->clock->advance(1);

    $events = collect(app(Schedule::class)->events())->filter(static fn ($e): bool => $e->description === IntegrationModule::LIVE_ACTIVITIES_EVENT);
    expect($coalesced->outcome)->toBe(TransitionOutcome::Deferred)->and($events)->toHaveCount(1);
    $events->first()->run(app());

    $pushes = $world->pushes();
    expect($pushes)->toHaveCount($sentBefore + 1)
        ->and(LiveWorld::aps(end($pushes))['content-state'])->toBe(['eta' => 8]);
});

it('schedules nothing for Live Activities or alerts while those modules are off', function () {
    config()->set('mobile-push.live_activities.enabled', false);
    config()->set('mobile-push.alerts.enabled', false);
    config()->set('mobile-push.maintenance.schedule', false);   // the daily maintenance has its own scenarios
    $schedule = new Schedule;
    IntegrationModule::schedule(app(), $schedule);

    expect($schedule->events())->toBe([]);

    config()->set('mobile-push.alerts.enabled', true);
    IntegrationModule::schedule(app(), $schedule);
    IntegrationModule::schedule(app(), $schedule);   // idempotent
    expect(array_map(static fn ($e) => $e->description, $schedule->events()))->toBe([IntegrationModule::ALERTS_EVENT])
        ->and($schedule->events()[0]->expression)->toBe('* * * * *');
});
