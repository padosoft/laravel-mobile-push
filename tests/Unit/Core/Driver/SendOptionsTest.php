<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;

it('resolves the execution mode from the configured mode and the application environment', function (string $configured, ?string $environment, ExecutionMode $expected) {
    expect((new SendOptions(applicationEnvironment: $environment, configuredMode: $configured))->effectiveMode)->toBe($expected);
})->with([
    'auto in production' => ['auto', 'production', ExecutionMode::Live],
    'auto on staging' => ['auto', 'staging', ExecutionMode::Simulate],
    'auto, environment unknown' => ['auto', null, ExecutionMode::Simulate],
    'live anywhere' => ['live', 'local', ExecutionMode::Live],
    'simulate in production' => ['simulate', 'production', ExecutionMode::Simulate],
]);

it('lets an already resolved mode win over the configured one', function () {
    expect((new SendOptions(applicationEnvironment: 'production', configuredMode: 'live', mode: ExecutionMode::Simulate))->effectiveMode)->toBe(ExecutionMode::Simulate);
});

it('rejects invalid options before the network', function (array $args) {
    expect(fn () => new SendOptions(...$args))->toThrow(ConfigurationException::class);
})->with([
    'unknown mode' => [['configuredMode' => 'sometimes']],
    'empty effect key' => [['effectKey' => '  ']],
    'zero window' => [['window' => 0]],
    'huge window' => [['window' => SendOptions::MAX_WINDOW + 1]],
]);

it('copies every other field when a with-helper changes one', function () {
    $observer = new RecordingObserver;
    $gate = new class implements DestinationGate
    {
        public function check(GateContext $context, array $items): array
        {
            return [];
        }
    };
    $policy = new RetryPolicy(maxAttempts: 2);
    $at = new DateTimeImmutable('2026-11-20T12:00:00Z');
    $marker = fn ($d, $id) => 'm';
    $base = new SendOptions(
        trafficClass: TrafficClass::Bulk,
        effectKey: 'k',
        applicationEnvironment: 'production',
        configuredMode: 'live',
        retryPolicy: $policy,
        receiptMarker: $marker,
        gates: [$gate],
        expiresAt: $at,
        window: 7,
        observers: [$observer],
    );

    $snapshot = fn (SendOptions $o) => [
        $o->trafficClass, $o->validateOnly, $o->effectKey, $o->applicationEnvironment, $o->configuredMode, $o->effectiveMode,
        $o->retryPolicy, $o->receiptMarker !== null, $o->gates, $o->expiresAt, $o->window, $o->observers, $o->live,
    ];
    $changes = [
        'observers' => [$base->withObserver($observer), 11, [$observer, $observer]],
        'gates' => [$base->withGate($gate), 8, [$gate, $gate]],
        'effectKey' => [$base->withEffectKey(null), 2, null],
        'expiresAt' => [$base->withExpiresAt(null), 9, null],
        'trafficClass' => [$base->withTrafficClass(TrafficClass::Transactional), 0, TrafficClass::Transactional],
        'validateOnly' => [$base->withValidateOnly(true), 1, true],
        'window' => [$base->withWindow(null), 10, null],
        'retryPolicy' => [$base->withRetryPolicy(null), 6, null],
        'receiptMarker' => [$base->withReceiptMarker(null), 7, false],
    ];

    $original = $snapshot($base);
    foreach ($changes as $name => [$copy, $index, $expected]) {
        $expectedSnapshot = $original;
        $expectedSnapshot[$index] = $expected;
        expect($snapshot($copy))->toEqual($expectedSnapshot, $name);
    }
    expect($snapshot($base))->toEqual($original)                  // the original is untouched
        ->and($base->withEffectKey('x')->effectiveMode)->toBe($base->effectiveMode);
});

it('keeps an explicitly resolved mode through a copy', function () {
    $o = (new SendOptions(mode: ExecutionMode::Simulate, configuredMode: 'live', applicationEnvironment: 'production'))->withWindow(3);

    expect($o->effectiveMode)->toBe(ExecutionMode::Simulate);
});

it('rejects a per-call observer that is not a SendObserver', function () {
    expect(fn () => new SendOptions(observers: [new stdClass]))->toThrow(ConfigurationException::class);
});
