<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

class FcmTestStringable implements Stringable
{
    public function __toString(): string
    {
        return 'from-object';
    }
}

it('stringifies data deterministically', function () {
    $message = PushMessage::make()->silent();
    // Assigned directly: PushMessage::data() spreads the array and renumbers integer-like keys (reported upstream).
    $message->data = [
        'string' => 'text',
        'int' => 42,
        'negative' => -7,
        'float' => 1.5,
        'whole_float' => 2.0,
        'true' => true,
        'false' => false,
        'zero' => 0,
        'empty' => '',
        'object' => new FcmTestStringable,
        '7' => 'numeric-key',
    ];

    $data = FcmKit::decode(FcmKit::compile($message))['message']['data'];

    expect($data)->toBe([
        'string' => 'text',
        'int' => '42',
        'negative' => '-7',
        'float' => '1.5',
        'whole_float' => '2',
        'true' => 'true',
        'false' => 'false',
        'zero' => '0',
        'empty' => '',
        'object' => 'from-object',
        '7' => 'numeric-key',
    ]);
});

it('drops null values and reports each one as a warning', function () {
    $compiled = FcmKit::compile(PushMessage::make('a', 'b')->data(['keep' => 'x', 'gone' => null, 'also_gone' => null]));

    expect(FcmKit::decode($compiled)['message']['data'])->toBe(['keep' => 'x'])
        ->and($compiled->warnings)->toBe([
            'Data key "gone" has a null value and was dropped.',
            'Data key "also_gone" has a null value and was dropped.',
        ]);
});

it('encodes a numeric-keyed data map as a JSON object, never a list', function () {
    $message = PushMessage::make()->silent();
    $message->data = ['0' => 'a', '1' => 'b'];
    $compiled = FcmKit::compile($message);

    expect($compiled->body)->toContain('"data":{"0":"a","1":"b"}');
});

it('rejects values that cannot be stringified', function (mixed $value) {
    $message = PushMessage::make('a', 'b');
    $message->data = ['bad' => $value];

    FcmKit::compile($message);
})->with([
    'array' => [['nested']],
    'plain object' => [new stdClass],
    'NAN' => [NAN],
    'infinity' => [INF],
])->throws(InvalidMessageException::class, '"bad"');

it('rejects the keys FCM reserves', function (string $key) {
    $message = PushMessage::make('a', 'b');
    $message->data = [$key => 'x'];

    FcmKit::compile($message);
})->with(['from', 'message_type', 'google.sent_time', 'google.c.a.e', 'gcm.notification.title', 'gcm.n.e'])
    ->throws(InvalidMessageException::class, 'reserved');

it('rejects reserved keys in native builders immediately', function () {
    expect(fn () => FcmMessage::make()->data(['from' => 'me']))->toThrow(InvalidMessageException::class)
        ->and(fn () => AndroidConfig::make()->data(['google.x' => 'y']))->toThrow(InvalidMessageException::class);
});

it('accepts keys that merely look like reserved ones', function () {
    $data = FcmKit::decode(FcmKit::compile(PushMessage::make()->silent()->data(['from_user' => '1', 'googleish' => '2', 'message_types' => '3'])))['message']['data'];

    expect(array_keys($data))->toBe(['from_user', 'googleish', 'message_types']);
});

it('rejects invalid UTF-8 anywhere in the payload', function (callable $build) {
    FcmKit::compile($build());
})->with([
    'title' => [fn () => PushMessage::make("bad \xB1 title", 'ok')],
    'body' => [fn () => PushMessage::make('ok', "bad \xC3\x28")],
    'data value' => [fn () => PushMessage::make()->silent()->data(['k' => "\xFF"])],
    'data key' => [fn () => PushMessage::make()->silent()->data(["k\xFF" => 'v'])],
])->throws(InvalidMessageException::class, 'JSON');

it('refuses to overwrite the keys the package writes itself', function () {
    expect(fn () => FcmKit::compile(
        PushMessage::make('a', 'b')->data(['mp_r' => 'user-value']),
        context: FcmKit::context(receiptMarker: 'marker'),
    ))->toThrow(InvalidMessageException::class, 'collides with the receipt marker')
        ->and(fn () => FcmKit::compile(PushMessage::make('a', 'b')->data(['deep_link' => 'other'])->deepLink('myapp://x')))
        ->toThrow(InvalidMessageException::class, 'collides with the deepLink');
});

it('puts the receipt marker and deep link in android.data too when the overlay overrides data there', function () {
    $message = PushMessage::make('a', 'b')->deepLink('myapp://x')
        ->fcm(fn (FcmMessage $m) => $m->android(fn (AndroidConfig $c) => $c->data(['only_android' => '1'])));

    $json = FcmKit::decode(FcmKit::compile($message, context: FcmKit::context(receiptMarker: 'm')))['message'];

    expect($json['data'])->toBe(['deep_link' => 'myapp://x', 'mp_r' => 'm'])
        ->and($json['android']['data'])->toBe(['only_android' => '1', 'deep_link' => 'myapp://x', 'mp_r' => 'm']);
});
