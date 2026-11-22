<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;

function jobWithInstant(?int $queuedAt): SendPushJob
{
    return new SendPushJob(
        ['scope' => 'default', 'name' => 'ios', 'provider' => 'apns', 'app_id' => 'com.example.app', 'environment' => 'production', 'generation' => '1'],
        serialize(PushMessage::make('Hi')),
        [],
        'effect-1',
        'effect-1',
        queuedAt: $queuedAt,
    );
}

it('carries the instant it was built at through serialization', function (): void {
    $copy = unserialize(serialize(jobWithInstant(1_700_000_000)));

    expect($copy->queuedAt)->toBe(1_700_000_000);
});

it('an instant is optional: a job built without one has an unknown instant', function (): void {
    expect(jobWithInstant(null)->queuedAt)->toBeNull();
});

it('a payload serialized before the field existed still unserializes, with an unknown instant', function (): void {
    $serialized = serialize(jobWithInstant(1_700_000_000));
    $old = str_replace('s:8:"queuedAt";i:1700000000;', '', $serialized, $removed);
    $old = preg_replace_callback('/^(O:\d+:"[^"]+":)(\d+)/', static fn (array $m): string => $m[1].((int) $m[2] - 1), $old);

    $job = unserialize((string) $old);

    expect($removed)->toBe(1)
        ->and($job)->toBeInstanceOf(SendPushJob::class)
        ->and($job->queuedAt)->toBeNull()
        ->and($job->effectId)->toBe('effect-1');
});
