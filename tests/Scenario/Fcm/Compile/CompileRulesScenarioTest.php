<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Fcm\FcmCompiler;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfigV2;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\FcmApnsConfig;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

/*
 * Rules of the FCM compiler that decide what goes on the wire: which messages it accepts, the silent-message
 * rules, android versus android_v2, the TTL arithmetic, the data maps, the truncation paths and the counting rule.
 */

final class M4FcmForeignMessage implements Message {}

it('accepts the unified and the native FCM message and nothing else', function () {
    $compiler = new FcmCompiler;

    expect($compiler->supports(PushMessage::make('x')))->toBeTrue()
        ->and($compiler->supports(FcmMessage::make('x')))->toBeTrue()
        ->and($compiler->supports(ApnsMessage::alert('x')))->toBeFalse()
        ->and($compiler->supports(new M4FcmForeignMessage))->toBeFalse();
});

it('refuses a message type it does not know and names it', function () {
    expect(fn () => FcmKit::compile(new M4FcmForeignMessage))
        ->toThrow(InvalidMessageException::class, 'The FCM compiler supports PushMessage and FcmMessage, got '.M4FcmForeignMessage::class.'.');
});

it('reports invalid UTF-8 even when the payload is also over the limit', function () {
    $message = PushMessage::make('title', "bad \xB1 ".str_repeat('a', 5000));

    expect(fn () => FcmKit::compile($message))
        ->not->toThrow(PayloadTooLargeException::class)
        ->and(fn () => FcmKit::compile($message))
        ->toThrow(InvalidMessageException::class, 'UTF-8');
});

// --- silent messages -------------------------------------------------------------------------------------------

dataset('m4 fcm silent conflicts', [
    'title' => [fn () => PushMessage::make('T')->silent(), 'title'],
    'body' => [fn () => PushMessage::make(null, 'B')->silent(), 'body'],
    'image' => [fn () => PushMessage::make()->image('https://cdn.example.test/a.png')->silent(), 'image'],
    'sound' => [fn () => PushMessage::make()->sound('chime')->silent(), 'sound'],
    'badge' => [fn () => PushMessage::make()->badge(4)->silent(), 'badge'],
    'actionCategory' => [fn () => PushMessage::make()->actionCategory('OPEN')->silent(), 'actionCategory'],
    'group' => [fn () => PushMessage::make()->group('orders')->silent(), 'group'],
    'several' => [fn () => PushMessage::make('T', 'B')->image('https://cdn.example.test/a.png')->silent(), 'title, body, image'],
]);

it('names every user-visible field a strict silent message carries', function (Closure $message, string $names) {
    expect(fn () => FcmKit::compile($message()->data(['k' => 'v'])))
        ->toThrow(InvalidMessageException::class, "A silent message is data-only and cannot carry user-visible fields ({$names}). Remove them or drop ->silent().");
})->with('m4 fcm silent conflicts');

it('names every user-visible field a lenient silent message drops and sends the data only', function (Closure $message, string $names) {
    $compiled = FcmKit::compile($message()->data(['k' => 'v'])->lenient());

    expect($compiled->warnings)->toBe(["Silent message: dropped user-visible fields ({$names})."])
        ->and(FcmKit::decode($compiled)['message'])->toBe([
            'token' => FcmKit::token()->value(),
            'data' => ['k' => 'v'],
            'android' => ['priority' => 'NORMAL'],
            'apns' => ['headers' => ['apns-priority' => '5', 'apns-push-type' => 'background'], 'payload' => ['aps' => ['content-available' => 1]]],
        ]);
})->with('m4 fcm silent conflicts');

// --- android_v2 ------------------------------------------------------------------------------------------------

function m4V2(PushMessage $message): PushMessage
{
    return $message->fcm(fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->directBootOk()));
}

it('warns that android_v2 cannot carry a normal priority, and only then', function (Closure $message, array $warnings) {
    expect(FcmKit::compile($message())->warnings)->toBe($warnings);
})->with([
    'normal priority' => [fn () => m4V2(PushMessage::make('a', 'b')->priority(MessagePriority::Normal)), ['android_v2 has no priority field: NORMAL priority was not applied.']],
    'high priority' => [fn () => m4V2(PushMessage::make('a', 'b')->priority(MessagePriority::High)), []],
    'silent message' => [fn () => m4V2(PushMessage::make()->silent()->data(['k' => 'v'])), []],
]);

