<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/LiveWorld.php';

/*
 * A food delivery followed on the Lock Screen: the order is confirmed, the server starts the activity remotely, the
 * rider moves, the order is delivered. Every oracle is what the fake transport received or what the table holds.
 */
beforeEach(function (): void {
    $this->world = LiveWorld::install();
    $this->rider = LiveWorld::rider(7);
    $this->rider->registerPushToStartToken($this->world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('p1'));
});

/** Remote start, then the app reports the update token of the started activity. */
function startedDelivery(LiveWorld $world, mixed $rider): PushActivity
{
    $start = LiveActivity::start(DeliveryActivity::class, ['order' => '42'], ['eta' => 20, 'status' => 'confirmed'])
        ->alert('Order confirmed', 'Tap to follow your rider')
        ->inputPushToken()
        ->to($rider)
        ->send();

    $rider->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('a1'), new ActivityReport(
        clientActivityId: 'B3D2C1A0-0000-4000-8000-000000000001',
        pushToStartToken: LiveWorld::pushToStart('p1'),
    ));

    return $start->activities[0]->fresh();
}

it('a delivery starts: one start to the push-to-start token with the Swift type and an alert, a requested row that becomes active', function (): void {
    $start = LiveActivity::start(DeliveryActivity::class, ['order' => '42'], ['eta' => 20])
        ->alert('Order confirmed', 'Tap to follow your rider')
        ->inputPushToken()
        ->to($this->rider)
        ->send();

    $pushes = $this->world->pushes();
    expect($pushes)->toHaveCount(1)
        ->and($pushes[0]->url)->toEndWith('/3/device/'.LiveWorld::pushToStart('p1')->value())
        ->and($pushes[0]->headers['apns-push-type'])->toBe('liveactivity')
        ->and($pushes[0]->headers['apns-topic'])->toBe(SendKit::BUNDLE.'.push-type.liveactivity');

    $aps = LiveWorld::aps($pushes[0]);
    expect($aps['event'])->toBe('start')
        ->and($aps['attributes-type'])->toBe('DeliveryAttributes')
        ->and($aps['attributes'])->toBe(['order' => '42'])
        ->and($aps['content-state'])->toBe(['eta' => 20])
        ->and($aps['input-push-token'])->toBe(1)
        ->and($aps['alert'])->toBe(['title' => 'Order confirmed', 'body' => 'Tap to follow your rider'])
        ->and($aps['timestamp'])->toBe($this->world->second());

    expect($start->count())->toBe(1)->and($start->accepted())->toBe(1);
    $row = $start->activities[0];
    expect($row->state)->toBe(ActivityState::Requested)
        ->and($row->start_status)->toBe('accepted')
        ->and($row->swift_name)->toBe('DeliveryAttributes')
        ->and($row->type_version)->toBe(2)
        ->and($row->type_key)->toBe('DeliveryAttributes')
        ->and($row->update_token)->toBeNull();

    // The app reports the update token of the activity it started (with the push-to-start token it answers).
    $reported = $this->rider->reportLiveActivityToken($this->world->binding(), DeliveryActivity::class, LiveWorld::activityToken('a1'), new ActivityReport(
        clientActivityId: 'B3D2C1A0-0000-4000-8000-000000000001',
        pushToStartToken: LiveWorld::pushToStart('p1'),
    ));

    expect($reported->id)->toBe($row->id)
        ->and($reported->state)->toBe(ActivityState::Active)
        ->and($reported->token_generation)->toBe(1)
        ->and($reported->destination()?->value())->toBe(LiveWorld::activityToken('a1')->value())
        ->and(DB::table('mobile_push_live_activities')->count())->toBe(1);
});

it('rider on the way: three updates over a minute carry strictly increasing timestamps and the stored state follows', function (): void {
    $activity = startedDelivery($this->world, $this->rider);

    $timestamps = [];
    foreach ([15, 10, 5] as $eta) {
        $this->world->kit->clock->advance(20);
        $result = LiveActivity::for($activity)->update(['eta' => $eta, 'status' => 'on_the_way'])->send();

        expect($result->outcome)->toBe(TransitionOutcome::Sent)
            ->and($result->status)->toBe(SendStatus::Accepted)
            ->and($result->timestamp)->toBe($this->world->second());
        $timestamps[] = $result->timestamp;

        $stored = $activity->fresh();
        expect($stored->last_content_state)->toBe(['eta' => $eta, 'status' => 'on_the_way'])
            ->and($stored->last_timestamp)->toBe($this->world->second());
    }

    $updates = array_slice($this->world->pushes(), 1);
    expect($updates)->toHaveCount(3);
    foreach ($updates as $i => $request) {
        $aps = LiveWorld::aps($request);
        expect($request->url)->toEndWith('/3/device/'.LiveWorld::activityToken('a1')->value())
            ->and($request->headers['apns-push-type'])->toBe('liveactivity')
            ->and($aps['event'])->toBe('update')
            ->and($aps['timestamp'])->toBe($timestamps[$i])
            ->and($aps['content-state']['eta'])->toBe([15, 10, 5][$i])
            ->and($aps)->not->toHaveKey('attributes-type');
    }
    expect($timestamps[0])->toBeLessThan($timestamps[1])->and($timestamps[1])->toBeLessThan($timestamps[2]);
});

