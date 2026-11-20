<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfigV2;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidPriority;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

function orderConfirmation(): PushMessage
{
    return PushMessage::make('Order shipped', 'Your parcel is on its way')
        ->image('https://cdn.example.test/parcel.png')
        ->data(['order_id' => 1234, 'gift' => true, 'note' => null])
        ->badge(3)
        ->sound('chime')
        ->actionCategory('OPEN_ORDER')
        ->group('orders')
        ->collapse('order-1234')
        ->deepLink('myapp://orders/1234');
}

it('maps the unified message onto an FCM notification message', function () {
    $compiled = FcmKit::compile(orderConfirmation(), context: FcmKit::context(receiptMarker: 'r3c31pt'));

    expect(FcmKit::decode($compiled)['message'])->toBe([
        'token' => FcmKit::token()->value(),
        'notification' => [
            'title' => 'Order shipped',
            'body' => 'Your parcel is on its way',
            'image' => 'https://cdn.example.test/parcel.png',
        ],
        'data' => ['order_id' => '1234', 'gift' => 'true', 'deep_link' => 'myapp://orders/1234', 'mp_r' => 'r3c31pt'],
        'android' => [
            'collapse_key' => 'order-1234',
            'priority' => 'HIGH',
            'notification' => ['notification_count' => 3, 'sound' => 'chime', 'click_action' => 'OPEN_ORDER', 'tag' => 'orders'],
        ],
    ])
        ->and($compiled->warnings)->toBe(['Data key "note" has a null value and was dropped.']);
});

it('sends normal priority when the unified message asks for it', function () {
    $message = PushMessage::make('Digest', 'Seven new items')->priority(MessagePriority::Normal);

    expect(FcmKit::decode(FcmKit::compile($message))['message']['android'])->toBe(['priority' => 'NORMAL']);
});

it('turns a silent message into a data-only message with normal priority', function () {
    $message = PushMessage::make()->silent()->data(['sync' => 'contacts'])->collapse('sync');

    $json = FcmKit::decode(FcmKit::compile($message))['message'];

    expect($json)->toBe([
        'token' => FcmKit::token()->value(),
        'data' => ['sync' => 'contacts'],
        'android' => ['collapse_key' => 'sync', 'priority' => 'NORMAL'],
        'apns' => [
            'headers' => ['apns-priority' => '5', 'apns-push-type' => 'background'],
            'payload' => ['aps' => ['content-available' => 1]],
        ],
    ])->and($json)->not->toHaveKey('notification');
});

it('refuses a silent message that also carries visible content, unless lenient', function () {
    $message = PushMessage::make('Hi', 'there')->silent()->sound('chime');

    expect(fn () => FcmKit::compile($message))->toThrow(InvalidMessageException::class, 'title, body, sound');

    $compiled = FcmKit::compile($message->lenient());
    expect(FcmKit::decode($compiled)['message'])->not->toHaveKey('notification')
        ->and($compiled->warnings)->toBe(['Silent message: dropped user-visible fields (title, body, sound).']);
});

it('merges the fcm overlay on top of the compiled unified message', function () {
    $message = orderConfirmation()
        ->fcm(fn (FcmMessage $m) => $m
            ->android(fn (AndroidConfig $a) => $a
                ->priority(AndroidPriority::Normal)
                ->restrictedPackageName('com.example.shop')
                ->notification(fn (AndroidNotification $n) => $n->channelId('orders')->sound('override')))
            ->data(['extra' => 'x'])
            ->analyticsLabel('order-shipped'));

    expect(FcmKit::decode(FcmKit::compile($message))['message'])->toBe([
        'token' => FcmKit::token()->value(),
        'notification' => [
            'title' => 'Order shipped',
            'body' => 'Your parcel is on its way',
            'image' => 'https://cdn.example.test/parcel.png',
        ],
        'data' => ['order_id' => '1234', 'gift' => 'true', 'extra' => 'x', 'deep_link' => 'myapp://orders/1234'],
        'android' => [
            'collapse_key' => 'order-1234',
            'priority' => 'NORMAL',
            'notification' => ['notification_count' => 3, 'sound' => 'override', 'click_action' => 'OPEN_ORDER', 'tag' => 'orders', 'channel_id' => 'orders'],
            'restricted_package_name' => 'com.example.shop',
        ],
        'fcm_options' => ['analytics_label' => 'order-shipped'],
    ]);
});

it('does not change the result when the overlay is empty', function () {
    $plain = FcmKit::compile(orderConfirmation())->body;
    $withEmptyOverlay = FcmKit::compile(orderConfirmation()->fcm(fn (FcmMessage $m) => null))->body;

    expect($withEmptyOverlay)->toBe($plain);
});

it('does not send policy, incrementBadge or bypassFrequencyCap', function () {
    $message = PushMessage::make('A', 'B')->policy('marketing')->incrementBadge()->bypassFrequencyCap()->replaceable();

    expect(FcmKit::decode(FcmKit::compile($message))['message'])->toBe([
        'token' => FcmKit::token()->value(),
        'notification' => ['title' => 'A', 'body' => 'B'],
        'android' => ['priority' => 'HIGH'],
    ]);
});

it('maps the unified fields into android_v2 when the overlay selects it', function () {
    $message = PushMessage::make('Hi', 'there')
        ->sound('chime')
        ->collapse('k')
        ->fcm(fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->directBootOk()->mutableContent()));

    expect(FcmKit::decode(FcmKit::compile($message))['message'])->toBe([
        'token' => FcmKit::token()->value(),
        'notification' => ['title' => 'Hi', 'body' => 'there'],
        'android_v2' => [
            'collapse_key' => 'k',
            'remote_notification' => ['notification' => ['sound' => 'chime'], 'mutable_content' => true],
            'direct_boot_ok' => true,
        ],
    ]);
});

