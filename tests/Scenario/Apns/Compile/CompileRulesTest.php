<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\ApnsPayloadLimits;
use Padosoft\MobilePush\Core\Apns\InterruptionLevel;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Apns\PushType;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

/*
 * Rules of the APNs compiler that decide what goes on the wire: which fields a silent message drops, what a
 * background push refuses, the numeric ranges, the expiry header, the Live Activity broadcast rules. Each case
 * asserts the compiled request (headers, body bytes) or the public validation exception.
 */

final class M4ForeignMessage implements Message {}

final class M4Activity implements LiveActivityType
{
    public function swiftName(): string
    {
        return 'M4Attributes';
    }

    public function version(): int
    {
        return 1;
    }
}

function m4Compile(Message $message, ?Destination $to = null, ?CompileContext $context = null): CompiledMessage
{
    return ApnsKit::compiler()->compile($message, $to ?? ApnsKit::device(), $context ?? ApnsKit::context());
}

it('refuses a message type it does not know instead of treating it as an APNs message', function () {
    expect(fn () => m4Compile(new M4ForeignMessage))
        ->toThrow(UnsupportedCapabilityException::class, 'The APNs compiler cannot compile a message of type '.M4ForeignMessage::class.'.');
});

// --- silent messages -------------------------------------------------------------------------------------------

dataset('m4 silent conflicts', [
    'title' => [fn () => PushMessage::make('T')->silent(), 'title'],
    'subtitle' => [fn () => PushMessage::make()->subtitle('S')->silent(), 'subtitle'],
    'body' => [fn () => PushMessage::make(null, 'B')->silent(), 'body'],
    'sound' => [fn () => PushMessage::make()->sound('ping.caf')->silent(), 'sound'],
    'badge' => [fn () => PushMessage::make()->badge(2)->silent(), 'badge'],
    'critical' => [fn () => PushMessage::make()->critical()->silent(), 'critical'],
    'timeSensitive' => [fn () => PushMessage::make()->timeSensitive()->silent(), 'timeSensitive'],
    'title and body' => [fn () => PushMessage::make('T', 'B')->silent(), 'title, body'],
]);

it('names every visible field a strict silent message carries', function (Closure $message, string $names) {
    expect(fn () => m4Compile($message()))
        ->toThrow(InvalidMessageException::class, 'A silent message cannot carry: '.$names.'. Remove them or drop silent().');
})->with('m4 silent conflicts');

it('names every visible field a lenient silent message drops and still sends the background push', function (Closure $message, string $names) {
    $compiled = m4Compile($message()->lenient());

    expect($compiled->warnings)->toBe(['silent message: dropped '.$names.'.'])
        ->and($compiled->body)->toBe('{"aps":{"content-available":1}}')
        ->and($compiled->headers['apns-push-type'])->toBe('background')
        ->and($compiled->headers['apns-priority'])->toBe('5');
})->with('m4 silent conflicts');

it('reports the dropped image of a lenient silent message', function () {
    $compiled = m4Compile(PushMessage::make()->silent()->image('https://cdn.example.com/a.png')->lenient());

    expect($compiled->warnings)->toBe(['silent message: dropped image.'])
        ->and($compiled->body)->toBe('{"aps":{"content-available":1}}');
});

// --- unified message mapping -----------------------------------------------------------------------------------

it('builds an alert from a subtitle alone, a body alone or a title alone', function (Closure $message, array $alert) {
    expect(ApnsKit::decode(m4Compile($message()))['aps'])->toBe(['alert' => $alert]);
})->with([
    'subtitle only' => [fn () => PushMessage::make()->subtitle('Courier: DHL'), ['subtitle' => 'Courier: DHL']],
    'body only' => [fn () => PushMessage::make(null, 'Only a body'), ['body' => 'Only a body']],
    'title only' => [fn () => PushMessage::make('Only a title'), ['title' => 'Only a title']],
]);

