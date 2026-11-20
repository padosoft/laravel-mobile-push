<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityReport;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\DeliveryActivity;
use Padosoft\MobilePush\Tests\Scenario\Laravel\LiveActivities\LiveWorld;

require_once __DIR__.'/LiveWorld.php';

/*
 * Two tenants run the same app. User 7 exists in both, with the same type and even the same device token.
 */
it('two tenants: the same owner id and type in two scopes never share tokens, activities or channels', function (): void {
    $world = LiveWorld::install();
    $acme = $world->binding('acme');
    $beta = $world->binding('beta');
    $user = LiveWorld::rider(7);

    $user->registerPushToStartToken($acme, DeliveryActivity::class, LiveWorld::pushToStart('same'));
    $user->registerPushToStartToken($beta, DeliveryActivity::class, LiveWorld::pushToStart('same'));
    expect(DB::table('mobile_push_live_activity_push_to_start_tokens')->count())->toBe(2);

    $start = LiveActivity::start(DeliveryActivity::class, [], ['eta' => 9])->alert('Acme order')->scope('acme')->to($user)->send();
    expect($start->count())->toBe(1)
        ->and($start->activities[0]->scope)->toBe('acme')
        ->and($world->pushes())->toHaveCount(1);

    // Beta's app reports an activity token for the same owner: it does not attach to acme's requested row.
    $betaActivity = $user->reportLiveActivityToken($beta, DeliveryActivity::class, LiveWorld::activityToken('x1'), new ActivityReport(pushToStartToken: LiveWorld::pushToStart('same')));
    expect($betaActivity->id)->not->toBe($start->activities[0]->id)
        ->and($betaActivity->scope)->toBe('beta')
        ->and($start->activities[0]->fresh()->state)->toBe(ActivityState::Requested);

    $acmeActivity = $user->reportLiveActivityToken($acme, DeliveryActivity::class, LiveWorld::activityToken('x1'), new ActivityReport(pushToStartToken: LiveWorld::pushToStart('same')));
    expect($acmeActivity->id)->toBe($start->activities[0]->id)
        ->and($user->openLiveActivities($acme))->toHaveCount(1)
        ->and($user->openLiveActivities($beta))->toHaveCount(1)
        ->and($acmeActivity->update_token_index)->not->toBe($betaActivity->update_token_index);

    // Ending acme's activity leaves beta's untouched.
    $world->kit->clock->advance(1);
    LiveActivity::for($acmeActivity)->end()->send();
    expect($betaActivity->fresh()->state)->toBe(ActivityState::Active);

    // Channels: a channel id of acme is not reachable from beta.
    $world->channelAnswers = [new Response(201, ['apns-channel-id' => 'YWNtZS1jaGFubmVs'])];
    $world->channels()->create($acme, StoragePolicy::NoStorage);
    expect(fn () => LiveActivity::broadcast('YWNtZS1jaGFubmVs', scope: 'beta'))->toThrow(InvalidArgumentException::class);
    $world->kit->clock->advance(1);
    expect(LiveActivity::broadcast('YWNtZS1jaGFubmVs', scope: 'acme')->update(['x' => 1])->send()->status)->toBe(SendStatus::Accepted);
});

it('nothing readable at rest: no clear token in any column of any table', function (): void {
    $world = LiveWorld::install();
    $user = LiveWorld::rider(7);
    $pts = LiveWorld::pushToStart('secret');
    $token = LiveWorld::activityToken('secret');

    $user->registerPushToStartToken($world->binding(), DeliveryActivity::class, $pts);
    LiveActivity::start(DeliveryActivity::class, ['order' => '1'], ['eta' => 3])->alert('Go')->to($user)->send();
    $activity = $user->reportLiveActivityToken($world->binding(), DeliveryActivity::class, $token, new ActivityReport(pushToStartToken: $pts));
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->update(['eta' => 2])->send();
    $world->kit->clock->advance(1);
    LiveActivity::for($activity)->update(['eta' => 1])->send();
    LiveActivity::for($activity)->update(['eta' => 0])->send(); // coalesced: its payload is stored as pending

    foreach (['mobile_push_live_activities', 'mobile_push_live_activity_push_to_start_tokens'] as $table) {
        foreach (DB::table($table)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                foreach ([$pts->value(), $token->value()] as $secret) {
                    expect((string) $value)->not->toContain($secret, "{$table}.{$column} leaks a token")
                        ->and((string) $value)->not->toContain(strtoupper($secret));
                }
            }
        }
    }

    // Serialized models hide the token columns.
    expect(array_keys($activity->fresh()->toArray()))->not->toContain('update_token', 'update_token_index')
        ->and(array_keys($user->pushToStartTokens()->first()->toArray()))->not->toContain('token', 'token_index')
        ->and(call_user_func('print_r', $activity->fresh()->destination(), true))->not->toContain($token->value());
});
