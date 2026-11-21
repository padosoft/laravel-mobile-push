<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Policy\LiveSettings;

it('accepts a window of exactly 1 and exactly 10,000 requests, and nothing outside', function () {
    expect((new SendOptions(window: 1))->window)->toBe(1)
        ->and((new SendOptions(window: 10_000))->window)->toBe(10_000)
        ->and(fn () => new SendOptions(window: 10_001))->toThrow(ConfigurationException::class);
});

it('replaces the live-sending settings with withLive and keeps everything else', function () {
    $base = new SendOptions(applicationEnvironment: 'staging', configuredMode: 'live', window: 3);
    $live = new LiveSettings(allow: true, allowTopics: true);

    $copy = $base->withLive($live);

    expect($copy->live)->toEqual($live)
        ->and($base->live)->toEqual(new LiveSettings)
        ->and($copy->window)->toBe(3)
        ->and($copy->effectiveMode)->toBe($base->effectiveMode);
});