it('asks for mutable-content only when the unified message does', function () {
    $with = ApnsKit::decode(m4Compile(PushMessage::make('x')->mutableContent()));
    $without = ApnsKit::decode(m4Compile(PushMessage::make('x')));

    expect($with['aps'])->toBe(['alert' => ['title' => 'x'], 'mutable-content' => 1])
        ->and($without['aps'])->toBe(['alert' => ['title' => 'x']]);
});

it('reports the compiled facts of a notification as strings', function () {
    $compiled = m4Compile(ApnsMessage::alert('Hi')->priority(Priority::Normal));

    expect($compiled->meta)->toBe(['push_type' => 'alert', 'topic' => 'com.example.app', 'priority' => '5', 'route' => 'device']);
});

// --- background pushes -----------------------------------------------------------------------------------------

it('refuses to put user-visible content in a background push and names it', function (Closure $build, string $names) {
    expect(fn () => m4Compile($build()))
        ->toThrow(InvalidMessageException::class, 'A background push cannot carry: '.$names.'.');
})->with([
    'alert' => [fn () => ApnsMessage::background()->withAlert(Alert::make('Hi')), 'alert'],
    'sound' => [fn () => ApnsMessage::background()->sound('ping.caf'), 'sound'],
    'badge' => [fn () => ApnsMessage::background()->badge(1), 'badge'],
    'interruption level' => [fn () => ApnsMessage::background()->interruptionLevel(InterruptionLevel::Passive), 'interruption-level'],
    'all of them' => [fn () => ApnsMessage::background()->withAlert(Alert::make('Hi'))->sound('x')->badge(1)->interruptionLevel(InterruptionLevel::Passive), 'alert, sound, badge, interruption-level'],
]);

it('does not mind an empty alert on a background push', function () {
    $compiled = m4Compile(ApnsMessage::background()->withAlert(new Alert));

    expect($compiled->body)->toBe('{"aps":{"content-available":1}}');
});

it('refuses a background push that turns content-available off', function () {
    expect(fn () => m4Compile(ApnsMessage::background()->contentAvailable(false)))
        ->toThrow(InvalidMessageException::class, 'content-available 1');
});

it('implies content-available on a background push that leaves the flag unset, and only there', function () {
    $background = (new ApnsMessage)->pushType(PushType::Background)->priority(Priority::Normal);
    $alertWithFlagOff = ApnsMessage::alert('Hi')->contentAvailable(false);
    $alertWithFlagUnset = ApnsMessage::alert('Hi');
    $alertWithFlagOn = ApnsMessage::alert('Hi')->contentAvailable(true);

    expect(ApnsKit::decode(m4Compile($background))['aps'])->toBe(['content-available' => 1])
        ->and(ApnsKit::decode(m4Compile($alertWithFlagOff))['aps'])->toBe(['alert' => ['title' => 'Hi']])
        ->and(ApnsKit::decode(m4Compile($alertWithFlagUnset))['aps'])->toBe(['alert' => ['title' => 'Hi']])
        ->and(ApnsKit::decode(m4Compile($alertWithFlagOn))['aps'])->toBe(['alert' => ['title' => 'Hi'], 'content-available' => 1]);
});

it('implies content-changed on widget and control pushes that leave the flag unset, and only there', function () {
    $widgets = (new ApnsMessage)->pushType(PushType::Widgets);
    $controls = (new ApnsMessage)->pushType(PushType::Controls);
    $widgetsOff = (new ApnsMessage)->pushType(PushType::Widgets)->contentChanged(false);
    $accessory = (new ApnsMessage)->pushType(PushType::Accessory);
    $alertOn = ApnsMessage::alert('Hi')->contentChanged(true);

    expect(m4Compile($widgets)->body)->toBe('{"aps":{"content-changed":true}}')
        ->and(m4Compile($controls)->body)->toBe('{"aps":{"content-changed":true}}')
        ->and(m4Compile($widgetsOff)->body)->toBe('{"aps":{}}')
        ->and(m4Compile($accessory)->body)->toBe('{"aps":{}}')
        ->and(ApnsKit::decode(m4Compile($alertOn))['aps'])->toBe(['alert' => ['title' => 'Hi'], 'content-changed' => true]);
});

