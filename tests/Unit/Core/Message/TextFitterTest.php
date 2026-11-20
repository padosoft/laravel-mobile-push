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
