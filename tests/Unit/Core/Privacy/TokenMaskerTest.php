<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Privacy\TokenMasker;

it('shows only a few characters', function (string $in, string $out) {
    expect(TokenMasker::mask($in))->toBe($out);
})->with([
    'empty' => ['', '[masked]'],
    'short' => ['abc123', '[masked]'],
    'medium' => [str_repeat('a', 10).'zz'.str_repeat('b', 6), 'aa…bb'],
    'long' => ['a1b2c3'.str_repeat('x', 40).'9f0e', 'a1b2c3…9f0e'],
]);

it('hides values under 16 bytes, keeps 2+2 characters up to 31 bytes and 6+4 from 32 bytes', function (string $value, string $out) {
    expect(TokenMasker::mask($value))->toBe($out);
})->with([
    // The characters at both ends are distinct, so a shifted window shows.
    '15 bytes: hidden' => ['ABCDEFGHstuvwxy', '[masked]'],
    '16 bytes: lower boundary of 2+2' => ['ABCDEFGHstuvwxyz', 'AB…yz'],
    '31 bytes: upper boundary of 2+2' => ['ABCDEFGH'.str_repeat('m', 15).'stuvwxyz', 'AB…yz'],
    '32 bytes: lower boundary of 6+4' => ['ABCDEFGH'.str_repeat('m', 16).'stuvwxyz', 'ABCDEF…wxyz'],
]);

it('shows nothing of a value that is not plain printable ASCII: cutting it by bytes could split a character', function (string $value) {
    $masked = TokenMasker::mask($value);

    expect($masked)->toBe('[masked]')
        ->and(json_encode(['destination' => $masked]))->toBeString();
})->with([
    'two-byte characters, 16 bytes' => [str_repeat('é', 8)],
    'three-byte characters, 18 bytes (a 6-byte cut would split one)' => [str_repeat('€', 6)],
    'long, one multi-byte character inside' => [str_repeat('a', 20).'é'.str_repeat('b', 20)],
    'a space inside' => [str_repeat('a', 20).' '.str_repeat('b', 20)],
    'invalid UTF-8' => [str_repeat('a', 20)."\xC3".str_repeat('b', 20)],
]);
