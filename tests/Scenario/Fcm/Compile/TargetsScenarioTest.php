<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

/*
 * Story: the same message is sent to the four kinds of FCM target. Exactly one target field appears,
 * it keeps the type the caller gave (a token is never turned into an fid), and the envelope is the REST body.
 */

dataset('fcm targets', [
    'token' => [fn () => FcmKit::token(), 'token', 4096],
    'fid' => [fn () => FcmKit::fid(), 'fid', 4096],
    'topic' => [fn () => FcmKit::topic('weather'), 'topic', 2048],
    'condition' => [fn () => FcmKit::condition(), 'condition', 2048],
]);

it('emits exactly one target field with the original type', function (Closure $destination, string $field, int $limit) {
    $dest = $destination();
    $compiled = FcmKit::compile(PushMessage::make('Hello', 'World'), $dest);
    $json = FcmKit::decode($compiled);

    expect($json['message'])->toBe([
        $field => $dest->value(),
        'notification' => ['title' => 'Hello', 'body' => 'World'],
        'android' => ['priority' => 'HIGH'],
    ])
        ->and(array_intersect(array_keys($json['message']), ['token', 'fid', 'topic', 'condition']))->toBe([$field])
        ->and($json)->not->toHaveKey('validate_only')
        ->and($compiled->headers)->toBe(['content-type' => 'application/json'])
        ->and($compiled->limitBytes)->toBe($limit)
        ->and($compiled->meta['target_type'])->toBe($field)
        ->and($compiled->meta['validate_only'])->toBe('false');
})->with('fcm targets');

it('compiles a native FcmMessage for every target as well', function (Closure $destination, string $field) {
    $dest = $destination();
    $message = FcmMessage::make('T', 'B')->data(['k' => 'v']);

    expect(FcmKit::decode(FcmKit::compile($message, $dest))['message'])->toBe([
        $field => $dest->value(),
        'notification' => ['title' => 'T', 'body' => 'B'],
        'data' => ['k' => 'v'],
    ]);
})->with('fcm targets');

it('rejects APNs destinations before building anything', function (Closure $apnsDestination) {
    FcmKit::compile(PushMessage::make('Hello', 'World'), $apnsDestination());
})->with([
    'device token' => [fn () => ApnsDeviceToken::of(str_repeat('ab', 32))],
    'voip token' => [fn () => ApnsVoipToken::of(str_repeat('cd', 32))],
    'live activity token' => [fn () => ApnsLiveActivityToken::of(str_repeat('ef', 32))],
    'push to start token' => [fn () => ApnsPushToStartToken::of(str_repeat('01', 32))],
    'broadcast channel' => [fn () => ApnsBroadcastChannel::of('dGVzdC1jaGFubmVsLWlkLTAwMDE=')],
])->throws(InvalidDestinationException::class);

it('refuses a condition that combines more than five topics', function () {
    $expression = implode(' && ', array_map(fn (int $i) => "'t{$i}' in topics", range(1, 6)));

    FcmKit::compile(PushMessage::make('Hello', 'World'), FcmKit::condition($expression));
})->throws(InvalidDestinationException::class, 'at most 5 topics');
