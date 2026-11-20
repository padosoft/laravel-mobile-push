<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfigV2;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidPriority;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Fcm\Message\LightSettings;
use Padosoft\MobilePush\Core\Fcm\Message\NotificationPriority;
use Padosoft\MobilePush\Core\Fcm\Message\NotificationProxy;
use Padosoft\MobilePush\Core\Fcm\Message\NotificationVisibility;
use Padosoft\MobilePush\Core\Message\PushMessage;

it('overlay() records only what is set afterwards', function () {
    expect(FcmMessage::overlay()->toArray())->toBe([])
        ->and(FcmMessage::overlay()->title('x')->toArray())->toBe(['notification' => ['title' => 'x']]);

    $push = PushMessage::make('a', 'b')->fcm(fn (FcmMessage $m) => $m->analyticsLabel('l'));
    expect($push->fcmOverlay?->toArray())->toBe(['fcm_options' => ['analytics_label' => 'l']]);
});

it('survives serialization without losing anything and without closures', function () {
    $message = FcmMessage::make('t', 'b')
        ->android(fn (AndroidConfig $a) => $a->priority(AndroidPriority::High)->notification(
            fn (AndroidNotification $n) => $n->lightSettings(LightSettings::of(1.0, 0.5, 0.0, 1, 0.5))->vibrateTimings([0, '0.5s', 1.25]),
        ))
        ->apns(fn ($a) => $a->payload(['aps' => ['badge' => 1]]));

    $copy = unserialize(serialize($message));

    expect($copy)->toEqual($message)
        ->and($copy->toArray())->toBe($message->toArray())
        ->and(serialize($message))->not->toContain('Closure');
});

it('records the full android notification surface as wire names', function () {
    $n = AndroidNotification::make()
        ->title('t')->body('b')->icon('ic')->color('#a1B2c3')->sound('default')->tag('tag')->clickAction('OPEN')
        ->bodyLocKey('bk')->bodyLocArgs(['1', '2'])->titleLocKey('tk')->titleLocArgs(['x'])
        ->channelId('ch')->ticker('tick')->sticky()->eventTime(new DateTimeImmutable('2026-11-20T10:00:00+01:00'))
        ->localOnly()->notificationPriority(NotificationPriority::Max)->defaultSound()->defaultVibrateTimings()
        ->vibrateTimings([0, 0.25, '1s'])->defaultLightSettings()->lightSettings(LightSettings::of(0.1, 0.2, 0.3, 1, 2, 0.5))
        ->visibility(NotificationVisibility::Public)->notificationCount(7)->image('https://x.test/i.png')
        ->proxy(NotificationProxy::Deny)->bypassProxyNotification();

    expect($n->toArray())->toBe([
        'title' => 't', 'body' => 'b', 'icon' => 'ic', 'color' => '#a1B2c3', 'sound' => 'default', 'tag' => 'tag',
        'click_action' => 'OPEN', 'body_loc_key' => 'bk', 'body_loc_args' => ['1', '2'], 'title_loc_key' => 'tk',
        'title_loc_args' => ['x'], 'channel_id' => 'ch', 'ticker' => 'tick', 'sticky' => true,
        'event_time' => '2026-11-20T09:00:00Z', 'local_only' => true, 'notification_priority' => 'PRIORITY_MAX',
        'default_sound' => true, 'default_vibrate_timings' => true, 'vibrate_timings' => ['0s', '0.25s', '1s'],
        'default_light_settings' => true,
        'light_settings' => ['color' => ['red' => 0.1, 'green' => 0.2, 'blue' => 0.3, 'alpha' => 0.5], 'light_on_duration' => '1s', 'light_off_duration' => '2s'],
        'visibility' => 'PUBLIC', 'notification_count' => 7, 'image' => 'https://x.test/i.png',
        'proxy' => 'DENY', 'bypass_proxy_notification' => true,
    ]);
});

it('keeps V1-only and V2-only notification fields apart', function () {
    expect(fn () => AndroidNotification::make()->proxy(NotificationProxy::Allow)->toArray(true))->toThrow(InvalidMessageException::class, 'proxy')
        ->and(fn () => AndroidNotification::make()->bypassProxyNotification()->toArray(true))->toThrow(InvalidMessageException::class)
        ->and(fn () => AndroidNotification::make()->id(5)->toArray(false))->toThrow(InvalidMessageException::class, '"id"')
        ->and(AndroidNotification::make()->id(5)->toArray(true))->toBe(['id' => 5]);
});

it('rejects malformed field values immediately', function (Closure $build) {
    $build();
})->with([
    'ttl without suffix' => [fn () => AndroidConfig::make()->ttl('3600')],
    'ttl negative' => [fn () => AndroidConfig::make()->ttl(-1)],
    'ttl 10 decimals' => [fn () => AndroidConfig::make()->ttl('1.0000000001s')],
    'color without hash' => [fn () => AndroidNotification::make()->color('ff0000')],
    'color short' => [fn () => AndroidNotification::make()->color('#f00')],
    'negative count' => [fn () => AndroidNotification::make()->notificationCount(-1)],
    'bad event time' => [fn () => AndroidNotification::make()->eventTime('yesterday')],
    'empty channel' => [fn () => AndroidNotification::make()->channelId('')],
    'light component > 1' => [fn () => LightSettings::of(1.5, 0, 0, 1, 1)],
    'empty collapse key' => [fn () => AndroidConfig::make()->collapseKey('')],
    'empty live activity token' => [fn () => FcmMessage::make()->apns(fn ($a) => $a->liveActivityToken(''))],
])->throws(InvalidMessageException::class);

it('formats durations canonically', function () {
    expect(AndroidConfig::make()->ttl(3600)->toArray())->toBe(['ttl' => '3600s'])
        ->and(AndroidConfig::make()->ttl(0)->toArray())->toBe(['ttl' => '0s'])
        ->and(AndroidConfig::make()->ttl(1.5)->toArray())->toBe(['ttl' => '1.5s'])
        ->and(AndroidConfig::make()->ttl('86400s')->toArray())->toBe(['ttl' => '86400s']);
});

it('models android_v2 as the Discovery schema defines it', function () {
    $v2 = AndroidConfigV2::make()
        ->collapseKey('c')->ttl(60)->restrictedPackageName('com.example')->data(['a' => 1])
        ->notification(fn (AndroidNotification $n) => $n->title('t')->id(3))
        ->mutableContent()->useAsV1DataMessage(false)->backgroundSync()->analyticsLabel('l')
        ->directBootOk()->bandwidthConstrainedOk()->restrictedSatelliteOk();

    $array = $v2->toArray();

    expect($array['remote_notification'])->toBe(['mutable_content' => true, 'notification' => ['title' => 't', 'id' => 3], 'use_as_v1_data_message' => false])
        ->and($array['background_sync'])->toEqual(new stdClass)
        ->and($array['data'])->toBe(['a' => '1'])
        ->and($array['fcm_options'])->toBe(['analytics_label' => 'l'])
        ->and(array_keys($array))->not->toContain('priority');
});
