<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Fcm\FcmPayloadLimits;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Message\Support\Truncator;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

/*
 * Counting rule under test (see FcmPayloadLimits): bytes of keys + string values of notification and data.
 * A data-only message with the single key "k" therefore weighs 1 + strlen(value).
 */

const FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 18 bytes, one grapheme
const ACCENTED_DECOMPOSED = "e\u{0301}"; // 3 bytes, one grapheme

/** A value of exactly $bytes bytes made of composed emoji, accents and ASCII padding. */
function valueOfBytes(int $bytes): string
{
    $value = '';
    $i = 0;
    while (strlen($value) + 18 <= $bytes && $i < 30) {
        $value .= FAMILY.ACCENTED_DECOMPOSED.'é';
        $i++;
    }

    return $value.str_repeat('a', $bytes - strlen($value));
}

function dataOnly(int $totalBytes): PushMessage
{
    $message = PushMessage::make()->silent();
    $message->data = ['k' => valueOfBytes($totalBytes - 1)];

    return $message;
}

dataset('limits', [
    'token' => [fn () => FcmKit::token(), 4096],
    'fid' => [fn () => FcmKit::fid(), 4096],
    'topic' => [fn () => FcmKit::topic(), 2048],
    'condition' => [fn () => FcmKit::condition(), 2048],
]);

it('accepts a payload of exactly the limit and rejects one byte more', function (Closure $destination, int $limit) {
    $dest = $destination();

    $exact = FcmKit::compile(dataOnly($limit), $dest);
    expect($exact->payloadBytes)->toBe($limit)
        ->and($exact->limitBytes)->toBe($limit);

    expect(fn () => FcmKit::compile(dataOnly($limit + 1), $dest))
        ->toThrow(PayloadTooLargeException::class, 'is '.($limit + 1).' bytes, over the '.$limit.'-byte limit');
})->with('limits');

it('applies 4096 to individual targets and 2048 to topics even for the same message', function () {
    $between = dataOnly(3000);

    expect(FcmKit::compile($between, FcmKit::token())->payloadBytes)->toBe(3000)
        ->and(fn () => FcmKit::compile($between, FcmKit::topic()))->toThrow(PayloadTooLargeException::class);
});

it('counts the receipt marker and the deep link against the limit, after overlays', function () {
    $atLimit = dataOnly(4096);

    FcmKit::compile($atLimit); // fits

    expect(fn () => FcmKit::compile($atLimit, context: FcmKit::context(receiptMarker: 'x')))
        ->toThrow(PayloadTooLargeException::class);

    $overlay = dataOnly(4090)->fcm(fn (FcmMessage $m) => $m->data(['extra' => 'sixbytes']));
    expect(fn () => FcmKit::compile($overlay))->toThrow(PayloadTooLargeException::class);
});

it('measures bytes, not characters', function () {
    // 1365 family emoji clusters = 4*... use a title of 100 emoji: 100 graphemes but 1800 bytes
    $title = str_repeat(FAMILY, 100);
    $compiled = FcmKit::compile(PushMessage::make($title, 'x'));

    expect($compiled->payloadBytes)->toBe(strlen('title') + 1800 + strlen('body') + 1);
});

it('measures the android notification overrides and android data as well', function () {
    $message = FcmMessage::make('t', 'b')
        ->android(fn (AndroidConfig $a) => $a->data(['d' => '12345'])->notification(fn (AndroidNotification $n) => $n->title('override')->channelId('ignored-in-count')));

    expect(FcmKit::compile($message)->payloadBytes)
        ->toBe((5 + 1) + (4 + 1) + (1 + 5) + (5 + 8));
});

it('fails with the data-is-never-shortened explanation when data alone is too big, even with truncation on', function () {
    $message = dataOnly(4097)->truncate();
    $message->silent(false)->title('A title')->body('A body');

    expect(fn () => FcmKit::compile($message))
        ->toThrow(PayloadTooLargeException::class, 'never shortened');
});

