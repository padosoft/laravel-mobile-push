<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelApiException;
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/LiveWorld.php';

const LAPTOP_CHANNEL = 'bGFwdG9wLWNoYW5uZWw=';

/** @return array<string, list<array<string, mixed>>> every row of the three tables, every column */
function liveTables(): array
{
    $out = [];
    foreach (['mobile_push_live_activities', 'mobile_push_live_activity_push_to_start_tokens', 'mobile_push_broadcast_channels'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
    }

    return $out;
}

/*
 * A developer runs the app on a laptop: the execution mode simulates. Everything reports what it would do; nothing
 * reaches APNs and nothing in the database changes, not even a version counter.
 */
it('a developer tries the feature on a laptop: zero requests and the three tables exactly as they were', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(1);
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('lp'));
    $activity = $rider->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('lp'), new ActivityReport(clientActivityId: 'LAPTOP-1', state: ['eta' => 10]));
    $world->channelAnswers = [new Response(201, ['apns-channel-id' => LAPTOP_CHANNEL])];
    $channel = $world->manager()->createChannel(StoragePolicy::NoStorage);
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->update(['eta' => 9])->send();   // one real update: the row has a last_timestamp
    LiveActivity::broadcast($channel)->update(['score' => '0-0'])->send();

    $world->dispatcher->options = SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate');
    $world->transport->reset();
    $before = liveTables();

    $start = LiveActivity::start(DeliveryActivity::class, ['order' => '1'], ['eta' => 20])->alert('Order confirmed')->to($rider)->send();
    $updates = [];
    foreach ([8, 7, 7] as $i => $eta) {
        $world->kit->clock->advance($i === 2 ? 0 : 1);   // the last two in the same second: still nothing written
        $updates[] = LiveActivity::for($activity)->update(['eta' => $eta])->send();
    }
    $world->kit->clock->advance(1);
    $end = LiveActivity::for($activity)->end(['eta' => 0])->dismissAt($world->kit->clock->now())->send();
    $publish = LiveActivity::broadcast($channel)->end(['score' => '1-0'])->send();

    expect(fn () => $world->manager()->createChannel())->toThrow(ChannelApiException::class)
        ->and(fn () => $world->channels()->delete($channel))->toThrow(ChannelApiException::class)
        ->and(fn () => $world->channels()->sync($world->binding()))->toThrow(ChannelApiException::class);

    expect($world->transport->requests())->toBe([])
        ->and(liveTables())->toBe($before)
        ->and($start->results[0]->status)->toBe(SendStatus::Simulated)
        ->and($start->activities)->toBe([])
        ->and(array_map(static fn ($r) => $r->status, [...$updates, $end, $publish]))->each->toBe(SendStatus::Simulated);
});

it('a simulated transition is still refused by the rules: an ended activity stays ended', function (): void {
    $world = LiveWorld::install();
    $activity = LiveWorld::rider(1)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('se'), new ActivityReport(clientActivityId: 'SIM-END'));
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->end()->send();

    $world->dispatcher->options = SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate');
    $world->kit->clock->advance(1);
    $late = LiveActivity::for($activity)->update(['eta' => 1])->send();

    expect($late->outcome)->toBe(TransitionOutcome::Skipped)->and($late->reason->code)->toBe(ReasonCode::PolicyIneligible);
});

it('staging with only one allowlisted device: the start goes to that device and only its row exists', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(2);
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('qa'));
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('xx'));
    $allowed = $world->kit->index->index(LiveWorld::pushToStart('qa'), 'default');
    $world->dispatcher->options = SendKit::live(applicationEnvironment: 'staging', live: new LiveSettings(allow: true, onlyTo: [$allowed]));

    $start = LiveActivity::start(DeliveryActivity::class, [], ['eta' => 5])->alert('QA build')->to($rider)->send();

    $statuses = array_map(static fn ($r) => $r->status, $start->results);
    sort($statuses);
    expect($world->pushes())->toHaveCount(1)
        ->and($world->pushes()[0]->url)->toEndWith('/'.LiveWorld::pushToStart('qa')->value())
        ->and($start->count())->toBe(2)
        ->and($start->accepted())->toBe(1)
        ->and($start->countOf(SendStatus::Skipped))->toBe(1)
        ->and($start->activities)->toHaveCount(1)
        ->and(DB::table('mobile_push_live_activities')->count())->toBe(1);

    $row = array_values($start->activities)[0];
    $kept = $start->results[array_key_first($start->activities)];
    expect($kept->status)->toBe(SendStatus::Accepted)
        ->and($row->push_to_start_id)->toBe($kept->destination->ref()?->id);
});