it('maps a silent message onto android_v2 as background_sync', function () {
    $compiled = FcmKit::compile(m4V2(PushMessage::make()->silent()->data(['k' => 'v'])->collapse('sync')));

    expect($compiled->body)->toContain('"android_v2":{"collapse_key":"sync","background_sync":{},"direct_boot_ok":true}')
        ->and(FcmKit::decode($compiled)['message'])->not->toHaveKey('notification');
});

it('maps badge, sound, category and group into the android_v2 notification', function () {
    $message = m4V2(PushMessage::make('a', 'b')->badge(2)->sound('chime')->actionCategory('OPEN')->group('orders'));

    expect(FcmKit::decode(FcmKit::compile($message))['message']['android_v2'])->toBe([
        'remote_notification' => ['notification' => ['notification_count' => 2, 'sound' => 'chime', 'click_action' => 'OPEN', 'tag' => 'orders']],
        'direct_boot_ok' => true,
    ]);
});

// --- condition topics ------------------------------------------------------------------------------------------

it('accepts a condition of five topics and refuses six', function () {
    $condition = fn (int $n) => FcmKit::condition(implode(' || ', array_map(fn (int $i) => "'topic{$i}' in topics", range(1, $n))));

    expect(FcmKit::decode(FcmKit::compile(PushMessage::make('a', 'b'), $condition(5)))['message'])->toHaveKey('condition')
        ->and(fn () => FcmKit::compile(PushMessage::make('a', 'b'), $condition(6)))
        ->toThrow(InvalidDestinationException::class, 'An FCM condition can combine at most 5 topics, got 6.');
});

// --- data maps -------------------------------------------------------------------------------------------------

it('writes the receipt marker and the deep link into every data map the message carries', function (string $section, Closure $overlay) {
    $message = PushMessage::make('a', 'b')->deepLink('app://x')->fcm($overlay);

    $json = FcmKit::decode(FcmKit::compile($message, context: FcmKit::context(receiptMarker: 'rcpt-1')))['message'];

    expect($json['data'])->toBe(['deep_link' => 'app://x', 'mp_r' => 'rcpt-1'])
        ->and($json[$section]['data'])->toBe(['own' => 'v', 'deep_link' => 'app://x', 'mp_r' => 'rcpt-1']);
})->with([
    'android' => ['android', fn (FcmMessage $m) => $m->android(fn (AndroidConfig $a) => $a->data(['own' => 'v']))],
    'android_v2' => ['android_v2', fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->data(['own' => 'v']))],
]);

it('refuses a platform data map that holds another value under a key the package writes', function (string $label, Closure $overlay, string $key) {
    $message = PushMessage::make('a', 'b')->deepLink('app://x')->fcm($overlay);

    expect(fn () => FcmKit::compile($message, context: FcmKit::context(receiptMarker: 'rcpt-1')))
        ->toThrow(InvalidMessageException::class, "{$label} key \"{$key}\" collides with the");
})->with([
    'android receipt marker' => ['android.data', fn (FcmMessage $m) => $m->android(fn (AndroidConfig $a) => $a->data(['mp_r' => 'other'])), 'mp_r'],
    'android deep link' => ['android.data', fn (FcmMessage $m) => $m->android(fn (AndroidConfig $a) => $a->data(['deep_link' => 'app://other'])), 'deep_link'],
    'android_v2 receipt marker' => ['android_v2.data', fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->data(['mp_r' => 'other'])), 'mp_r'],
    'android_v2 deep link' => ['android_v2.data', fn (FcmMessage $m) => $m->androidV2(fn (AndroidConfigV2 $a) => $a->data(['deep_link' => 'app://other'])), 'deep_link'],
]);

