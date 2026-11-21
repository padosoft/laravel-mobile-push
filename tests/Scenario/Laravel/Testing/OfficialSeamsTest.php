<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Testing\InteractsWithMobilePush;
use Padosoft\MobilePush\Laravel\Testing\TestingSession;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Testing\Shopper;

uses(InteractsWithMobilePush::class);

/*
 * The fakes go through the manager's supported seams (forced execution, installed drivers, a removable recording
 * gate): host code that looks at the environment sees the real one, and a reset leaves nothing behind.
 */

/** What a host app would do: something only production does (e.g. warm a CDN), next to a push. */
function hostShipOrder(Shopper $shopper, array &$productionOnly): void
{
    if (app()->isProduction()) {
        $productionOnly[] = 'warmed the production CDN';
    }
    if (app()->environment('production')) {
        $productionOnly[] = 'called the production ERP';
    }
    MobilePush::to($shopper)->send(PushMessage::make('Order shipped'))->count();
}

it('does not fool host code that checks the environment', function (string $activate): void {
    $productionOnly = [];
    $fake = MobilePush::{$activate}();

    expect(app()->environment())->toBe('testing')
        ->and(app()->isProduction())->toBeFalse();
    hostShipOrder(new Shopper(1), $productionOnly);

    expect($productionOnly)->toBe([]);
    $activate === 'fake' ? $fake->assertSentTimes(1) : $fake->assertRequestCount(1); // and the push still went "live"
})->with(['fake', 'fakeTransport']);

it('leaves no residue once reset: connection names, forced execution and gates are exactly what they were', function (array $connections): void {
    config()->set('mobile-push.connections', $connections);
    $manager = app(PushManager::class);
    $names = MobilePush::connectionNames();
    $gates = $manager->eligibilityChecks();
    $cached = $manager->cachedConnections();

    // fakeTransport() runs the real pipeline: it needs real-looking credentials, so only with the test connections.
    foreach ($connections === [] ? ['fake', 'fakeTransport', 'fake'] : ['fake', 'fake'] as $activate) {
        $fake = MobilePush::{$activate}();
        MobilePush::to(new Shopper(1))->send(PushMessage::make('a'))->count();
        if ($activate === 'fake') {
            $fake->connection('ios-eu', 'apns', 'com.example.eu')->connection('tenant-ios', 'apns', null, 'tenant-b');
            MobilePush::connection('ios-eu')->to(new Shopper(2))->send(PushMessage::make('b'))->count();
            $fake->assertSentOnConnection('ios-eu');
        }
        expect($manager->executionForced())->toBeTrue();
    }
    $this->resetMobilePushFakes();

    expect(app(PushManager::class))->toBe($manager)
        ->and(MobilePush::connectionNames())->toBe($names)
        ->and(MobilePush::connectionNames('tenant-b'))->toBe($names)
        ->and($manager->executionForced())->toBeFalse()
        ->and($manager->eligibilityChecks())->toBe($gates)
        ->and($manager->cachedConnections())->toBe($cached)
        ->and($manager->hasInstalledDriver('ios-eu'))->toBeFalse()
        ->and(config('mobile-push.connections'))->toBe($connections)
        ->and(app(TestingSession::class)->active())->toBeFalse();
})->with([
    'no connection configured' => [[]],
    'the app\'s own connections' => [['shop-ios' => ['driver' => 'apns', 'bundle_id' => 'com.example.shop', 'team_id' => 'TEAM123456', 'environment' => 'production']]],
]);