it('uses background_sync for a silent message on android_v2 and warns about lost priority', function () {
    $silent = PushMessage::make()->silent()->data(['a' => 'b'])
        ->fcm(fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->directBootOk(false)));
    $normal = PushMessage::make('x', 'y')->priority(MessagePriority::Normal)
        ->fcm(fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->directBootOk(false)));

    $compiledSilent = FcmKit::compile($silent);
    $compiledNormal = FcmKit::compile($normal);

    expect($compiledSilent->body)->toContain('"background_sync":{}')
        ->and($compiledNormal->warnings)->toBe(['android_v2 has no priority field: NORMAL priority was not applied.']);
});

it('rejects android and android_v2 together', function () {
    $builder = FcmMessage::make('a', 'b')->android(fn (AndroidConfig $c) => $c->collapseKey('k'));

    expect(fn () => $builder->androidV2(fn (AndroidConfigV2 $c) => $c->collapseKey('k')))
        ->toThrow(InvalidMessageException::class, 'mutually exclusive');

    // and the other way round
    $v2 = FcmMessage::make('a', 'b')->androidV2(fn (AndroidConfigV2 $c) => $c->collapseKey('k'));
    expect(fn () => $v2->android(fn (AndroidConfig $c) => $c->collapseKey('k')))
        ->toThrow(InvalidMessageException::class, 'mutually exclusive');
});

it('rejects the conflict at compile time too, even if both reach the compiler through a back door', function () {
    $message = FcmMessage::make('a', 'b')->android(fn (AndroidConfig $c) => $c->collapseKey('k'));
    (new ReflectionProperty(FcmMessage::class, 'androidV2'))->setValue($message, AndroidConfigV2::make()->collapseKey('k'));

    expect(fn () => FcmKit::compile($message))->toThrow(InvalidMessageException::class, 'mutually exclusive');
});

it('keeps the unified mapping out of android when the overlay selects android_v2', function () {
    $push = PushMessage::make('a', 'b')->fcm(fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $c) => $c->collapseKey('k')));

    expect(FcmKit::decode(FcmKit::compile($push))['message'])->not->toHaveKey('android');
});

dataset('ios only capabilities', [
    'subtitle' => [fn (PushMessage $m) => $m->subtitle('Sub'), 'subtitle'],
    'critical' => [fn (PushMessage $m) => $m->critical(), 'critical'],
    'timeSensitive' => [fn (PushMessage $m) => $m->timeSensitive(), 'timeSensitive'],
    'mutableContent' => [fn (PushMessage $m) => $m->mutableContent(), 'mutableContent'],
]);

it('fails loudly on iOS-only capabilities and names the remedy', function (Closure $apply, string $name) {
    $message = $apply(PushMessage::make('Hi', 'there'));

    expect(fn () => FcmKit::compile($message))
        ->toThrow(UnsupportedCapabilityException::class, $name);
    expect(fn () => FcmKit::compile($message))
        ->toThrow(UnsupportedCapabilityException::class, '->lenient()');
})->with('ios only capabilities');

it('drops iOS-only capabilities with one warning each in lenient mode', function () {
    $message = PushMessage::make('Hi', 'there')->subtitle('Sub')->critical()->timeSensitive()->mutableContent()->lenient();

    $compiled = FcmKit::compile($message);

    expect(FcmKit::decode($compiled)['message'])->toBe([
        'token' => FcmKit::token()->value(),
        'notification' => ['title' => 'Hi', 'body' => 'there'],
        'android' => ['priority' => 'HIGH'],
    ])->and($compiled->warnings)->toBe([
        'Dropped iOS-only capability "subtitle": FCM has no equivalent (lenient mode).',
        'Dropped iOS-only capability "critical": FCM has no equivalent (lenient mode).',
        'Dropped iOS-only capability "timeSensitive": FCM has no equivalent (lenient mode).',
        'Dropped iOS-only capability "mutableContent": FCM has no equivalent (lenient mode).',
    ]);
});

it('compiles a realistic native message with apns override and analytics label', function () {
    $message = FcmMessage::make('Match starts', 'Kick-off in 5 minutes')
        ->image('https://cdn.example.test/match.png')
        ->data(['match' => 42])
        ->android(fn (AndroidConfig $a) => $a->priority(AndroidPriority::High)->directBootOk()->notification(fn (AndroidNotification $n) => $n->channelId('live')->color('#FF8800')))
        ->apns(fn ($a) => $a->header('apns-priority', 10)->payload(['aps' => ['sound' => 'default']])->image('https://cdn.example.test/ios.png'))
        ->analyticsLabel('kickoff');

    expect(FcmKit::decode(FcmKit::compile($message, FcmKit::topic('match-42')))['message'])->toBe([
        'topic' => 'match-42',
        'notification' => ['title' => 'Match starts', 'body' => 'Kick-off in 5 minutes', 'image' => 'https://cdn.example.test/match.png'],
        'data' => ['match' => '42'],
        'android' => [
            'priority' => 'HIGH',
            'notification' => ['channel_id' => 'live', 'color' => '#FF8800'],
            'direct_boot_ok' => true,
        ],
        'apns' => [
            'headers' => ['apns-priority' => '10'],
            'payload' => ['aps' => ['sound' => 'default']],
            'fcm_options' => ['image' => 'https://cdn.example.test/ios.png'],
        ],
        'fcm_options' => ['analytics_label' => 'kickoff'],
    ]);
});
