<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/** @return list<array{GuardKind, BreakerTransition}> */
function seen(RecordingObserver $observer): array
{
    return array_map(fn ($t) => [$t->guard, $t->transition], $observer->transitions);
}

/*
 * Story: the .p8 key is revoked, the breaker opens; a minute later the key is fixed and the first send is the probe.
 */
it('labels the half-open and closed transitions of the authentication breaker as authentication', function () {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $revoked = (new FakeTransport)->defaultTo(SendKit::apnsReason(403, 'InvalidProviderToken'));
    $guards = $kit->guards(authThreshold: 2);
    $driver = $kit->apnsDriver();
    SendKit::all($kit->sender($revoked, $guards, observers: [$observer])->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(5), SendKit::live()));
    $observer->transitions = [];

    $kit->clock->advance(61);
    $fixed = (new FakeTransport)->defaultTo(new Response(200));
    SendKit::all($kit->sender($fixed, $guards, observers: [$observer])->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live()));

    expect(seen($observer))->toBe([
        [GuardKind::Auth, BreakerTransition::HalfOpened],
        [GuardKind::Auth, BreakerTransition::Closed],
    ]);
});

/*
 * Story: a bulk campaign hits a staging database full of wrong-environment tokens; the destination breaker opens,
 * then recovers after its probe.
 */
it('labels the half-open and closed transitions of the destination-quality breaker as destination quality', function () {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $guards = $kit->guards(minSample: 4);
    $driver = $kit->apnsDriver();
    $bulk = SendKit::live(trafficClass: TrafficClass::Bulk);
    $wrong = (new FakeTransport)->defaultTo(SendKit::apnsReason(400, 'BadDeviceToken'));
    SendKit::all($kit->sender($wrong, $guards, observers: [$observer])->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(4), $bulk));
    $observer->transitions = [];

    $kit->clock->advance(301);
    $good = (new FakeTransport)->defaultTo(new Response(200));
    SendKit::all($kit->sender($good, $guards, observers: [$observer])->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(25), $bulk));

    expect(seen($observer))->toBe([
        [GuardKind::DestinationQuality, BreakerTransition::HalfOpened],
        [GuardKind::DestinationQuality, BreakerTransition::Closed],
    ]);
});
