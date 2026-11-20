<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Message\Support\JsonEncoder;

it('encodes compactly without escaping unicode or slashes', function () {
    expect(JsonEncoder::encode(['t' => 'è 日本 👍', 'u' => 'https://a.b/c']))->toBe('{"t":"è 日本 👍","u":"https://a.b/c"}');
});

it('escapes only what JSON requires', function () {
    expect(JsonEncoder::encode(['v' => "q\" b\\ n\n \x01"]))->toBe('{"v":"q\\" b\\\\ n\\n \\u0001"}');
});

it('keeps the zero fraction of floats', function () {
    expect(JsonEncoder::encode(['v' => 1.0, 'w' => 0.5]))->toBe('{"v":1.0,"w":0.5}');
});

it('turns an empty map into an object and an empty list into an array', function () {
    expect(JsonEncoder::encode(['aps' => JsonEncoder::map([]), 'list' => []]))->toBe('{"aps":{},"list":[]}')
        ->and(JsonEncoder::encode(JsonEncoder::map(['a' => 1])))->toBe('{"a":1}');
});

it('wraps invalid UTF-8 and unrepresentable numbers in InvalidMessageException', function () {
    expect(fn () => JsonEncoder::encode(['v' => "\xB1"]))->toThrow(InvalidMessageException::class)
        ->and(fn () => JsonEncoder::encode(['v' => NAN]))->toThrow(InvalidMessageException::class)
        ->and(fn () => JsonEncoder::encode(['v' => INF]))->toThrow(InvalidMessageException::class);
});