it('channel management blocked outside production leaves no row and no request', function (): void {
    $world = LiveWorld::install();
    $world->dispatcher->options = SendKit::live(applicationEnvironment: 'staging', live: new LiveSettings(allow: true));

    expect(fn () => $world->manager()->createChannel())->toThrow(ChannelApiException::class);
    expect($world->transport->requests())->toBe([])
        ->and(DB::table('mobile_push_broadcast_channels')->count())->toBe(0);
});

it('an unknown connection is refused before anything is written', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(3);

    expect(fn () => LiveActivity::start(DeliveryActivity::class, [], ['eta' => 1])->alert('Hi')->connection('android-only')->to($rider)->send())
        ->toThrow(UnknownConnectionException::class)
        ->and(fn () => $world->manager()->createChannel(connection: 'nope'))->toThrow(UnknownConnectionException::class);
    expect($world->transport->requests())->toBe([])
        ->and(DB::table('mobile_push_live_activities')->count())->toBe(0)
        ->and(DB::table('mobile_push_broadcast_channels')->count())->toBe(0);
});

it('if the pipeline simulates a transition that was already claimed, the claim is undone: same state, same timestamp, no ending', function (): void {
    $world = LiveWorld::install();
    $activity = LiveWorld::rider(4)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('sn'), new ActivityReport(clientActivityId: 'SIM-NET', state: ['eta' => 6]));
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->update(['eta' => 5])->send();
    $before = $activity->fresh();

    $world->dispatcher->forceSendOptions = SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate');
    $world->kit->clock->advance(5);
    $update = LiveActivity::for($activity)->update(['eta' => 4])->send();
    $world->kit->clock->advance(5);
    $end = LiveActivity::for($activity)->end(['eta' => 0])->send();

    $after = $activity->fresh();
    expect($update->status)->toBe(SendStatus::Simulated)
        ->and($end->status)->toBe(SendStatus::Simulated)
        ->and($world->pushes())->toHaveCount(1);
    foreach (['state', 'last_content_state', 'last_timestamp', 'last_event', 'last_priority', 'last_sent_at', 'hp_count', 'hp_window_started_at', 'end_requested_at', 'dismissal_at', 'stale_at'] as $column) {
        expect($after->{$column})->toEqual($before->{$column}, $column);
    }

    // Live again: the next update is not coalesced against a second that was never sent.
    $world->dispatcher->forceSendOptions = null;
    expect(LiveActivity::for($activity)->update(['eta' => 3])->send()->status)->toBe(SendStatus::Accepted);
});

it('a start the pipeline simulates or blocks leaves no activity row; a start APNs refused keeps its closed row', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(5);
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('bk'));
    $start = fn () => LiveActivity::start(DeliveryActivity::class, [], ['eta' => 5])->alert('Hi')->to($rider)->send();

    $world->dispatcher->forceSendOptions = SendKit::live(applicationEnvironment: 'local', configuredMode: 'simulate');
    expect($start()->results[0]->status)->toBe(SendStatus::Simulated);
    $world->dispatcher->forceSendOptions = SendKit::live(applicationEnvironment: 'staging');
    expect($start()->results[0]->reason->code)->toBe(ReasonCode::PolicySafeMode)
        ->and(DB::table('mobile_push_live_activities')->count())->toBe(0)
        ->and($world->transport->requests())->toBe([]);

    $world->dispatcher->forceSendOptions = null;
    $world->pushAnswers = [SendKit::apnsReason(400, 'BadDeviceToken')];
    $refused = $start();
    expect($refused->results[0]->status)->toBe(SendStatus::Rejected)
        ->and($refused->activities)->toHaveCount(1)
        ->and(DB::table('mobile_push_live_activities')->value('closed_reason'))->toBe('start_not_accepted');
});