// --- numeric ranges --------------------------------------------------------------------------------------------

it('refuses a negative badge and accepts zero', function () {
    expect(ApnsKit::decode(m4Compile(ApnsMessage::alert('x')->badge(0)))['aps']['badge'])->toBe(0)
        ->and(fn () => m4Compile(ApnsMessage::alert('x')->badge(-1)))
        ->toThrow(InvalidMessageException::class, 'The badge cannot be negative.');
});

it('accepts a relevance score from 0 to 1 inclusive', function (float $score) {
    $aps = ApnsKit::decode(m4Compile(ApnsMessage::alert('x')->relevanceScore($score)))['aps'];

    expect($aps['relevance-score'])->toEqual($score);
})->with([0.0, 0.25, 0.5, 1.0]);

it('refuses a relevance score outside 0 to 1 or not finite', function (float $score) {
    expect(fn () => m4Compile(ApnsMessage::alert('x')->relevanceScore($score)))
        ->toThrow(InvalidMessageException::class, 'between 0 and 1');
})->with([
    'just below zero' => [-0.0000001],
    'below zero' => [-0.5],
    'far below zero' => [-3.0],
    'just above one' => [1.0000001],
    'above one' => [1.5],
    'far above one' => [7.0],
    'not a number' => [NAN],
    'infinity' => [INF],
    'minus infinity' => [-INF],
]);

// --- custom keys and collapse id -------------------------------------------------------------------------------

it('refuses custom data whose key is empty or not a string', function (Closure $build) {
    expect(fn () => m4Compile($build()))->toThrow(InvalidMessageException::class, 'Custom data keys must be non-empty strings.');
})->with([
    'empty key' => [fn () => ApnsMessage::alert('x')->custom('', 'v')],
    'integer key' => [fn () => ApnsMessage::alert('x')->data([5 => 'v'])],
]);

it('refuses custom data that uses the key of the receipt marker and names the key', function () {
    expect(fn () => m4Compile(ApnsMessage::alert('x')->custom('mp_r', 'mine'), context: ApnsKit::context(receiptMarker: 'rcpt-1')))
        ->toThrow(InvalidMessageException::class, 'The custom data key "mp_r" is reserved for the receipt marker.');
});

it('refuses a collapse id that is empty, not UTF-8 or holds a control character', function (string $id, string $reason) {
    expect(fn () => m4Compile(ApnsMessage::alert('x')->collapseId($id)))
        ->toThrow(InvalidMessageException::class, $reason);
})->with([
    'empty' => ['', 'The collapse id must be 1 to 64 bytes.'],
    'invalid UTF-8' => ["order-\xB1", 'valid UTF-8 without control characters'],
    'NUL' => ["order\x00-1", 'valid UTF-8 without control characters'],
    'tab' => ["order\t1", 'valid UTF-8 without control characters'],
    'DEL' => ["order\x7f1", 'valid UTF-8 without control characters'],
]);

it('counts the collapse id in bytes, not characters', function () {
    $fits = m4Compile(ApnsMessage::alert('x')->collapseId(str_repeat('é', 32)));

    expect($fits->headers['apns-collapse-id'])->toBe(str_repeat('é', 32))
        ->and(fn () => m4Compile(ApnsMessage::alert('x')->collapseId(str_repeat('é', 33))))
        ->toThrow(InvalidMessageException::class, '1 to 64 bytes');
});

// --- size limits -----------------------------------------------------------------------------------------------

it('knows the byte limit of every kind of destination', function (PushType $type, DestinationKind $kind, int $limit) {
    expect(ApnsPayloadLimits::for($type, $kind))->toBe($limit);
})->with([
    'alert to a device' => [PushType::Alert, DestinationKind::ApnsDeviceToken, 4096],
    'voip to a voip token' => [PushType::Voip, DestinationKind::ApnsVoipToken, 5120],
    'live activity to an activity token' => [PushType::LiveActivity, DestinationKind::ApnsLiveActivityToken, 4096],
    'live activity to a broadcast channel' => [PushType::LiveActivity, DestinationKind::ApnsBroadcastChannel, 5120],
    'voip type to a broadcast channel' => [PushType::Voip, DestinationKind::ApnsBroadcastChannel, 5120],
]);

