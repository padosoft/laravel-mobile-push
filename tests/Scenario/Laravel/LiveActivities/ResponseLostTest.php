<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

/*
 * The request was written, then the connection dropped before APNs answered. An update or an end is replaceable (a
 * later copy replaces the earlier one on the device), so it is retried; a start is not: starting twice would show
 * two activities.
 */
function connectionDropped(): TransportFailure
{
    return new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream 1 was not closed cleanly: INTERNAL_ERROR');
}

function activeActivity(LiveWorld $world): PushActivity
{
    return LiveWorld::rider(3)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('r1'), new ActivityReport(clientActivityId: 'C-1', state: ['eta' => 12]));
}

it('a lost update is retried as the pending transition, and the row ends consistent with the request that was answered', function (): void {
    $world = LiveWorld::install();
    $activity = activeActivity($world);
    $world->kit->clock->advance(1);
    $sentAt = $world->second();
    $world->pushAnswers = [connectionDropped()];

    $lost = LiveActivity::for($activity)->update(['eta' => 11])->send();

    expect($lost->outcome)->toBe(TransitionOutcome::Sent)
        ->and($lost->status)->toBe(SendStatus::Deferred)
        ->and($world->pushes())->toHaveCount(1);
    $row = $activity->fresh();
    expect($row->pending_seq)->toBe($lost->seq)
        ->and($row->pending_attempts)->toBe(1)
        ->and($row->last_content_state)->toBe(['eta' => 11])
        ->and($row->last_timestamp)->toBe($sentAt);

    // Before the retry is due nothing goes out.
    expect($world->manager()->flushDue())->toBe([])->and($world->pushes())->toHaveCount(1);

    $world->kit->clock->advance(1);
    [$retried] = $world->manager()->flushDue();

    $pushes = $world->pushes();
    expect($retried->status)->toBe(SendStatus::Accepted)
        ->and($pushes)->toHaveCount(2)
        ->and(LiveWorld::aps($pushes[1])['content-state'])->toBe(['eta' => 11])
        ->and(LiveWorld::aps($pushes[1])['timestamp'])->toBe($sentAt + 1);

    $row = $activity->fresh();
    expect($row->pending_seq)->toBeNull()
        ->and($row->last_status)->toBe('accepted')
        ->and($row->last_timestamp)->toBe($sentAt + 1)
        ->and($row->last_content_state)->toBe(['eta' => 11])
        ->and($row->state)->toBe(ActivityState::Active);
});

it('a newer update supersedes a lost one: the retry of the old state never goes out', function (): void {
    $world = LiveWorld::install();
    $activity = activeActivity($world);
    $world->kit->clock->advance(1);
    $world->pushAnswers = [connectionDropped()];
    LiveActivity::for($activity)->update(['eta' => 11])->send();

    $world->kit->clock->advance(2);
    LiveActivity::for($activity)->update(['eta' => 9])->send();
    $world->kit->clock->advance(10);

    expect($world->manager()->flushDue())->toBe([])
        ->and(array_map(static fn ($r) => LiveWorld::aps($r)['content-state'], $world->pushes()))->toBe([['eta' => 11], ['eta' => 9]])
        ->and($activity->fresh()->last_content_state)->toBe(['eta' => 9]);
});

it('an inline retry crossing into the next second is stored with the second it actually carried', function (): void {
    $world = LiveWorld::install(policy: new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, inlineThresholdMs: 1000));
    $activity = activeActivity($world);
    $world->kit->clock->advance(1);
    $before = $world->second();
    $world->pushAnswers = [connectionDropped()];

    $result = LiveActivity::for($activity)->update(['eta' => 4])->send();

    $pushes = $world->pushes();
    expect($result->status)->toBe(SendStatus::Accepted)
        ->and($pushes)->toHaveCount(2)
        ->and(LiveWorld::aps($pushes[1])['timestamp'])->toBe($before + 1)
        ->and($activity->fresh()->last_timestamp)->toBe($before + 1);

    // An update in that same second is coalesced instead of repeating the timestamp APNs already saw.
    expect(LiveActivity::for($activity)->update(['eta' => 3])->send()->outcome)->toBe(TransitionOutcome::Deferred);
});

it('a lost start is not retried, even when the policy would retry every unknown outcome, and the row says so', function (UnknownOutcomeRetry $policy): void {
    $world = LiveWorld::install(policy: new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, unknownOutcome: $policy, inlineThresholdMs: 5000));
    $rider = LiveWorld::rider(3);
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('s1'));
    $world->pushAnswers = [connectionDropped()];

    $start = LiveActivity::start(DeliveryActivity::class, ['order' => '7'], ['eta' => 30])->alert('Order confirmed')->to($rider)->send();

    expect($world->pushes())->toHaveCount(1)
        ->and($start->results[0]->status)->toBe(SendStatus::OutcomeUnknown);

    $row = $start->activities[0]->fresh();
    expect($row->state)->toBe(ActivityState::Requested)
        ->and($row->start_status)->toBe('outcome_unknown')
        ->and($row->closed_reason)->toBeNull()
        ->and($row->pending_seq)->toBeNull();

    $world->kit->clock->advance(60);
    expect($world->manager()->flushDue())->toBe([])->and($world->pushes())->toHaveCount(1);

    // If the device did start it, its token report attaches to this very row.
    $reported = $rider->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('s1'), new ActivityReport(pushToStartToken: LiveWorld::pushToStart('s1')));
    expect($reported->id)->toBe($row->id)->and($reported->state)->toBe(ActivityState::Active);
})->with([
    'replaceable policy' => [UnknownOutcomeRetry::Replaceable],
    'always policy' => [UnknownOutcomeRetry::Always],
]);

it('a start APNs refused closes its row without pretending it was never sent', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(3);
    $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('s2'));
    $world->pushAnswers = [\Padosoft\MobilePush\Tests\Scenario\Send\SendKit::apnsReason(400, 'BadDeviceToken')];

    $start = LiveActivity::start(DeliveryActivity::class, [], ['eta' => 30])->alert('Order confirmed')->to($rider)->send();

    $row = $start->activities[0]->fresh();
    expect($start->results[0]->status)->toBe(SendStatus::Rejected)
        ->and($row->start_status)->toBe('rejected')
        ->and($row->state)->toBe(ActivityState::Ended)
        ->and($row->closed_reason?->value)->toBe('start_not_accepted')
        ->and($row->end_accepted_at)->toBeNull()
        ->and($rider->pushToStartTokens()->first()->state->value)->toBe('quarantined');
});
