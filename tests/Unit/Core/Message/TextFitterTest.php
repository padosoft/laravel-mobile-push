<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\Support\JsonEncoder;
use Padosoft\MobilePush\Core\Message\Support\TextFitter;

function fitSize(array $fields): int
{
    return strlen(JsonEncoder::encode(['title' => $fields['title'] ?? '', 'body' => $fields['body'] ?? '', 'fixed' => str_repeat('f', 100)]));
}

it('returns the fields untouched when they already fit', function () {
    $fields = ['body' => 'hello', 'title' => 'hi'];

    expect(TextFitter::fit($fields, fitSize($fields), 'fitSize'))->toBe($fields);
});

it('shortens the first field and leaves the others alone', function () {
    $fields = ['body' => str_repeat('b', 300), 'title' => 'Title'];
    $limit = fitSize($fields) - 50;

    $fitted = TextFitter::fit($fields, $limit, 'fitSize');

    expect($fitted)->not->toBeNull()
        ->and($fitted['title'])->toBe('Title')
        ->and($fitted['body'])->toEndWith('…')
        ->and(fitSize($fitted))->toBeLessThanOrEqual($limit);
});

it('lands exactly on the limit when the text is plain ASCII', function () {
    $fields = ['body' => str_repeat('b', 300), 'title' => 'T'];
    $limit = fitSize($fields) - 10;

    $fitted = TextFitter::fit($fields, $limit, 'fitSize');

    expect(fitSize($fitted ?? []))->toBe($limit);
});

it('moves on to the next field once the first is emptied', function () {
    $fields = ['body' => str_repeat('b', 20), 'title' => str_repeat('t', 200)];
    $limit = fitSize(['body' => '', 'title' => str_repeat('t', 100)]);

    $fitted = TextFitter::fit($fields, $limit, 'fitSize');

    expect($fitted)->not->toBeNull()
        ->and($fitted['body'])->toBe('')
        ->and(strlen($fitted['title']))->toBeLessThan(200)
        ->and(fitSize($fitted))->toBeLessThanOrEqual($limit);
});

it('accounts for JSON escaping, which makes some characters cost more than their raw size', function () {
    $fields = ['body' => str_repeat('"', 400), 'title' => ''];
    $limit = fitSize(['body' => '', 'title' => '']) + 100;

    $fitted = TextFitter::fit($fields, $limit, 'fitSize');

    expect($fitted)->not->toBeNull()->and(fitSize($fitted))->toBeLessThanOrEqual($limit);
});

it('returns null when even empty fields do not fit', function () {
    expect(TextFitter::fit(['body' => 'abc', 'title' => 'def'], 10, 'fitSize'))->toBeNull();
});

it('does not split graphemes', function () {
    $family = '👨‍👩‍👧‍👦';
    $fields = ['body' => str_repeat($family, 20), 'title' => ''];
    $limit = fitSize($fields) - 60;

    $fitted = TextFitter::fit($fields, $limit, 'fitSize');
    $kept = substr($fitted['body'] ?? '', 0, -3);

    expect(str_repeat($family, intdiv(strlen($kept), strlen($family))))->toBe($kept);
});

it('treats a payload that is exactly at the limit as fitting, with no field to shorten', function () {
    expect(TextFitter::fit([], fitSize([]), 'fitSize'))->toBe([])
        ->and(TextFitter::fit([], fitSize([]) - 1, 'fitSize'))->toBeNull();
});

it('keeps a body that lands exactly on the limit and shortens one that is a byte over', function () {
    $body = str_repeat('b', 60);
    $exact = fitSize(['body' => $body, 'title' => '']);

    expect(TextFitter::fit(['body' => $body, 'title' => ''], $exact, 'fitSize'))->toBe(['body' => $body, 'title' => ''])
        ->and(TextFitter::fit(['body' => $body, 'title' => ''], $exact - 1, 'fitSize'))
        ->toBe(['body' => str_repeat('b', 56).'…', 'title' => '']);
});

it('empties a one-byte field that cannot be shortened and reports null when it is still too big', function () {
    $fixed = fitSize(['body' => '', 'title' => '']);

    expect(TextFitter::fit(['body' => 'x', 'title' => ''], $fixed, 'fitSize'))->toBe(['body' => '', 'title' => ''])
        ->and(TextFitter::fit(['body' => 'x', 'title' => ''], $fixed - 1, 'fitSize'))->toBeNull();
});

it('empties the first field entirely when the overflow is larger than the field', function () {
    $fixed = fitSize(['body' => '', 'title' => 'T']);
    $fitted = TextFitter::fit(['body' => str_repeat('b', 30), 'title' => 'T'], $fixed + 1, 'fitSize');

    expect($fitted)->toBe(['body' => '', 'title' => 'T']);
});

it('uses the custom ellipsis', function () {
    $fitted = TextFitter::fit(['body' => str_repeat('b', 60), 'title' => ''], fitSize(['body' => str_repeat('b', 40), 'title' => '']), 'fitSize', '...');

    expect($fitted['body'])->toBe(str_repeat('b', 37).'...');
});
