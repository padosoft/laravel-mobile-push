<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * Device-registry gates must ignore destinations that are not registry devices. A Live Activity token carries a
 * DestinationRef of the Live Activities tables: with the device registry (and the frequency cap) on, an update sent
 * through the manager must still reach APNs, while a registry device whose row does not exist is still refused.
 */
it('sends a Live Activity update through the manager with the device registry and the frequency cap on', function () {
    $world = IntegrationWorld::install([
        'connections' => ['ios' => W::apns()],
        'frequency.enabled' => true,
        'frequency.limits' => ['default' => ['per_day' => 1]],
    ]);
    $world->send->withDevices();
    // Row 01J... of mobile_push_live_activities, owned by rider 5: unknown to the devices table.
    $activity = ApnsLiveActivityToken::of(W::apnsToken('activity'))->withRef(new DestinationRef('01JLIVEACTIVITYROW00000001', 1, 'App\\Rider', '5'));
    $binding = MobilePush::binding('ios');

    $updates = [];
    foreach (['1-0', '2-0', '2-1'] as $score) {
        $updates[] = W::all(MobilePush::send($binding, LiveActivityMessage::update(['score' => $score]), [$activity]))[0];
    }
    // A registry device that does not exist is still refused by the eligibility gate.
    $ghost = ApnsDeviceToken::of(W::apnsToken('ghost'))->withRef(new DestinationRef('999999', 1, 'App\\Rider', '5'));
    $refused = W::all(MobilePush::send($binding, LiveActivityMessage::update(['score' => '3-1']), [$ghost]))[0];

    expect(array_map(static fn ($r) => $r->status, $updates))->toBe([SendStatus::Accepted, SendStatus::Accepted, SendStatus::Accepted])
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(3)
        ->and($refused->status)->toBe(SendStatus::Skipped)
        ->and($refused->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($refused->reason->rawReason)->toBe('row_missing');
});
