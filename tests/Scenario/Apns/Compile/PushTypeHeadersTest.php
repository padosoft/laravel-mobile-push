<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\PushType;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

dataset('push types', [
    'alert' => [fn () => ApnsMessage::alert('Hi', 'There'), 'device', 'alert', 'com.example.app', '10', ['aps' => ['alert' => ['title' => 'Hi', 'body' => 'There']]]],
    'background' => [fn () => ApnsMessage::background(), 'device', 'background', 'com.example.app', '5', ['aps' => ['content-available' => 1]]],
    'voip' => [fn () => ApnsMessage::voip(['callId' => 'c1']), 'voip', 'voip', 'com.example.app.voip', '10', ['aps' => [], 'callId' => 'c1']],
    'complication' => [fn () => ApnsMessage::complication(), 'device', 'complication', 'com.example.app.complication', '10', ['aps' => []]],
    'fileprovider' => [fn () => ApnsMessage::fileProvider(), 'device', 'fileprovider', 'com.example.app.pushkit.fileprovider', '10', ['aps' => []]],
    'location' => [fn () => ApnsMessage::location(), 'device', 'location', 'com.example.app.location-query', '10', ['aps' => []]],
    'mdm' => [fn () => ApnsMessage::mdm('com.apple.mgmt.External.3b6e2f0a-0000-0000-0000-000000000000'), 'device', 'mdm', 'com.apple.mgmt.External.3b6e2f0a-0000-0000-0000-000000000000', '10', ['aps' => []]],
    'pushtotalk' => [fn () => ApnsMessage::pushToTalk(), 'device', 'pushtotalk', 'com.example.app.voip-ptt', '10', ['aps' => []]],
    'widgets' => [fn () => ApnsMessage::widgetsReload(), 'device', 'widgets', 'com.example.app.push-type.widgets', '10', ['aps' => ['content-changed' => true]]],
    'controls' => [fn () => ApnsMessage::controlsReload(), 'device', 'controls', 'com.example.app.push-type.controls', '10', ['aps' => ['content-changed' => true]]],
    'accessory' => [fn () => ApnsMessage::accessory(), 'device', 'accessory', 'com.example.app.push-type.accessory', '10', ['aps' => []]],
]);

it('sends the right header set for every push type', function (Closure $message, string $destination, string $type, string $topic, string $priority, array $payload) {
    $to = $destination === 'voip' ? ApnsKit::voipToken() : ApnsKit::device();

    $compiled = ApnsKit::compiler()->compile($message(), $to, ApnsKit::context(expiresAt: '2026-11-20T12:00:00Z'));

    expect($compiled->headers)->toBe([
        'apns-push-type' => $type,
        'apns-topic' => $topic,
        'apns-priority' => $priority,
        'apns-expiration' => '1795176000',
    ])
        ->and(ApnsKit::decode($compiled))->toEqual($payload)
        ->and($compiled->meta)->toMatchArray(['push_type' => $type, 'topic' => $topic, 'priority' => $priority]);
})->with('push types');

it('encodes an empty aps as a JSON object', function () {
    $compiled = ApnsKit::compiler()->compile(ApnsMessage::complication(), ApnsKit::device(), ApnsKit::context());

    expect($compiled->body)->toBe('{"aps":{}}');
});

it('sends the live activity header set to a device token and to a channel', function () {
    $update = LiveActivityMessage::update(['score' => 3]);

    $device = ApnsKit::compiler()->compile($update, ApnsKit::activityToken(), ApnsKit::context());
    $channel = ApnsKit::compiler()->compile($update, ApnsKit::channel(), ApnsKit::context());

    expect($device->headers)->toBe([
        'apns-push-type' => 'liveactivity',
        'apns-topic' => 'com.example.app.push-type.liveactivity',
        'apns-priority' => '10',
    ])
        ->and($channel->headers)->toBe([
            'apns-push-type' => 'liveactivity',
            'apns-channel-id' => 'dHN0LXNyY2gtY2hubA==',
            'apns-priority' => '10',
            'apns-expiration' => '0',
        ])
        ->and($device->meta['route'])->toBe('device')
        ->and($channel->meta['route'])->toBe('broadcast');
});

it('refuses an mdm push without an explicit topic', function () {
    $message = ApnsMessage::alert()->pushType(PushType::Mdm);

    ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'explicit topic');

it('lets an explicit topic override the derived one', function () {
    $message = ApnsMessage::alert('Hi')->topic('com.example.other');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect($compiled->headers['apns-topic'])->toBe('com.example.other');
});

it('adds the collapse id and the apns-id only when given', function () {
    $message = ApnsMessage::alert('Hi')->collapseId('order-42');
    $id = 'eabeae54-14a8-11e5-b60b-1697f925ec7b';

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context(messageId: $id));

    expect($compiled->headers)->toMatchArray(['apns-collapse-id' => 'order-42', 'apns-id' => $id]);
});
