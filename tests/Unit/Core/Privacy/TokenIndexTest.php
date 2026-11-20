<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

function indexFixture(): array
{
    return [str_repeat('a1', 32), new TokenIndex(new KeyRing([1 => str_repeat('k', 32)]))];
}

it('is deterministic and prefixed with the key version', function () {
    [$hex, $index] = indexFixture();
    $d = ApnsDeviceToken::of($hex);

    expect($index->index($d, 'tenant'))->toMatch('/^v1:[0-9a-f]{64}$/')
        ->and($index->index($d, 'tenant'))->toBe($index->index($d, 'tenant'))
        ->and(TokenIndex::versionOf($index->index($d)))->toBe(1);
});

it('separates scopes, kinds and keys', function () {
    [$hex, $index] = indexFixture();
    $device = ApnsDeviceToken::of($hex);
    $other = new TokenIndex(new KeyRing([1 => str_repeat('z', 32)]));

    expect($index->index($device, 'a'))->not->toBe($index->index($device, 'b'))
        ->and($index->index($device, 'a'))->not->toBe($index->index(ApnsVoipToken::of($hex), 'a'))
        ->and($index->index($device, 'a'))->not->toBe($other->index($device, 'a'))
        ->and($index->index($device, null))->toBe($index->index($device, 'default'));
});

it('is not a plain hash of the value', function () {
    [$hex, $index] = indexFixture();

    expect($index->index(ApnsDeviceToken::of($hex)))->not->toContain(hash('sha256', $hex));
});

it('refuses short keys and hides keys in dumps', function () {
    expect(fn () => new KeyRing([1 => 'short']))->toThrow(PrivacyException::class);

    $ring = new KeyRing([1 => str_repeat('K', 32)]);
    expect(print_r_safe($ring))->not->toContain(str_repeat('K', 32));
});

function print_r_safe(mixed $v): string
{
    return call_user_func('print_r', $v, true);
}
