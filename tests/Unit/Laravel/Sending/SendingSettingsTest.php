<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;

it('reads the shipped config file with its documented defaults', function () {
    $settings = SendingSettings::fromArray(require dirname(__DIR__, 4).'/config/mobile-push.php');

    expect($settings->scope)->toBe('default')
        ->and($settings->mode)->toBe('auto')
        ->and($settings->defaultConnection)->toBeNull()
        ->and($settings->routes)->toBeNull()
        ->and($settings->retry->maxAttempts)->toBe(5)
        ->and($settings->retry->unknownOutcome)->toBe(UnknownOutcomeRetry::Replaceable)
        ->and($settings->retry->inlineThresholdMs)->toBe(0)
        ->and($settings->fcmRatePerMinute)->toBe(500_000)
        ->and($settings->apnsRatePerMinute)->toBeNull()
        ->and($settings->apnsServerErrorRetryDelay)->toBe(900)
        ->and($settings->fcmThrottledMinDelay)->toBe(60)
        ->and($settings->deliveryProfile)->toBe('light')
        ->and($settings->perResultEvents)->toBe('auto')
        ->and($settings->connectionCacheSize)->toBe(64)
        ->and($settings->transport['window'])->toBe(100);
});

it('accepts values the way env() delivers them', function () {
    $settings = SendingSettings::fromArray([
        'mode' => 'LIVE',
        'routes' => 'ios, android,ios',
        'retry' => ['max_attempts' => '3', 'jitter' => '0'],
        'unknown_outcome' => ['retry' => 'never'],
        'guards' => ['rate' => ['fcm' => '1200', 'apns' => '600', 'burst' => null]],
        'events' => ['per_result' => 'always'],
    ]);

    expect($settings->mode)->toBe('live')
        ->and($settings->routes)->toBe(['ios', 'android'])
        ->and($settings->retry->maxAttempts)->toBe(3)
        ->and($settings->retry->jitter)->toBe(0.0)
        ->and($settings->retry->unknownOutcome)->toBe(UnknownOutcomeRetry::Never)
        ->and($settings->fcmRatePerMinute)->toBe(1200)
        ->and($settings->apnsRatePerMinute)->toBe(600)
        ->and($settings->perResultEvents)->toBe('always');
});

dataset('invalid settings', [
    'unknown mode' => [['mode' => 'production'], 'mobile-push.mode must be one of: auto, live, simulate'],
    'zero attempts' => [['retry' => ['max_attempts' => 0]], 'mobile-push.retry.max_attempts must be an integer >= 1'],
    'jitter above one' => [['retry' => ['jitter' => 2]], 'mobile-push.retry.jitter must be a number between 0 and 1'],
    'non-numeric rate' => [['guards' => ['rate' => ['fcm' => 'lots']]], 'mobile-push.guards.rate.fcm must be an integer >= 1'],
    'unknown outcome policy' => [['unknown_outcome' => ['retry' => 'sometimes']], 'mobile-push.unknown_outcome.retry must be one of: never, replaceable, always'],
    'unknown profile' => [['delivery' => ['profile' => 'heavy']], 'mobile-push.delivery.profile must be one of: light, durable'],
    'unknown event policy' => [['events' => ['per_result' => 'often']], 'mobile-push.events.per_result must be one of: auto, always, never'],
    'bad scope' => [['scope' => 'tenant one'], 'mobile-push.scope may contain only'],
    'window too big' => [['transport' => ['window' => 20000]], 'mobile-push.transport.window must be an integer between 1 and 10000'],
    'routes not a list' => [['routes' => 5], 'mobile-push.routes must be a list of names'],
]);

it('refuses an invalid setting with the key and the allowed values', function (array $config, string $message) {
    expect(fn () => SendingSettings::fromArray($config))->toThrow(ConfigurationException::class, $message);
})->with('invalid settings');
