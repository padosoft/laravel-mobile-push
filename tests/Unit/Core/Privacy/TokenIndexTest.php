<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
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

it('refuses keys below 32 bytes and accepts exactly 32 (the boundary)', function () {
    expect(fn () => new KeyRing([1 => str_repeat('k', 31)]))->toThrow(PrivacyException::class)
        ->and((new KeyRing([1 => str_repeat('k', 32)]))->key(1))->toBe(str_repeat('k', 32))
        ->and(KeyRing::MIN_KEY_BYTES)->toBe(32);
});

it('accepts key versions from 1 to 9999 only', function (int $version, bool $ok) {
    $make = fn () => new KeyRing([$version => str_repeat('k', 32)]);

    $ok ? expect($make()->current)->toBe($version) : expect($make)->toThrow(PrivacyException::class);
})->with([
    'version 0' => [0, false],
    'version -1' => [-1, false],
    'version 1: lower boundary' => [1, true],
    'version 9999: upper boundary' => [9999, true],
    'version 10000' => [10000, false],
]);

it('refuses an empty ring and a current version outside the ring', function () {
    expect(fn () => new KeyRing([]))->toThrow(PrivacyException::class)
        ->and(fn () => new KeyRing([1 => str_repeat('k', 32)], 2))->toThrow(PrivacyException::class)
        ->and(fn () => new KeyRing([1 => str_repeat('k', 32)], -1))->toThrow(PrivacyException::class);
});

it('picks the highest version as current by default, whatever the order, and lists versions newest first', function () {
    $ring = new KeyRing([2 => str_repeat('b', 32), 7 => str_repeat('c', 32), 1 => str_repeat('a', 32)]);
    $explicit = new KeyRing([2 => str_repeat('b', 32), 7 => str_repeat('c', 32), 1 => str_repeat('a', 32)], 2);

    expect($ring->current)->toBe(7)->and($explicit->current)->toBe(2)
        ->and($ring->versions())->toBe([7, 2, 1])
        ->and($ring->key(3))->toBeNull()
        ->and($ring->__debugInfo())->toBe(['versions' => [7, 2, 1], 'current' => 7])
        ->and($explicit->__debugInfo())->toBe(['versions' => [7, 2, 1], 'current' => 2]);
});

it('keeps the index format frozen: rows written by earlier releases stay findable', function () {
    $index = new TokenIndex(new KeyRing([1 => str_repeat('k', 32), 2 => str_repeat('q', 32)]));

    expect($index->candidates(ApnsDeviceToken::of(str_repeat('a1', 32)), 'tenant-a'))->toBe([
        'v2:67892b930b7e4d70171f5a109b53529e70f7d29ad4ceeb4c354c80b9806a76cc',
        'v1:38809af51dd9ac67d190b5772d687d085e3ce4b513a3e3a58e2a047946dfe287',
    ])->and($index->candidates(FcmToken::of('fcm-token-1')))->toBe([
        'v2:40df1f6286a1249b13b3f7a6fbd19fa39930f0f778df823edef47b9020c1084c',
        'v1:9898041879269338aa81df751c1e2f3b2f8ff3181847cf78cccf1011d2612f7d',
    ]);
});

it('writes with the current version, lists one candidate per version newest first, and matches any of them', function () {
    $ring = new KeyRing([1 => str_repeat('k', 32), 3 => str_repeat('m', 32), 2 => str_repeat('q', 32)], 2);
    $index = new TokenIndex($ring);
    $d = FcmToken::of('fcm-token-1');
    $candidates = $index->candidates($d, 'tenant-a');

    expect(array_map(TokenIndex::versionOf(...), $candidates))->toBe([3, 2, 1])
        ->and($index->index($d, 'tenant-a'))->toBe($candidates[1])
        ->and(count(array_unique($candidates)))->toBe(3)
        ->and($index->matches($candidates[0], $d, 'tenant-a'))->toBeTrue()
        ->and($index->matches($candidates[2], $d, 'tenant-a'))->toBeTrue()
        ->and($index->matches($candidates[2], FcmToken::of('fcm-token-2'), 'tenant-a'))->toBeFalse()
        ->and($index->matches($candidates[2], $d, 'tenant-b'))->toBeFalse()
        ->and($index->matches('', $d, 'tenant-a'))->toBeFalse();
});

it('reads the version only from a well-formed index', function (string $stored, ?int $version) {
    expect(TokenIndex::versionOf($stored))->toBe($version);
})->with([
    ['v1:'.str_repeat('0', 64), 1],
    ['v9999:'.str_repeat('f', 64), 9999],
    ['v0:'.str_repeat('0', 64), null],
    ['v10000:'.str_repeat('0', 64), null],
    ['v1:'.str_repeat('0', 63), null],
    ['v1:'.str_repeat('A', 64), null],
    ['k1:'.str_repeat('0', 64), null],
]);