it('says how to shorten an oversized payload, or that there is no text to shorten', function () {
    $oversized = fn (bool $truncate) => ApnsMessage::alert()->badge(1)->custom('pad', str_repeat('p', 5000))->truncate($truncate);

    expect(fn () => m4Compile($oversized(false)))
        ->toThrow(PayloadTooLargeException::class, 'Shorten it or opt in to truncation of title/subtitle/body.')
        ->and(fn () => m4Compile($oversized(true)))
        ->toThrow(PayloadTooLargeException::class, 'There is no alert text to shorten.');
});

it('shortens a title when the title is the only text', function () {
    $message = ApnsMessage::alert(str_repeat('t', 5000))->truncate();

    $compiled = m4Compile($message);
    $title = ApnsKit::decode($compiled)['aps']['alert']['title'];

    expect(strlen($compiled->body))->toBeLessThanOrEqual(4096)
        ->and($title)->toEndWith('…')
        ->and($compiled->warnings)->toHaveCount(1)
        ->and($compiled->warnings[0])->toStartWith('alert title truncated from 5000 to ');
});

it('leaves an empty alert field alone while shortening the others', function () {
    $message = ApnsMessage::alert('', str_repeat('b', 5000))->truncate();

    $compiled = m4Compile($message);
    $alert = ApnsKit::decode($compiled)['aps']['alert'];

    expect($alert['title'])->toBe('')
        ->and($alert['body'])->toEndWith('…')
        ->and($compiled->warnings)->toHaveCount(1)
        ->and($compiled->warnings[0])->toStartWith('alert body truncated');
});

// --- expiry header ---------------------------------------------------------------------------------------------

it('sends apns-expiration 0 for an expiry that is now or already past, the epoch for a future one', function (string $now, string $expiresAt, string $expected) {
    $compiled = m4Compile(ApnsMessage::alert('x'), context: ApnsKit::context($now, $expiresAt));

    expect($compiled->headers['apns-expiration'])->toBe($expected);
})->with([
    'in the future' => ['2026-11-20T10:00:00Z', '2026-11-20T10:00:01Z', '1795168801'],
    'exactly now' => ['2026-11-20T10:00:00Z', '2026-11-20T10:00:00Z', '0'],
    'one second ago' => ['2026-11-20T10:00:00Z', '2026-11-20T09:59:59Z', '0'],
    'long ago' => ['2026-11-20T10:00:00Z', '2020-01-01T00:00:00Z', '0'],
    'before 1970, still in the future' => ['1969-12-31T23:59:50Z', '1969-12-31T23:59:58Z', '0'],
]);

// --- Live Activity ---------------------------------------------------------------------------------------------

it('sends a Live Activity update to a device token with strictly typed headers and meta', function () {
    $id = 'eabeae54-14a8-11e5-b60b-1697f925ec7b';
    $update = LiveActivityMessage::update(['score' => '2-1'], new M4Activity);

    $compiled = m4Compile($update, ApnsKit::activityToken(), ApnsKit::context('2026-11-20T10:00:00Z', '2026-11-20T12:00:00Z', $id));

    expect($compiled->headers)->toBe([
        'apns-push-type' => 'liveactivity',
        'apns-topic' => 'com.example.app.push-type.liveactivity',
        'apns-priority' => '10',
        'apns-expiration' => '1795176000',
        'apns-id' => $id,
    ])->and($compiled->meta)->toBe([
        'push_type' => 'liveactivity',
        'topic' => 'com.example.app.push-type.liveactivity',
        'priority' => '10',
        'route' => 'device',
        'activity_event' => 'update',
        'attributes_version' => '1',
    ]);
});

