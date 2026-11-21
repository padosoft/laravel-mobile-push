<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Padosoft\MobilePush\Laravel\Pulse\KeyNormalizer;
use Padosoft\MobilePush\Laravel\Pulse\PulseModule;
use Padosoft\MobilePush\Laravel\Pulse\PulseSettings;
use Padosoft\MobilePush\Laravel\Pulse\Sampler;

it('turns any identifier into a short, storage-safe key part', function (string $raw, string $expected) {
    expect(KeyNormalizer::part($raw))->toBe($expected);
})->with([
    'plain' => ['ios-main', 'ios-main'],
    'spaces and symbols' => ['Tenant A / "prod"', 'Tenant_A_prod_'],
    'empty' => ['', 'unknown'],
    'newline and unicode' => ["a\nb\u{1F600}c", 'a_b_c'],
    'long' => [str_repeat('x', 200), str_repeat('x', 48)],
]);

it('encodes a key as a JSON list of normalised parts', function () {
    expect(KeyNormalizer::key(['apns', 'my conn', 'production', 'accepted']))->toBe('["apns","my_conn","production","accepted"]');
});

it('folds connections beyond the cap into _other but keeps the known ones', function () {
    $keys = new KeyNormalizer(2);

    expect($keys->connection('a'))->toBe('a')
        ->and($keys->connection('b'))->toBe('b')
        ->and($keys->connection('c'))->toBe('_other')
        ->and($keys->connection('a'))->toBe('a');
});

it('samples by comparing a uniform roll with the rate', function (float $rate, float $roll, bool $kept) {
    expect(Sampler::keeps($rate, $roll))->toBe($kept);
})->with([
    'always' => [1.0, 0.999, true],
    'never' => [0.0, 0.0, false],
    'below' => [0.25, 0.24, true],
    'at the rate' => [0.25, 0.25, false],
]);

it('reads its settings with explicit defaults and clamps bad values', function () {
    $config = new Repository;
    expect(PulseSettings::fromConfig($config))->toEqual(new PulseSettings(false, 1.0, 50));

    $config->set('mobile-push.pulse', ['enabled' => 'true', 'sample_rate' => 7, 'max_connections' => 0]);
    expect(PulseSettings::fromConfig($config))->toEqual(new PulseSettings(true, 1.0, 1));

    $config->set('mobile-push.pulse.sample_rate', 'abc');
    expect(PulseSettings::fromConfig($config)->sampleRate)->toBe(1.0);
});

it('knows whether Pulse is installed', function () {
    expect(PulseModule::pulseInstalled())->toBeTrue();
});