it('accepts a platform data map that already holds the very value the package writes', function () {
    $message = PushMessage::make('a', 'b')->fcm(fn (FcmMessage $m) => $m->android(fn (AndroidConfig $a) => $a->data(['mp_r' => 'rcpt-1'])));

    $json = FcmKit::decode(FcmKit::compile($message, context: FcmKit::context(receiptMarker: 'rcpt-1')))['message'];

    expect($json['android']['data'])->toBe(['mp_r' => 'rcpt-1']);
});

it('encodes every data map and apns map as a JSON object, even when its keys look like a list', function () {
    $message = FcmMessage::make('a', 'b')
        ->android(fn (AndroidConfig $a) => $a->data(['0' => 'x']))
        ->apns(fn (FcmApnsConfig $a) => $a->header('0', 'x')->payload(['0' => 'x']));
    $v2 = FcmMessage::make('a', 'b')->androidV2(fn (AndroidConfigV2 $a) => $a->data(['0' => 'x']));
    $apnsOnly = FcmMessage::make('a', 'b')->apns(fn (FcmApnsConfig $a) => $a->header('0', 'x'));

    expect(FcmKit::compile($message)->body)->toContain('"android":{"data":{"0":"x"}}')
        ->toContain('"headers":{"0":"x"}')->toContain('"payload":{"0":"x"}')
        ->and(FcmKit::compile($v2)->body)->toContain('"android_v2":{"data":{"0":"x"}}')
        ->and(FcmKit::compile($apnsOnly)->body)->toContain('"apns":{"headers":{"0":"x"}}');
});

it('encodes a platform section that was set but left empty as an empty object', function () {
    $android = FcmMessage::make('a', 'b')->android(fn (AndroidConfig $a) => null);
    $v2 = FcmMessage::make('a', 'b')->androidV2(fn (AndroidConfigV2 $a) => null);
    $apns = FcmMessage::make('a', 'b')->apns(fn (FcmApnsConfig $a) => null);

    expect(FcmKit::compile($android)->body)->toEndWith('"android":{}}}')
        ->and(FcmKit::compile($v2)->body)->toEndWith('"android_v2":{}}}')
        ->and(FcmKit::compile($apns)->body)->toEndWith('"apns":{}}}');
});

// --- ttl -------------------------------------------------------------------------------------------------------

it('computes the remaining ttl to the microsecond and rounds down', function (string $now, string $expiresAt, string $ttl) {
    $compiled = FcmKit::compile(PushMessage::make('a', 'b'), context: FcmKit::context($now, $expiresAt));

    expect(FcmKit::decode($compiled)['message']['android']['ttl'])->toBe($ttl);
})->with([
    'a microsecond short of ten seconds' => ['2026-11-20T10:00:00.000000Z', '2026-11-20T10:00:09.999999Z', '9s'],
    'a microsecond short of a second' => ['2026-11-20T10:00:00.000000Z', '2026-11-20T10:00:00.999999Z', '0s'],
    'exactly a second' => ['2026-11-20T10:00:00.000000Z', '2026-11-20T10:00:01.000000Z', '1s'],
    'a day and a half' => ['2026-11-20T10:00:00.500000Z', '2026-11-21T22:00:00.500000Z', '129600s'],
]);

it('accepts a remaining lifetime of exactly four weeks and caps one second more', function () {
    $exact = FcmKit::compile(PushMessage::make('a', 'b'), context: FcmKit::context('2026-11-20T10:00:00Z', '2026-12-18T10:00:00Z'));
    $over = FcmKit::compile(PushMessage::make('a', 'b'), context: FcmKit::context('2026-11-20T10:00:00Z', '2026-12-18T10:00:01Z'));

    expect(FcmKit::decode($exact)['message']['android']['ttl'])->toBe('2419200s')
        ->and($exact->warnings)->toBe([])
        ->and(FcmKit::decode($over)['message']['android']['ttl'])->toBe('2419200s')
        ->and($over->warnings)->toBe(['The remaining lifetime exceeds the FCM maximum of 4 weeks: ttl capped at 2419200s.']);
});

// --- truncation ------------------------------------------------------------------------------------------------