it('shortens only title and body when truncation is opted in, never touching data', function () {
    $data = ['order_id' => '1234', 'note' => str_repeat(FAMILY, 20)];
    $title = 'Titolo molto lungo con accénti: '.str_repeat(ACCENTED_DECOMPOSED, 400);
    $body = str_repeat(FAMILY.' ', 200);
    $message = PushMessage::make($title, $body)->truncate();
    $message->data = $data;

    $compiled = FcmKit::compile($message, FcmKit::topic());
    $json = FcmKit::decode($compiled)['message'];

    expect($compiled->payloadBytes)->toBeLessThanOrEqual(2048)
        ->and($compiled->payloadBytes)->toBeGreaterThan(2048 - 40)
        ->and($json['data'])->toBe($data)
        ->and($json['notification']['body'])->toEndWith('…')
        ->and($compiled->warnings)->toHaveCount(1)
        ->and($compiled->warnings[0])->toContain('Truncated to fit 2048 bytes')
        // the input message is untouched
        ->and($message->title)->toBe($title)
        ->and($message->body)->toBe($body)
        ->and($message->data)->toBe($data);

    // no grapheme was split: what remains of the body is whole family emoji and spaces
    $kept = rtrim(mb_substr($json['notification']['body'], 0, -1));
    expect(str_replace([FAMILY, ' '], '', $kept))->toBe('');
});

it('truncates body first and leaves the title alone when that is enough', function () {
    $message = PushMessage::make('Short title', str_repeat('b', 5000))->truncate();

    $compiled = FcmKit::compile($message);
    $json = FcmKit::decode($compiled)['message'];

    expect($json['notification']['title'])->toBe('Short title')
        ->and(strlen($json['notification']['body']))->toBeLessThan(5000)
        ->and($compiled->payloadBytes)->toBe(4096);
});

it('truncates exactly one byte over the limit', function () {
    $overflowByOne = PushMessage::make('Hello', str_repeat('x', 4096 - 5 - 4 - 5 + 1))->truncate();

    $compiled = FcmKit::compile($overflowByOne);

    expect($compiled->payloadBytes)->toBeLessThanOrEqual(4096)
        ->and(FcmKit::decode($compiled)['message']['notification']['body'])->toEndWith('…');
});

it('does not truncate when the message fits, even with truncation on', function () {
    $compiled = FcmKit::compile(PushMessage::make('Hello', 'World')->truncate());

    expect(FcmKit::decode($compiled)['message']['notification'])->toBe(['title' => 'Hello', 'body' => 'World'])
        ->and($compiled->warnings)->toBe([]);
});

it('truncates a native FcmMessage when it opts in', function () {
    $message = FcmMessage::make('Title', str_repeat('y', 6000))->data(['k' => 'v'])->truncate();

    $compiled = FcmKit::compile($message);

    expect($compiled->payloadBytes)->toBeLessThanOrEqual(4096)
        ->and(FcmKit::decode($compiled)['message']['data'])->toBe(['k' => 'v']);
});

it('truncates the android notification override too', function () {
    $message = FcmMessage::make('Title', 'Body')
        ->android(fn (AndroidConfig $a) => $a->notification(fn (AndroidNotification $n) => $n->body(str_repeat('z', 5000))))
        ->truncate();

    $json = FcmKit::decode(FcmKit::compile($message))['message'];

    expect(strlen($json['android']['notification']['body']))->toBeLessThan(5000)
        ->and($json['notification'])->toBe(['title' => 'Title', 'body' => 'Body']);
});

it('keeps the pure counting function in line with the limits table', function () {
    expect(FcmPayloadLimits::DEFAULT_BYTES)->toBe(4096)
        ->and(FcmPayloadLimits::TOPIC_BYTES)->toBe(2048)
        ->and(Truncator::truncateToBytes('abc', 10))->toBe('abc');
});