it('two updates in the same second: one request now, the latest state coalesced into one request in the next real second', function (): void {
    $activity = startedDelivery($this->world, $this->rider);
    $this->world->kit->clock->advance(5);
    $second = $this->world->second();

    $first = LiveActivity::for($activity)->update(['eta' => 9])->send();
    $second1 = LiveActivity::for($activity)->update(['eta' => 8])->send();
    $second2 = LiveActivity::for($activity)->update(['eta' => 7])->send();

    expect($first->outcome)->toBe(TransitionOutcome::Sent)
        ->and($second1->outcome)->toBe(TransitionOutcome::Deferred)
        ->and($second1->status)->toBe(SendStatus::Deferred)
        ->and($second1->nextAttemptAt?->getTimestamp())->toBe($second + 1)
        ->and($second2->outcome)->toBe(TransitionOutcome::Deferred)
        ->and(array_slice($this->world->pushes(), 1))->toHaveCount(1);

    // Flushing within the same second sends nothing: the next second has not come and none is invented.
    expect(LiveActivity::flush($activity)->outcome)->toBe(TransitionOutcome::Deferred)
        ->and(array_slice($this->world->pushes(), 1))->toHaveCount(1);

    $this->world->kit->clock->advance(1);
    $flushed = $this->world->manager()->flushDue();

    $updates = array_slice($this->world->pushes(), 1);
    expect($flushed)->toHaveCount(1)
        ->and($updates)->toHaveCount(2)
        ->and(LiveWorld::aps($updates[0])['timestamp'])->toBe($second)
        ->and(LiveWorld::aps($updates[0])['content-state'])->toBe(['eta' => 9])
        ->and(LiveWorld::aps($updates[1])['timestamp'])->toBe($second + 1)
        ->and(LiveWorld::aps($updates[1])['content-state'])->toBe(['eta' => 7]);

    $stored = $activity->fresh();
    expect($stored->last_content_state)->toBe(['eta' => 7])
        ->and($stored->last_timestamp)->toBe($second + 1)
        ->and($stored->pending_seq)->toBeNull();

    // Nothing more is pending.
    expect($this->world->manager()->flushDue())->toBe([]);
});

it('order delivered: the end carries the dismissal date, the row is ended, and a stale update job sends nothing', function (): void {
    $activity = startedDelivery($this->world, $this->rider);

    // A worker captured an update ticket before the order was delivered; the job runs late.
    $this->world->kit->clock->advance(30);
    $staleJob = LiveActivity::for($activity)->update(['eta' => 2])->ticket();

    $this->world->kit->clock->advance(30);
    $dismissAt = $this->world->kit->clock->now()->modify('+15 minutes');
    $end = LiveActivity::for($activity)->end(['eta' => 0, 'status' => 'delivered'])->dismissAt($dismissAt)->send();

    $pushes = $this->world->pushes();
    $aps = LiveWorld::aps(end($pushes));
    expect($end->status)->toBe(SendStatus::Accepted)
        ->and($aps['event'])->toBe('end')
        ->and($aps['dismissal-date'])->toBe($dismissAt->getTimestamp())
        ->and($aps['content-state'])->toBe(['eta' => 0, 'status' => 'delivered']);

    $row = $activity->fresh();
    expect($row->state)->toBe(ActivityState::Ended)
        ->and($row->end_accepted_at)->not->toBeNull()
        ->and($row->dismissal_at?->getTimestamp())->toBe($dismissAt->getTimestamp());

    $count = count($pushes);
    $this->world->kit->clock->advance(5);
    $late = LiveActivity::replay(\Padosoft\MobilePush\Laravel\LiveActivities\ActivityTicket::fromArray($staleJob->toArray()));
    $direct = LiveActivity::for($activity)->update(['eta' => 1])->send();

    expect($late->outcome)->toBe(TransitionOutcome::Skipped)
        ->and($late->status)->toBe(SendStatus::Skipped)
        ->and($direct->status)->toBe(SendStatus::Skipped)
        ->and($direct->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($this->world->pushes())->toHaveCount($count)
        ->and($activity->fresh()->last_content_state)->toBe(['eta' => 0, 'status' => 'delivered']);
});

it('a start with no push-to-start token sends nothing and creates no row; a start without an alert is refused before the network', function (): void {
    $nobody = LiveWorld::rider(99);
    $none = LiveActivity::start(DeliveryActivity::class, [], ['eta' => 1])->alert('Hi')->to($nobody)->send();

    expect($none->count())->toBe(0)->and($this->world->pushes())->toBe([]);
    expect(fn () => LiveActivity::start(DeliveryActivity::class, [], ['eta' => 1])->to($this->rider)->send())
        ->toThrow(\Padosoft\MobilePush\Core\Exceptions\InvalidMessageException::class);
    expect($this->world->pushes())->toBe([])->and(DB::table('mobile_push_live_activities')->count())->toBe(0);
});

it('an activity started locally on the device is registered as active when its token is reported, and can be updated', function (): void {
    $local = $this->rider->reportLiveActivityToken($this->world->binding(), DeliveryActivity::class, LiveWorld::activityToken('l1'), new ActivityReport(
        clientActivityId: 'LOCAL-0001', state: ['eta' => 30],
    ));

    expect($local->state)->toBe(ActivityState::Active)
        ->and($local->push_to_start_id)->toBeNull()
        ->and($local->last_content_state)->toBe(['eta' => 30]);

    $this->world->kit->clock->advance(1);
    expect(LiveActivity::for($local)->update(['eta' => 29])->send()->status)->toBe(SendStatus::Accepted)
        ->and($this->world->pushes()[0]->url)->toEndWith('/3/device/'.LiveWorld::activityToken('l1')->value());
});