dataset('m4 fcm text locations', [
    'notification' => [
        fn (string $title, string $body) => FcmMessage::make($title, $body),
        ['notification'],
        'notification',
    ],
    'android.notification' => [
        fn (string $title, string $body) => FcmMessage::make()->android(fn (AndroidConfig $a) => $a->notification(fn (AndroidNotification $n) => $n->title($title)->body($body))),
        ['android', 'notification'],
        'android.notification',
    ],
    'android_v2.remote_notification.notification' => [
        fn (string $title, string $body) => FcmMessage::make()->androidV2(fn (AndroidConfigV2 $a) => $a->notification(fn (AndroidNotification $n) => $n->title($title)->body($body))),
        ['android_v2', 'remote_notification', 'notification'],
        'android_v2.remote_notification.notification',
    ],
]);

it('shortens only the body when the body alone is too long and says which field changed', function (Closure $build, array $path, string $name) {
    $message = $build('Short title', str_repeat('b', 4500))->truncate();

    $compiled = FcmKit::compile($message);
    $node = FcmKit::decode($compiled)['message'];
    foreach ($path as $segment) {
        $node = $node[$segment];
    }

    expect($node['title'])->toBe('Short title')
        ->and($node['body'])->toEndWith('…')
        ->and($compiled->payloadBytes)->toBeLessThanOrEqual(4096)
        ->and($compiled->warnings)->toHaveCount(1)
        ->and($compiled->warnings[0])->toMatch('/^Truncated to fit 4096 bytes \(was \d+\): '.preg_quote($name, '/').'\.body\.$/');
})->with('m4 fcm text locations');

it('shortens the title when the title is what is too long', function (Closure $build, array $path, string $name) {
    $message = $build(str_repeat('t', 4500), '')->truncate();

    $compiled = FcmKit::compile($message);
    $node = FcmKit::decode($compiled)['message'];
    foreach ($path as $segment) {
        $node = $node[$segment];
    }

    expect($node['title'])->toEndWith('…')
        ->and($compiled->payloadBytes)->toBeLessThanOrEqual(4096)
        ->and($compiled->warnings[0])->toContain($name.'.title');
})->with('m4 fcm text locations');

it('lists every field it had to shorten, body before title', function () {
    $message = FcmMessage::make(str_repeat('t', 4200), str_repeat('b', 3000))->truncate();

    $compiled = FcmKit::compile($message);

    expect($compiled->payloadBytes)->toBeLessThanOrEqual(4096)
        ->and($compiled->warnings)->toHaveCount(1)
        ->and($compiled->warnings[0])->toMatch('/^Truncated to fit 4096 bytes \(was 7209\): notification\.body, notification\.title\.$/');
});

// --- counting rule ---------------------------------------------------------------------------------------------

it('counts the key and value of title, body and image of an android notification, and nothing else of it', function () {
    $message = FcmMessage::make()->android(fn (AndroidConfig $a) => $a->notification(fn (AndroidNotification $n) => $n
        ->title('abc')->body('de')->image('https://x/i.png')->sound('chime')->channelId('orders')));

    // title: 5 + 3, body: 4 + 2, image: 5 + 15. sound and channel_id are not counted.
    expect(FcmKit::compile($message)->payloadBytes)->toBe(8 + 6 + 20);
});

it('counts the key and value of title, body and image of an android_v2 notification, and nothing else of it', function () {
    $message = FcmMessage::make()->androidV2(fn (AndroidConfigV2 $a) => $a->notification(fn (AndroidNotification $n) => $n
        ->title('abc')->body('de')->image('https://x/i.png')->sound('chime'))->data(['k' => 'vv']));

    // title: 5 + 3, body: 4 + 2, image: 5 + 15, data: 1 + 2.
    expect(FcmKit::compile($message)->payloadBytes)->toBe(8 + 6 + 20 + 3);
});

it('counts the data of the top level and of the android map separately', function () {
    $message = FcmMessage::make('t', 'b')->data(['k' => 'vv'])->android(fn (AndroidConfig $a) => $a->data(['own' => 'xyz']));

    // title 5+1, body 4+1, data 1+2, android.data 3+3.
    expect(FcmKit::compile($message)->payloadBytes)->toBe(6 + 5 + 3 + 6);
});
