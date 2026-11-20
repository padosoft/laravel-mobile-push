<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\Support\Truncator;

afterEach(fn () => Truncator::forceMode(null));

dataset('modes', [
    'intl' => [Truncator::MODE_INTL],
    'pcre' => [Truncator::MODE_PCRE],
]);

dataset('texts', [
    'ascii' => ['hello world, this is plain ascii'],
    'accents precomposed' => ['àèìòù çñ ß ÀÈÌÒÙ'],
    'accents combining' => ["e\u{0301}a\u{0300}o\u{0302}u\u{0308}n\u{0303}"],
    'zwj family' => ['👨‍👩‍👧‍👦 and 👩‍💻 and 🧑🏽‍🚀'],
    'flags' => ['🇮🇹🇯🇵🇧🇷🇨🇭🇺🇸'],
    'skin tones' => ['👍🏽👍🏿👋🏻'],
    'keycaps and variation' => ['1️⃣2️⃣ ❤️ ☎️'],
    'cjk and hangul' => ['日本語の通知テスト 한국어 알림'],
    'crlf' => ["a\r\nb\r\nc"],
]);

it('segments identically with intl and with PCRE', function (string $text) {
    Truncator::forceMode(Truncator::MODE_INTL);
    $intl = Truncator::graphemes($text);
    Truncator::forceMode(Truncator::MODE_PCRE);
    $pcre = Truncator::graphemes($text);

    expect($intl)->toBe($pcre)->and(implode('', $intl))->toBe($text);
})->with('texts');

it('reports which path is active', function () {
    Truncator::forceMode(Truncator::MODE_PCRE);
    expect(Truncator::mode())->toBe(Truncator::MODE_PCRE);

    Truncator::forceMode(null);
    expect(Truncator::mode())->toBe(function_exists('grapheme_extract') ? Truncator::MODE_INTL : Truncator::MODE_PCRE);
});

it('never exceeds the byte budget and never splits a grapheme at any limit', function (string $mode, string $text) {
    Truncator::forceMode($mode);
    $boundaries = [0];
    $offset = 0;
    foreach (Truncator::graphemes($text) as $grapheme) {
        $offset += strlen($grapheme);
        $boundaries[] = $offset;
    }

    for ($max = 0; $max <= strlen($text) + 2; $max++) {
        $result = Truncator::truncateToBytes($text, $max);

        expect(preg_match('//u', $result))->toBe(1);
        if ($result === $text) {
            expect(strlen($text))->toBeLessThanOrEqual($max);

            continue;
        }

        expect(strlen($result))->toBeLessThanOrEqual($max);
        if ($result !== '') {
            expect($result)->toEndWith('…');
            // Trailing whitespace before the ellipsis is trimmed, so compare against the untrimmed prefix.
            $kept = substr($result, 0, -strlen('…'));
            expect(str_starts_with($text, $kept))->toBeTrue()
                ->and(array_filter($boundaries, fn (int $b): bool => $b >= strlen($kept) && trim(substr($text, strlen($kept), $b - strlen($kept))) === ''))->not->toBeEmpty();
        }
    }
})->with('modes')->with('texts');

it('gives the same truncation with both paths at every limit', function (string $text) {
    for ($max = 0; $max <= strlen($text); $max++) {
        Truncator::forceMode(Truncator::MODE_INTL);
        $a = Truncator::truncateToBytes($text, $max);
        Truncator::forceMode(Truncator::MODE_PCRE);
        $b = Truncator::truncateToBytes($text, $max);

        expect($a)->toBe($b);
    }
})->with('texts');

it('returns the text untouched when it already fits, including the exact limit', function (string $mode) {
    Truncator::forceMode($mode);
    $text = 'été 👨‍👩‍👧‍👦';

    expect(Truncator::truncateToBytes($text, strlen($text)))->toBe($text)
        ->and(Truncator::truncateToBytes($text, strlen($text) + 10))->toBe($text);
})->with('modes');

it('keeps whole family emoji and drops the one that does not fit', function (string $mode) {
    Truncator::forceMode($mode);
    $family = '👨‍👩‍👧‍👦';
    $text = 'ab'.$family.$family;

    // 2 + 25 bytes + 3 ellipsis = 30 fits one family; one byte less cannot keep it.
    expect(Truncator::truncateToBytes($text, 2 + strlen($family) + 3))->toBe('ab'.$family.'…')
        ->and(Truncator::truncateToBytes($text, 2 + strlen($family) + 2))->toBe('ab…');
})->with('modes');

it('never splits a flag pair', function (string $mode) {
    Truncator::forceMode($mode);

    expect(Truncator::truncateToBytes('🇮🇹🇯🇵', 8 + 3))->toBe('🇮🇹…');
})->with('modes');

it('does not leave trailing spaces before the ellipsis and returns empty when nothing fits', function () {
    expect(Truncator::truncateToBytes('hello world', 9))->toBe('hello…')
        ->and(Truncator::truncateToBytes('hello', 3))->toBe('')
        ->and(Truncator::truncateToBytes('hello', 2))->toBe('')
        ->and(Truncator::truncateToBytes('hello', 0))->toBe('');
});

it('supports a custom ellipsis and returns nothing for invalid UTF-8', function () {
    expect(Truncator::truncateToBytes('abcdefghij', 6, '...'))->toBe('abc...')
        ->and(Truncator::graphemes("bad \xB1 bytes"))->toBe([])
        ->and(Truncator::graphemes(''))->toBe([]);
});
