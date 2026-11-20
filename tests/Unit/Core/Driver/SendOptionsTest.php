<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;

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
