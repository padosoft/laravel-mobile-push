<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\Events\BindingMismatchDetected;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

beforeEach(function () {
    $this->world = W::install(['connections' => ['main' => W::apns(bundle: 'com.example.default')]]);
    $this->token = ApnsDeviceToken::of(W::apnsToken('tenant-device'));
});

it('keeps two tenants with the same alias apart: a binding captured for A keeps going to A after B reconfigures', function () {
    MobilePush::register('main', W::apns(keyId: 'KEYAAAAAAA', bundle: 'com.tenant-a.app'), 'tenant-a');
    MobilePush::register('main', W::apns(keyId: 'KEYBBBBBBB', bundle: 'com.tenant-b.app'), 'tenant-b');
    $capturedForA = MobilePush::binding('main', 'tenant-a');

    MobilePush::register('main', W::apns(keyId: 'KEYBBBBBB2', bundle: 'com.tenant-b.other'), 'tenant-b');
    $a = W::all(MobilePush::send($capturedForA, PushMessage::make('Hi A'), [$this->token]))[0];
    $b = W::all(MobilePush::scope('tenant-b')->connection('main')->to($this->token)->send(PushMessage::make('Hi B')))[0];

    $recorded = $this->world->transport->recorded();
    expect($a->status)->toBe(SendStatus::Accepted)
        ->and($b->status)->toBe(SendStatus::Accepted)
        ->and($recorded)->toHaveCount(2)
        ->and($recorded[0]['request']->headers['apns-topic'])->toBe('com.tenant-a.app')
        ->and(W::jwtHeader($recorded[0]['request'])['kid'])->toBe('KEYAAAAAAA')
        ->and($recorded[1]['request']->headers['apns-topic'])->toBe('com.tenant-b.other')
        ->and(W::jwtHeader($recorded[1]['request'])['kid'])->toBe('KEYBBBBBB2')
        ->and($recorded[0]['profile']->poolKey)->not->toBe($recorded[1]['profile']->poolKey)
        ->and($capturedForA->identity())->not->toBe(MobilePush::binding('main', 'tenant-b')->identity());
});

it('refuses a captured binding once its alias points to another app: zero requests, an event, no default fallback', function () {
    MobilePush::register('main', W::apns(bundle: 'com.tenant-a.app'), 'tenant-a');
    $capturedForA = MobilePush::binding('main', 'tenant-a');
    MobilePush::register('main', W::apns(bundle: 'com.someone-else.app'), 'tenant-a');

    $results = W::all(MobilePush::send($capturedForA, PushMessage::make('Hi'), [$this->token, ApnsDeviceToken::of(W::apnsToken('second'))]));

    $events = $this->world->eventsOf(BindingMismatchDetected::class);
    expect($this->world->transport->sentCount())->toBe(0)
        ->and(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Skipped, SendStatus::Skipped])
        ->and($results[0]->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->expected?->appId)->toBe('com.tenant-a.app')
        ->and($events[0]->current?->appId)->toBe('com.someone-else.app');
});

it('refuses an alias that no longer resolves instead of using the configured connection of the same name', function () {
    // "main" exists in config (default scope) and as a runtime connection of tenant-a; a binding of tenant-a whose
    // name "ghost" resolves nowhere must not land on anything.
    $ghost = new BindingRef('tenant-a', 'ghost', Provider::Apns, 'com.tenant-a.app', Environment::Production);
    $results = W::all(MobilePush::send($ghost, PushMessage::make('Hi'), [$this->token]));

    expect($this->world->transport->sentCount())->toBe(0)
        ->and($results[0]->status)->toBe(SendStatus::Skipped)
        ->and($results[0]->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($this->world->eventsOf(BindingMismatchDetected::class)[0]->current)->toBeNull();

    // A tenant job whose runtime "main" is gone: the configured "main" (another app) is never used instead.
    $tenantMain = new BindingRef('tenant-a', 'main', Provider::Apns, 'com.tenant-a.app', Environment::Production);
    $again = W::all(MobilePush::send($tenantMain, PushMessage::make('Hi'), [$this->token]));

    expect($this->world->transport->sentCount())->toBe(0)
        ->and($again[0]->reason->code)->toBe(ReasonCode::BindingMismatch)
        ->and($this->world->eventsOf(BindingMismatchDetected::class)[1]->current?->appId)->toBe('com.example.default');
});

it('sends an old job with the new key after a key rotation: same identity, new generation', function () {
    MobilePush::register('main', W::apns(keyId: 'KEYOLD0001', bundle: 'com.tenant-a.app'), 'tenant-a');
    $oldJob = MobilePush::binding('main', 'tenant-a');
    MobilePush::register('main', W::apns(keyId: 'KEYNEW0002', bundle: 'com.tenant-a.app'), 'tenant-a');
    $now = MobilePush::binding('main', 'tenant-a');

    $result = W::all(MobilePush::send(BindingRef::fromArray($oldJob->toArray()), PushMessage::make('Hi'), [$this->token]))[0];

    expect($now->generation)->not->toBe($oldJob->generation)
        ->and($now->sameIdentityAs($oldJob))->toBeTrue()
        ->and($result->status)->toBe(SendStatus::Accepted)
        ->and(W::jwtHeader($this->world->pushRequests()[0])['kid'])->toBe('KEYNEW0002');
});

it('keeps the driver cache bounded in a long-lived worker that serves 200 tenants', function () {
    config()->set('mobile-push.connection_cache.size', 16);
    W::forgetSingletons();
    MobilePush::resolveConnectionUsing(fn (string $name, string $scope): ?array => str_starts_with($scope, 'tenant-')
        ? W::apns(keyId: 'KEY'.str_pad(substr($scope, 7), 7, '0', STR_PAD_LEFT), bundle: 'com.'.$scope.'.app')
        : null);

    $peak = 0;
    for ($i = 1; $i <= 200; $i++) {
        MobilePush::scope('tenant-'.$i)->connection('main')->toToken(Platform::Ios, W::apnsToken((string) $i))->send(PushMessage::make('Hi'))->counts();
        $peak = max($peak, MobilePush::cachedConnections());
    }
    $topics = array_unique(array_map(fn ($r) => $r->headers['apns-topic'], $this->world->pushRequests()));

    expect($peak)->toBeLessThanOrEqual(16)
        ->and($topics)->toHaveCount(200)
        ->and(MobilePush::cachedConnections())->toBe(16);
});
