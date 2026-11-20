<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Laravel\LiveActivities\TokenChange;
use Padosoft\MobilePush\Laravel\LiveActivities\TokenState;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/LiveWorld.php';

function unregistered(): Response
{
    return new Response(410, ['apns-id' => '00000000-0000-4000-8000-000000000000'], '{"reason":"Unregistered","timestamp":'.((time() + 86_400) * 1000).'}');
}

/*
 * ActivityKit rotates the update token of a running activity; the app reports the new one. A 410 that APNs gives
 * late, for a request sent with the old token, must not kill the activity's new token.
 */
it('token rotated: a new generation; a late 410 for the old generation leaves the new token alone', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(5);
    $report = new ActivityReport(clientActivityId: 'ROT-1', state: ['eta' => 9]);
    $activity = $rider->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('o1'), $report);
    $oldDestination = $activity->destination();

    $rotated = $rider->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('n1'), $report);

    expect($rotated->id)->toBe($activity->id)
        ->and($rotated->token_generation)->toBe(2)
        ->and($rotated->destination()?->value())->toBe(LiveWorld::activityToken('n1')->value())
        ->and(DB::table('mobile_push_live_activities')->count())->toBe(1);

    // A request made with the old token (generation 1) is answered 410 now.
    $world->pushAnswers = [unregistered()];
    [$late] = SendKit::all($world->dispatcher->send($world->binding(), LiveActivityMessage::update(['eta' => 8]), [$oldDestination], $world->dispatcher->defaultOptions($world->binding())));

    expect($late->destinationAction)->toBe(DestinationAction::Invalidate)
        ->and($world->registry()->applyResult($late))->toBe(TokenChange::GenerationChanged);

    $row = $activity->fresh();
    expect($row->state)->toBe(ActivityState::Active)->and($row->token_state)->toBe(TokenState::Active);

    $world->kit->clock->advance(1);
    $next = LiveActivity::for($activity)->update(['eta' => 7])->send();
    expect($next->status)->toBe(SendStatus::Accepted)
        ->and(array_slice($world->transport->requests(), -1)[0]->url)->toEndWith('/'.LiveWorld::activityToken('n1')->value());
});

it('a 410 for the current generation ends the activity and nothing more is sent to it', function (): void {
    $world = LiveWorld::install();
    $activity = LiveWorld::rider(5)->reportLiveActivityToken($world->binding(), DeliveryActivity::class, LiveWorld::activityToken('g1'), new ActivityReport(clientActivityId: 'GONE-1'));
    $world->kit->clock->advance(1);
    $world->pushAnswers = [unregistered()];

    $gone = LiveActivity::for($activity)->update(['eta' => 3])->send();

    $row = $activity->fresh();
    expect($gone->status)->toBe(SendStatus::Rejected)
        ->and($row->token_state)->toBe(TokenState::Invalidated)
        ->and($row->state)->toBe(ActivityState::Ended)
        ->and($row->closed_reason?->value)->toBe('token_invalidated')
        ->and($row->end_accepted_at)->toBeNull();

    $world->kit->clock->advance(1);
    expect(LiveActivity::for($activity)->update(['eta' => 2])->send()->status)->toBe(SendStatus::Skipped)
        ->and($world->pushes())->toHaveCount(1);
});

it('a rotated push-to-start token replaces the old row instead of duplicating it', function (): void {
    $world = LiveWorld::install();
    $rider = LiveWorld::rider(5);
    $first = $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('old'));
    $again = $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('old'));
    $rotated = $rider->registerPushToStartToken($world->binding(), DeliveryActivity::class, LiveWorld::pushToStart('new'), LiveWorld::pushToStart('old'));

    expect($again->generation)->toBe(1)
        ->and($rotated->id)->toBe($first->id)
        ->and($rotated->generation)->toBe(2)
        ->and($rotated->destination()->value())->toBe(LiveWorld::pushToStart('new')->value())
        ->and(DB::table('mobile_push_live_activity_push_to_start_tokens')->count())->toBe(1);

    LiveActivity::start(DeliveryActivity::class, [], ['eta' => 1])->alert('Go')->to($rider)->send();
    expect($world->pushes()[0]->url)->toEndWith('/'.LiveWorld::pushToStart('new')->value());
});
