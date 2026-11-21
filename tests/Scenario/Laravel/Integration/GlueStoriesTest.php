<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\Ineligibility;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\HealthStatus;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;
use Padosoft\MobilePush\Laravel\Queue\QueuedDestination;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

it('a light-profile job that dies is reported to the send log once per destination, as unknown, without tokens', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]])->withDatabaseLog();
    $tokens = [W::apnsToken('lost-1'), W::apnsToken('lost-2')];
    $job = new SendPushJob(
        MobilePush::binding('ios')->toArray(),
        serialize(PushMessage::make('Hi')),
        array_map(static fn (string $t): array => QueuedDestination::of(ApnsDeviceToken::of($t))->toArray(), $tokens),
        'effect-1',
        'effect-1',
    );

    $job->failed(new RuntimeException('worker killed'));

    $rows = IntegrationWorld::logRows();
    $dump = json_encode($rows, JSON_THROW_ON_ERROR);
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'status'))->toBe(['outcome_unknown', 'outcome_unknown'])
        ->and(array_column($rows, 'reason_raw'))->toBe(['job_failed:RuntimeException', 'job_failed:RuntimeException'])
        ->and(array_column($rows, 'effect_id'))->toBe(['effect-1', 'effect-1'])
        ->and($dump)->not->toContain($tokens[0])->not->toContain($tokens[1])
        ->and($world->send->pushRequests())->toBe([]);
});

it('announces once that a durable effect settled, with its origin and counts', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]])->withDurableLedger();
    $settled = [];
    Event::listen(EffectSettled::class, static function (EffectSettled $e) use (&$settled): void {
        $settled[] = $e;
    });

    $effectId = MobilePush::connection('ios')->to([ApnsDeviceToken::of(W::apnsToken('s1')), ApnsDeviceToken::of(W::apnsToken('s2'))])->queue(PushMessage::make('Hi'));

    expect($world->send->requestsTo('api.push.apple.com'))->toHaveCount(2)
        ->and($settled)->toHaveCount(1)
        ->and($settled[0]->effectId)->toBe($effectId)
        ->and($settled[0]->counts->total())->toBe(2)
        ->and($settled[0]->counts->terminal())->toBe(2)
        ->and(app(Padosoft\MobilePush\Laravel\Integration\EffectSettlement::class)->check('default', $effectId))->toBeNull();
});

it('reports a connection whose key cannot be read as failed in readiness, naming the key, never its value', function () {
    $broken = W::apns(keyId: 'KEY2222222', extra: ['key' => ['base64' => base64_encode('-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----')]]);
    IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'ios-broken' => $broken]]);

    $checks = [];
    foreach (app(HealthInspector::class)->inspect()->readiness as $check) {
        $checks[$check->name] = $check;
    }

    expect($checks['credentials.ios']->status)->toBe(HealthStatus::Ok)
        ->and($checks['credentials.ios-broken']->status)->toBe(HealthStatus::Failed)
        ->and($checks['credentials.ios-broken']->message)->toContain('ios-broken')
        ->and($checks['credentials.ios-broken']->message)->not->toContain('not-a-key');
});

it('resolves eligibility and rows of registry devices in one query', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $world->send->withDevices();
    $alice = Customer::withId(1);
    $phone = $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('p')), $alice);
    $tablet = $world->send->register(MobilePush::binding('android'), FcmToken::of('fake-tablet-token'), $alice);
    $refs = [$phone->ref(), $tablet->ref()];
    $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of(W::apnsToken('p')), Customer::withId(2));   // the phone changed hands

    $queries = 0;
    Illuminate\Support\Facades\DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    $resolved = app(DeviceEligibility::class)->resolveMany($refs);

    expect($queries)->toBe(1)
        ->and($resolved[$refs[0]->id]->eligible())->toBeFalse()
        ->and($resolved[$refs[0]->id]->eligibility->cause)->toBe(Ineligibility::OwnerChanged)
        ->and($resolved[$refs[1]->id]->eligible())->toBeTrue()
        ->and($resolved[$refs[1]->id]->device?->destination()->value())->toBe('fake-tablet-token');
});
