<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Apns\PushType;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

function orderShipped(): PushMessage
{
    return PushMessage::make('Order shipped', 'Your order #42 is on its way')
        ->subtitle('Courier: DHL')
        ->badge(3)
        ->sound('ping.caf')
        ->actionCategory('ORDER')
        ->group('orders')
        ->collapse('order-42')
        ->data(['order_id' => 42, 'express' => true])
        ->deepLink('app://orders/42')
        ->policy('transactional')
        ->incrementBadge()
        ->bypassFrequencyCap();
}

it('maps the unified message to a complete APNs request', function () {
    $compiled = ApnsKit::compiler()->compile(orderShipped(), ApnsKit::device(), ApnsKit::context(receiptMarker: 'rcpt-1'));

    expect($compiled->headers)->toBe([
        'apns-push-type' => 'alert',
        'apns-topic' => 'com.example.app',
        'apns-priority' => '10',
        'apns-collapse-id' => 'order-42',
    ])->and(ApnsKit::decode($compiled))->toEqual([
        'aps' => [
            'alert' => ['title' => 'Order shipped', 'subtitle' => 'Courier: DHL', 'body' => 'Your order #42 is on its way'],
            'badge' => 3,
            'sound' => 'ping.caf',
            'thread-id' => 'orders',
            'category' => 'ORDER',
        ],
        'order_id' => 42,
        'express' => true,
        'deep_link' => 'app://orders/42',
        'mp_r' => 'rcpt-1',
    ])->and($compiled->payloadBytes)->toBe(strlen($compiled->body))
        ->and($compiled->limitBytes)->toBe(4096);
});

it('does not send policy, incrementBadge or bypassFrequencyCap to the device', function () {
    $compiled = ApnsKit::compiler()->compile(orderShipped(), ApnsKit::device(), ApnsKit::context());

    expect($compiled->body)->not->toContain('transactional')->not->toContain('incrementBadge')->not->toContain('bypass');
});

it('maps normal priority to apns-priority 5', function () {
    $message = PushMessage::make('Digest')->priority(MessagePriority::Normal);

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect($compiled->headers['apns-priority'])->toBe('5');
});

it('lays the APNs overlay on top of the compiled base', function () {
    $message = orderShipped()->apns(fn (ApnsMessage $apns) => $apns
        ->title('Overridden title')
        ->badge(0)
        ->interruptionLevel(Padosoft\MobilePush\Core\Apns\InterruptionLevel::Passive)
        ->targetContentId('window-1')
        ->custom('order_id', 99)
        ->custom('extra', 'x')
        ->collapseId('overlay-collapse')
        ->priority(Priority::Normal));

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
    $decoded = ApnsKit::decode($compiled);

    expect($compiled->headers)->toMatchArray(['apns-collapse-id' => 'overlay-collapse', 'apns-priority' => '5'])
        ->and($decoded['aps'])->toEqual([
            'alert' => ['title' => 'Overridden title', 'subtitle' => 'Courier: DHL', 'body' => 'Your order #42 is on its way'],
            'badge' => 0,
            'sound' => 'ping.caf',
            'thread-id' => 'orders',
            'category' => 'ORDER',
            'target-content-id' => 'window-1',
            'interruption-level' => 'passive',
        ])
        ->and($decoded)->toMatchArray(['order_id' => 99, 'extra' => 'x', 'deep_link' => 'app://orders/42']);
});

it('does not change what the overlay does not set', function () {
    $plain = ApnsKit::compiler()->compile(orderShipped(), ApnsKit::device(), ApnsKit::context());
    $withEmptyOverlay = ApnsKit::compiler()->compile(orderShipped()->apns(fn (ApnsMessage $apns) => $apns), ApnsKit::device(), ApnsKit::context());

    expect($withEmptyOverlay->body)->toBe($plain->body)->and($withEmptyOverlay->headers)->toBe($plain->headers);
});

it('lets an overlay re-target the unified message to a VoIP token', function () {
    $message = PushMessage::make()->data(['callId' => 'c1'])->silent(false)->badge(1)
        ->apns(fn (ApnsMessage $apns) => $apns->pushType(PushType::Voip));

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::voipToken(), ApnsKit::context());

    expect($compiled->headers['apns-topic'])->toBe('com.example.app.voip')->and($compiled->limitBytes)->toBe(5120);
});

it('sends a silent message as a priority 5 background push', function () {
    $message = PushMessage::make()->silent()->data(['sync' => 'inbox'])->collapse('sync');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect($compiled->headers)->toBe([
        'apns-push-type' => 'background',
        'apns-topic' => 'com.example.app',
        'apns-priority' => '5',
        'apns-collapse-id' => 'sync',
    ])->and(ApnsKit::decode($compiled))->toEqual(['aps' => ['content-available' => 1], 'sync' => 'inbox']);
});

