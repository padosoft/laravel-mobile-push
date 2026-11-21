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

it('reports a JSON failure with a stable message, no code and the original exception as the cause', function () {
    try {
        JsonEncoder::encode(['v' => "\xB1"]);
        $this->fail('expected an exception');
    } catch (InvalidMessageException $e) {
        expect($e->getMessage())->toStartWith('The message cannot be encoded as JSON: ')
            ->and($e->getCode())->toBe(0)
            ->and($e->getPrevious())->toBeInstanceOf(JsonException::class)
            ->and($e->getMessage())->toEndWith($e->getPrevious()->getMessage());
    }
});

it('does not reduce the encoding flags: unicode, slashes and zero fraction survive together in one payload', function () {
    expect(JsonEncoder::encode(['a' => 'é/', 'b' => 2.0]))->toBe('{"a":"é/","b":2.0}');
});
