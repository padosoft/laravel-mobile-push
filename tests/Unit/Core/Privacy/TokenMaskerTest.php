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
