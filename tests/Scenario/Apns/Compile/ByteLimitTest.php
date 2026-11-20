<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Message\Support\Truncator;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

/**
 * The scenarios: a message whose encoded body is exactly `limit` bytes is accepted untouched; one byte more
 * is refused, or, when the sender opted in, shortened on a grapheme boundary and only in title/subtitle/body.
 *
 * Each case is [limit, factory(body text) => [message, destination]].
 */
dataset('limits', [
    'alert 4096' => [4096, fn (string $body, bool $truncate) => [
        ApnsMessage::alert('Title', $body)->custom('order', ['id' => 42, 'url' => 'https://example.com/a?b=c'])->truncate($truncate),
        ApnsKit::device(),
    ]],
    'unified 4096' => [4096, fn (string $body, bool $truncate) => [
        PushMessage::make('Title', $body)->data(['order' => 42])->deepLink('app://o/42')->truncate($truncate),
        ApnsKit::device(),
    ]],
    'voip 5120' => [5120, fn (string $body, bool $truncate) => [
        ApnsMessage::voip(['callId' => 'c-1'])->body($body)->truncate($truncate),
        ApnsKit::voipToken(),
    ]],
    'broadcast live activity 5120' => [5120, fn (string $body, bool $truncate) => [
        LiveActivityMessage::update(['score' => '2-1'])->alert(Alert::make('Goal', $body))->truncate($truncate),
        ApnsKit::channel(),
    ]],
]);

dataset('tails', [
    'zwj family' => ['👨‍👩‍👧‍👦👩‍💻🧑🏽‍🚀'],
    'flags' => ['🇮🇹🇯🇵🇧🇷🇨🇭'],
    'accents' => ["caffe\u{0301} è à ö ñ ç ß 日本語の通知 한국어 "],
    'json escapes' => ["say \"hi\" \\ back/slash \t tab \u{2028} \x01 end"],
    'mixed' => ["é👨‍👩‍👧‍👦🇮🇹\"\\ ok 🏳️‍🌈"],
]);

/**
 * Pad `$tail` at the front with ASCII so that the compiled body is exactly `$target` bytes.
 *
 * @param  Closure(string, bool): array{0: Message, 1: Destination}  $factory
 */
function bodyForExactSize(Closure $factory, string $tail, int $target): string
{
    $size = static function (string $text) use ($factory): int {
        [$message, $destination] = $factory($text, false);
        try {
            return strlen(ApnsKit::compiler()->compile($message, $destination, ApnsKit::context(receiptMarker: 'rcpt-1'))->body);
        } catch (PayloadTooLargeException) {
            return PHP_INT_MAX;
        }
    };

    $base = $size($tail);
    expect($base)->toBeLessThan($target);

    $text = str_repeat('x', $target - $base).$tail;
    expect($size($text))->toBe($target);

    return $text;
}

it('accepts a payload of exactly the limit', function (int $limit, Closure $factory, string $tail) {
    $text = bodyForExactSize($factory, $tail, $limit);
    [$message, $destination] = $factory($text, false);

    $compiled = ApnsKit::compiler()->compile($message, $destination, ApnsKit::context(receiptMarker: 'rcpt-1'));

    expect($compiled->payloadBytes)->toBe($limit)->and(strlen($compiled->body))->toBe($limit)
        ->and($compiled->limitBytes)->toBe($limit)->and($compiled->warnings)->toBe([]);
})->with('limits')->with('tails');

it('refuses a payload one byte over the limit', function (int $limit, Closure $factory, string $tail) {
    $text = bodyForExactSize($factory, $tail, $limit).'y';
    [$message, $destination] = $factory($text, false);

    ApnsKit::compiler()->compile($message, $destination, ApnsKit::context(receiptMarker: 'rcpt-1'));
})->with('limits')->with('tails')->throws(PayloadTooLargeException::class, '1 over');

