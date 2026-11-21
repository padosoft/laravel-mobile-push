<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DestinationListAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignsModule;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once dirname(__DIR__).'/Integration/IntegrationWorld.php';

/*
 * The manager's supported seams: installing a driver for a connection, undoing a runtime registration, forcing the
 * execution for every destination without touching the application environment, and the campaign entry point.
 */

it('sends through an installed driver instead of the configured one, and back once it is forgotten', function (): void {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $manager = app(PushManager::class);
    $real = $manager->driver($manager->binding('ios'));
    $token = W::apnsToken('seam');

    $manager->useDriver('ios', $real); // the same driver object, installed explicitly
    $report = MobilePush::connection('ios')->to(ApnsDeviceToken::of($token))->send(PushMessage::make('Hi'));
    expect($report->counts()[SendStatus::Accepted->value])->toBe(1)
        ->and($manager->hasInstalledDriver('ios'))->toBeTrue()
        ->and($manager->cachedConnections())->toBe(1);

    $manager->forgetDriver('ios');
    expect($manager->hasInstalledDriver('ios'))->toBeFalse();
    MobilePush::connection('ios')->to(ApnsDeviceToken::of($token))->send(PushMessage::make('Hi'))->count();
    expect($world->send->requestsTo('api.push.apple.com'))->toHaveCount(2);
});

it('refuses a driver whose binding is for another connection', function (): void {
    IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $manager = app(PushManager::class);

    expect(fn () => $manager->useDriver('other', $manager->driver($manager->binding('ios'))))
        ->toThrow(ConfigurationException::class, 'same scope and name');
});

it('unregister() takes back a runtime connection: the name is gone and the configured one of the same name is used again', function (): void {
    IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $before = MobilePush::connectionNames();

    MobilePush::register('ios-eu', W::apns(bundle: 'com.example.eu'));
    MobilePush::register('ios', W::apns(bundle: 'com.example.runtime'));
    expect(MobilePush::connectionNames())->toBe(['ios', 'ios-eu'])
        ->and(MobilePush::binding('ios')->appId)->toBe('com.example.runtime');

    MobilePush::unregister('ios-eu');
    MobilePush::unregister('ios');
    MobilePush::unregister('never-registered');

    expect(MobilePush::connectionNames())->toBe($before)
        ->and(MobilePush::binding('ios')->appId)->toBe(W::BUNDLE)
        ->and(fn () => MobilePush::binding('ios-eu'))->toThrow(UnknownConnectionException::class);
});

it('forcing live for every destination outside production sends to a device on no allowlist, and the app still says "staging"', function (): void {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()], 'mode' => 'auto'], 'staging');
    $token = ApnsDeviceToken::of(W::apnsToken('not-allowlisted'));

    $blocked = MobilePush::connection('ios')->to($token)->send(PushMessage::make('Hi'))->results()->current();
    expect($blocked->status)->toBe(SendStatus::Simulated);

    MobilePush::forceExecution(ExecutionMode::Live);
    $skipped = MobilePush::connection('ios')->to($token)->send(PushMessage::make('Hi'))->results()->current();
    expect($skipped->status)->toBe(SendStatus::Skipped); // live, but the safe-mode allowlist still applies

    MobilePush::forceExecution(ExecutionMode::Live, everyDestination: true);
    $sent = MobilePush::connection('ios')->to($token)->send(PushMessage::make('Hi'))->results()->current();

    expect($sent->status)->toBe(SendStatus::Accepted)
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and(app()->environment())->toBe('staging')
        ->and(app()->isProduction())->toBeFalse()
        ->and(MobilePush::executionForced())->toBeTrue();

    MobilePush::forceExecution(null);
    expect(MobilePush::defaultOptions(MobilePush::binding('ios'))->effectiveMode)->toBe(ExecutionMode::Simulate)
        ->and(MobilePush::executionForced())->toBeFalse();
});

it('forgetEligibility() removes exactly the check it was given', function (): void {
    IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $mine = static fn (): bool => false;
    $other = static fn (): bool => true;
    MobilePush::eligibleUsing($mine);
    MobilePush::eligibleUsing($other);

    expect(MobilePush::forgetEligibility($mine))->toBe(1)
        ->and(MobilePush::eligibilityChecks())->toBe(1)
        ->and(MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('ok')))->send(PushMessage::make('Hi'))->results()->current()->status)
        ->toBe(SendStatus::Accepted);
});

it('MobilePush::campaign() drafts a campaign on the durable profile', function (): void {
    IntegrationWorld::install(['connections' => ['ios' => W::apns()], 'campaigns.enabled' => true])->withDurableLedger();
    (require CampaignsModule::migrationPath())->up();

    $campaign = MobilePush::campaign(PushMessage::make('Black Friday', '-40%'))
        ->name('bf')
        ->via('ios')
        ->audience(DestinationListAudience::of([ApnsDeviceToken::of(W::apnsToken('c1')), ApnsDeviceToken::of(W::apnsToken('c2'))]))
        ->draft();

    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->binding->name)->toBe('ios')
        ->and(DB::table('mobile_push_campaigns')->count())->toBe(1);
});

it('MobilePush::campaign() on the light profile fails with the module\'s clear message, before writing anything', function (): void {
    IntegrationWorld::install(['connections' => ['ios' => W::apns()], 'campaigns.enabled' => true]);

    expect(fn () => MobilePush::campaign(PushMessage::make('Black Friday'))
        ->via('ios')
        ->audience(DestinationListAudience::of([ApnsDeviceToken::of(W::apnsToken('c1'))]))
        ->draft())
        ->toThrow(ConfigurationException::class, 'Campaigns need the durable delivery profile');
});