it('refuses a silent message that also carries visible content', function () {
    ApnsKit::compiler()->compile(PushMessage::make('Hi')->silent(), ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'silent message cannot carry: title');

it('drops visible content from a lenient silent message and says so', function () {
    $message = PushMessage::make('Hi', 'There')->silent()->sound('x.caf')->lenient();

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled))->toEqual(['aps' => ['content-available' => 1]])
        ->and($compiled->warnings)->toHaveCount(1)
        ->and($message->title)->toBe('Hi');
});

it('refuses an image on a silent message as an unsupported capability', function () {
    ApnsKit::compiler()->compile(PushMessage::make()->silent()->image('https://cdn.example.com/a.png'), ApnsKit::device(), ApnsKit::context());
})->throws(UnsupportedCapabilityException::class);

it('refuses a silent message whose overlay asks for priority 10', function () {
    $message = PushMessage::make()->silent()->apns(fn (ApnsMessage $apns) => $apns->priority(Priority::High));

    ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'priority 5');

it('sends a critical message with the critical sound dictionary and interruption level', function () {
    $message = PushMessage::make('Smoke detected', 'Leave the building')->critical()->sound('alarm.caf');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled)['aps'])->toEqual([
        'alert' => ['title' => 'Smoke detected', 'body' => 'Leave the building'],
        'sound' => ['critical' => 1, 'name' => 'alarm.caf', 'volume' => 1.0],
        'interruption-level' => 'critical',
    ]);
});

it('refuses critical together with time-sensitive', function () {
    ApnsKit::compiler()->compile(PushMessage::make('x')->critical()->timeSensitive(), ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'mutually exclusive');

it('refuses an overlay that breaks the critical pairing', function () {
    $message = PushMessage::make('x')->critical()->apns(fn (ApnsMessage $apns) => $apns->sound('default'));

    ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'critical');

it('marks a time-sensitive message', function () {
    $compiled = ApnsKit::compiler()->compile(PushMessage::make('Now')->timeSensitive(), ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled)['aps']['interruption-level'])->toBe('time-sensitive');
});

it('needs an app extension for images and says so in the meta', function () {
    $message = PushMessage::make('Look')->image('https://cdn.example.com/a.png');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled))->toEqual([
        'aps' => ['alert' => ['title' => 'Look'], 'mutable-content' => 1],
        'image_url' => 'https://cdn.example.com/a.png',
    ])->and($compiled->meta['requires_app_code'])->toBe('image')
        ->and($compiled->warnings)->toBe([]);
});

it('refuses custom data that collides with a key the compiler writes', function (string $key) {
    $message = PushMessage::make('x')->deepLink('app://x')->image('https://e.com/a.png')->data([$key => 'mine']);

    ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
})->with(['deep_link', 'image_url'])->throws(InvalidMessageException::class, 'reserved');

it('refuses the reserved aps key in custom data and the receipt key collision', function () {
    expect(fn () => ApnsKit::compiler()->compile(PushMessage::make('x')->data(['aps' => 'no']), ApnsKit::device(), ApnsKit::context()))
        ->toThrow(InvalidMessageException::class, 'aps')
        ->and(fn () => ApnsKit::compiler()->compile(PushMessage::make('x')->data(['mp_r' => 'no']), ApnsKit::device(), ApnsKit::context(receiptMarker: 'r')))
        ->toThrow(InvalidMessageException::class, 'mp_r');
});

it('refuses a message with nothing to show and no silent flag', function () {
    ApnsKit::compiler()->compile(PushMessage::make()->data(['a' => 1]), ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'alert, a badge or a sound');

it('refuses invalid UTF-8 instead of sending garbage', function () {
    ApnsKit::compiler()->compile(PushMessage::make("bad \xB1 bytes"), ApnsKit::device(), ApnsKit::context());
})->throws(InvalidMessageException::class, 'UTF-8');

it('refuses a collapse id over 64 bytes and accepts exactly 64', function () {
    $ok = ApnsKit::compiler()->compile(PushMessage::make('x')->collapse(str_repeat('a', 64)), ApnsKit::device(), ApnsKit::context());

    expect($ok->headers['apns-collapse-id'])->toHaveLength(64)
        ->and(fn () => ApnsKit::compiler()->compile(PushMessage::make('x')->collapse(str_repeat('a', 65)), ApnsKit::device(), ApnsKit::context()))
        ->toThrow(InvalidMessageException::class, '64 bytes');
});

it('keeps the expiry identical across two compilations at different now', function () {
    $message = orderShipped();
    $expires = '2026-11-20T18:30:00Z';

    $first = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context('2026-11-20T10:00:00Z', expiresAt: $expires));
    $later = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context('2026-11-20T10:07:41Z', expiresAt: $expires));

    expect($first->headers['apns-expiration'])->toBe('1795199400')
        ->and($later->headers['apns-expiration'])->toBe($first->headers['apns-expiration'])
        ->and($later->body)->toBe($first->body);
});

it('omits apns-expiration when the context carries no expiry', function () {
    $compiled = ApnsKit::compiler()->compile(PushMessage::make('x'), ApnsKit::device(), ApnsKit::context());

    expect($compiled->headers)->not->toHaveKey('apns-expiration');
});