it('truncates one byte over the limit on a grapheme boundary when opted in', function (int $limit, Closure $factory, string $tail) {
    $text = bodyForExactSize($factory, $tail, $limit).'y';
    [$message, $destination] = $factory($text, true);
    $before = serialize($message);

    $compiled = ApnsKit::compiler()->compile($message, $destination, ApnsKit::context(receiptMarker: 'rcpt-1'));
    $decoded = ApnsKit::decode($compiled);

    expect($compiled->payloadBytes)->toBeLessThanOrEqual($limit)->and(strlen($compiled->body))->toBeLessThanOrEqual($limit)
        ->and($compiled->warnings)->toHaveCount(1)->and($compiled->warnings[0])->toContain('body')
        ->and(serialize($message))->toBe($before);

    /** @var array{aps: array{alert: array{body: string}}} $decoded */
    $shortened = $decoded['aps']['alert']['body'];
    expect($shortened)->toEndWith('…');
    $kept = substr($shortened, 0, -strlen('…'));

    // The kept text is a prefix of the original that ends on a grapheme boundary: nothing is split.
    $boundaries = [0];
    $offset = 0;
    foreach (Truncator::graphemes($text) as $grapheme) {
        $offset += strlen($grapheme);
        $boundaries[] = $offset;
    }
    expect(str_starts_with($text, $kept))->toBeTrue()->and($boundaries)->toContain(strlen($kept));
})->with('limits')->with('tails');

it('never changes custom data, identifiers or Live Activity state when truncating', function () {
    $custom = ['order' => ['id' => 42, 'note' => str_repeat('é', 50)], 'url' => 'https://example.com/long/path?x=1'];
    $factory = fn (string $body, bool $truncate) => [ApnsMessage::alert('T', $body)->data($custom)->collapseId('keep-me')->truncate($truncate), ApnsKit::device()];
    $text = bodyForExactSize($factory, str_repeat('ü', 40), 4096).'y';
    [$message, $destination] = $factory($text, true);

    $compiled = ApnsKit::compiler()->compile($message, $destination, ApnsKit::context());
    $decoded = ApnsKit::decode($compiled);

    expect($decoded['order'])->toEqual($custom['order'])->and($decoded['url'])->toBe($custom['url'])
        ->and($compiled->headers['apns-collapse-id'])->toBe('keep-me')
        ->and($decoded['aps']['alert']['title'])->toBe('T');

    $state = ['rows' => array_fill(0, 120, 'state-row-state-row-state-row-1234567890')];
    $activity = LiveActivityMessage::update($state)->alert(Alert::make('Goal', str_repeat('g', 50)))->truncate();

    expect(fn () => ApnsKit::compiler()->compile($activity, ApnsKit::activityToken(), ApnsKit::context()))
        ->toThrow(PayloadTooLargeException::class);
});

it('shortens the body first, then the subtitle, then the title', function () {
    $message = ApnsMessage::alert(str_repeat('t', 30), str_repeat('b', 200), str_repeat('s', 30))->custom('pad', str_repeat('p', 3900))->truncate();

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
    $alert = ApnsKit::decode($compiled)['aps']['alert'];

    expect(strlen($compiled->body))->toBeLessThanOrEqual(4096)
        ->and($alert['title'])->toBe(str_repeat('t', 30))->and($alert['subtitle'])->toBe(str_repeat('s', 30))
        ->and($alert['body'])->toEndWith('…');

    $tight = ApnsMessage::alert(str_repeat('t', 300), str_repeat('b', 200), str_repeat('s', 300))->custom('pad', str_repeat('p', 3500))->truncate();
    $second = ApnsKit::compiler()->compile($tight, ApnsKit::device(), ApnsKit::context());
    $secondAlert = ApnsKit::decode($second)['aps']['alert'];

    expect(strlen($second->body))->toBeLessThanOrEqual(4096)
        ->and($secondAlert)->not->toHaveKey('body')
        ->and($secondAlert['subtitle'])->toEndWith('…')->and($secondAlert['title'])->toBe(str_repeat('t', 300));
});

it('refuses when the text cannot make it fit even after truncation', function () {
    $message = ApnsMessage::alert('T', 'short')->custom('pad', str_repeat('p', 5000))->truncate();

    ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
})->throws(PayloadTooLargeException::class);

it('gives the same truncation with intl and with PCRE segmentation', function () {
    $text = str_repeat('x', 4000).'👨‍👩‍👧‍👦🇮🇹é'."\u{0301}".str_repeat('日', 50);
    $message = ApnsMessage::alert('T', $text)->truncate();

    Truncator::forceMode(Truncator::MODE_PCRE);
    $pcre = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
    Truncator::forceMode(Truncator::MODE_INTL);
    $intl = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());
    Truncator::forceMode(null);

    expect($intl->body)->toBe($pcre->body);
})->afterEach(fn () => Truncator::forceMode(null));
