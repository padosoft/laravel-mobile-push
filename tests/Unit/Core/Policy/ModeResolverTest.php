<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\ModeResolver;

it('resolves the effective mode', function (string $configured, string $env, ExecutionMode $expected) {
    expect((new ModeResolver)->resolve($configured, $env))->toBe($expected);
})->with([
    ['auto', 'production', ExecutionMode::Live],
    ['auto', 'local', ExecutionMode::Simulate],
    ['auto', 'staging', ExecutionMode::Simulate],
    ['auto', 'Production', ExecutionMode::Simulate],
    ['live', 'local', ExecutionMode::Live],
    ['simulate', 'production', ExecutionMode::Simulate],
]);

it('rejects an unknown mode', function () {
    expect(fn () => (new ModeResolver)->resolve('yes', 'local'))->toThrow(ConfigurationException::class);
});
