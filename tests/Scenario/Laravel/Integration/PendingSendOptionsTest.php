<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;

require_once __DIR__.'/IntegrationWorld.php';
require_once dirname(__DIR__, 2).'/Send/RecordingObserver.php';

/*
 * A PendingSend builds its options ON TOP of the connection's defaults: a per-call observer present in the default
 * options (what the testing module adds) must still receive the results of MobilePush::to(...)->send().
 */
it('keeps the observers, gates and retry policy of the default options and adds its own settings', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $observer = new RecordingObserver;
    $manager = app(PushManager::class);
    $binding = $manager->binding('ios');
    $base = $manager->defaultOptions($binding)->withObserver($observer)->withWindow(7);

    $pending = MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('observed')))->bulk()->effectKey('effect-1');
    $options = (fn (SendOptions $b): SendOptions => $this->options($b))->call($pending, $base);
    $results = W::all($manager->send($binding, PushMessage::make('Hi'), [ApnsDeviceToken::of(W::apnsToken('observed'))], $options));

    expect($options->observers)->toBe([$observer])
        ->and($options->window)->toBe(7)
        ->and($options->retryPolicy)->toBe($base->retryPolicy)
        ->and($options->gates)->toBe($base->gates)
        ->and($options->effectKey)->toBe('effect-1')
        ->and($options->trafficClass->value)->toBe('bulk')
        ->and($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($observer->results)->toHaveCount(1)
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1);
});

it('forces the execution mode for the testing module, and clears it', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]]);
    $manager = app(PushManager::class);

    $manager->forceExecution(ExecutionMode::Simulate);
    $simulated = W::all(MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('forced')))->send(PushMessage::make('Hi')));
    $manager->forceExecution(null);
    $live = W::all(MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('forced')))->send(PushMessage::make('Hi')));

    expect($simulated[0]->status)->toBe(SendStatus::Simulated)
        ->and($live[0]->status)->toBe(SendStatus::Accepted)
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1);
});