it('publishes to a broadcast channel with apns-request-id and a broadcast route', function () {
    $id = 'eabeae54-14a8-11e5-b60b-1697f925ec7b';

    $compiled = m4Compile(LiveActivityMessage::update(['n' => 1]), ApnsKit::channel(), ApnsKit::context(messageId: $id));

    expect($compiled->headers)->toBe([
        'apns-push-type' => 'liveactivity',
        'apns-channel-id' => 'dHN0LXNyY2gtY2hubA==',
        'apns-priority' => '10',
        'apns-expiration' => '0',
        'apns-request-id' => $id,
    ])->and($compiled->meta['route'])->toBe('broadcast')
        ->and($compiled->meta['priority'])->toBe('10');
});

it('warns about forcing expiration 0 on a no-storage channel only when it changes something', function (?string $now, ?string $expiresAt, string $expiration, array $warnings) {
    $update = LiveActivityMessage::update(['n' => 1])->channelStoresNoMessages();

    $compiled = m4Compile($update, ApnsKit::channel(), ApnsKit::context($now ?? '2026-11-20T10:00:00Z', $expiresAt));

    expect($compiled->headers['apns-expiration'])->toBe($expiration)->and($compiled->warnings)->toBe($warnings);
})->with([
    'no expiry' => [null, null, '0', []],
    'expiry already past' => [null, '2026-11-20T09:00:00Z', '0', []],
    'expiry in the future' => [null, '2026-11-20T12:00:00Z', '0', ['channel stores no messages: apns-expiration forced to 0.']],
    'expiry one second after the epoch' => ['1970-01-01T00:00:00Z', '1970-01-01T00:00:01Z', '0', ['channel stores no messages: apns-expiration forced to 0.']],
]);

it('keeps the expiry of a broadcast channel that stores messages', function () {
    $compiled = m4Compile(LiveActivityMessage::update(['n' => 1]), ApnsKit::channel(), ApnsKit::context(expiresAt: '2026-11-20T12:00:00Z'));

    expect($compiled->headers['apns-expiration'])->toBe('1795176000')->and($compiled->warnings)->toBe([]);
});

it('refuses an empty input push channel on a Live Activity start', function () {
    $start = LiveActivityMessage::start(new M4Activity, [], [], Alert::make('Hi'))->inputPushChannel('');

    expect(fn () => m4Compile($start, ApnsKit::startToken()))
        ->toThrow(InvalidMessageException::class, 'The input push channel id cannot be empty.');
});

it('names the destination and the event when a Live Activity goes to the wrong token', function () {
    expect(fn () => m4Compile(LiveActivityMessage::start(new M4Activity, [], [], Alert::make('Hi')), ApnsKit::activityToken()))
        ->toThrow(InvalidDestinationException::class, 'A Live Activity start must be sent to apns_push_to_start_token, not to apns_live_activity_token.')
        ->and(fn () => m4Compile(LiveActivityMessage::end(), ApnsKit::startToken()))
        ->toThrow(InvalidDestinationException::class, 'A Live Activity end must be sent to apns_live_activity_token or apns_broadcast_channel, not to apns_push_to_start_token.');
});

it('nests a localized body as a dictionary even when the title is plain', function () {
    $alert = Alert::make('Plain title')->withBodyLocalization('DELIVERY_BODY', ['A-42']);

    $aps = ApnsKit::decode(m4Compile(LiveActivityMessage::update(['n' => 1])->alert($alert), ApnsKit::activityToken()))['aps'];

    expect($aps['alert'])->toBe([
        'title' => 'Plain title',
        'body' => ['loc-key' => 'DELIVERY_BODY', 'loc-args' => ['A-42']],
    ]);
});

it('nests a localized title and a localized body as dictionaries', function () {
    $alert = (new Alert)->withTitleLocalization('DELIVERY_TITLE')->withBodyLocalization('DELIVERY_BODY');

    $aps = ApnsKit::decode(m4Compile(LiveActivityMessage::update(['n' => 1])->alert($alert), ApnsKit::activityToken()))['aps'];

    expect($aps['alert'])->toBe([
        'title' => ['loc-key' => 'DELIVERY_TITLE'],
        'body' => ['loc-key' => 'DELIVERY_BODY'],
    ]);
});
