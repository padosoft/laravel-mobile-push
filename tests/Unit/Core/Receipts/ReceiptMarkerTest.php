<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Ledger\Ulid;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Receipts\ClientTimestamp;
use Padosoft\MobilePush\Core\Receipts\DeliveryReference;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;

const REF = '0191a2b3-c4d5-4e6f-8a7b-9c0d1e2f3a4b';

function receiptRing(int ...$versions): KeyRing
{
    $keys = [];
    foreach ($versions as $v) {
        $keys[$v] = random_bytes(32);
    }

    return new KeyRing($keys);
}

function receiptAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

it('round-trips scope, reference, expiry and key version', function (): void {
    $marker = new ReceiptMarker(receiptRing(1));
    $token = $marker->issue('tenant-a', REF, receiptAt('2026-11-27T10:00:00Z'));
    $verified = $marker->verify($token, receiptAt('2026-11-20T10:00:00Z'));

    expect($verified?->scope)->toBe('tenant-a')
        ->and($verified?->deliveryReference)->toBe(REF)
        ->and($verified?->expiresAt->getTimestamp())->toBe(receiptAt('2026-11-27T10:00:00Z')->getTimestamp())
        ->and($verified?->keyVersion)->toBe(1)
        ->and(ReceiptMarker::versionOf($token))->toBe(1)
        ->and($token)->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('is compact: 34 raw bytes plus the scope, 12-byte tag', function (string $scope, int $length): void {
    $token = (new ReceiptMarker(receiptRing(1)))->issue($scope, REF, receiptAt('2026-11-27T10:00:00Z'));

    expect(strlen($token))->toBe($length)->and($length)->toBeLessThan(140);
})->with([
    'default scope' => ['default', 55],
    'short scope' => ['a', 47],
    'longest scope (64)' => [str_repeat('s', 64), 131],
]);

it('rejects, without telling why, anything that is not authentic and unexpired', function (string $case): void {
    $marker = new ReceiptMarker(receiptRing(1, 2));
    $now = receiptAt('2026-11-20T10:00:00Z');
    $good = $marker->issue('t', REF, receiptAt('2026-11-21T10:00:00Z'));
    $raw = base64_decode(strtr($good, '-_', '+/').str_repeat('=', (4 - strlen($good) % 4) % 4), true);
    $flip = static fn (int $at): string => rtrim(strtr(base64_encode(substr_replace($raw, chr(ord($raw[$at]) ^ 1), $at, 1)), '+/', '-_'), '=');

    $input = match ($case) {
        'empty' => '',
        'garbage' => 'not a marker!',
        'too long' => str_repeat('A', 400),
        'padded' => $good.'==',
        'truncated' => substr($good, 0, -4),
        'extended' => $good.'AAAA',
        'flipped version' => $flip(0),
        'flipped expiry' => $flip(2),
        'flipped scope' => $flip(6),
        'flipped reference' => $flip(8),
        'flipped tag' => $flip(strlen($raw) - 1),
        'unknown key version' => (new ReceiptMarker(receiptRing(7)))->issue('t', REF, receiptAt('2026-11-21T10:00:00Z')),
        'other key' => (new ReceiptMarker(receiptRing(1, 2)))->issue('t', REF, receiptAt('2026-11-21T10:00:00Z')),
        'expired' => $marker->issue('t', REF, receiptAt('2026-11-20T09:59:59Z')),
        'expires exactly now' => $marker->issue('t', REF, $now),
    };

    expect($marker->verify($input, $now))->toBeNull();
})->with(['empty', 'garbage', 'too long', 'padded', 'truncated', 'extended', 'flipped version', 'flipped expiry', 'flipped scope', 'flipped reference', 'flipped tag', 'unknown key version', 'other key', 'expired', 'expires exactly now']);

it('can be pinned to an expected scope', function (): void {
    $marker = new ReceiptMarker(receiptRing(1));
    $token = $marker->issue('tenant-a', REF, receiptAt('2026-11-21T10:00:00Z'));

    expect($marker->verify($token, receiptAt('2026-11-20T10:00:00Z'), 'tenant-a'))->not->toBeNull()
        ->and($marker->verify($token, receiptAt('2026-11-20T10:00:00Z'), 'tenant-b'))->toBeNull();
});

it('refuses to issue for a bad scope, reference or expiry, and for key versions above 255', function (): void {
    $marker = new ReceiptMarker(receiptRing(1));
    $future = receiptAt('2026-11-21T10:00:00Z');

    expect(fn () => $marker->issue('bad scope!', REF, $future))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $marker->issue('t', 'not-a-uuid', $future))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $marker->issue('t', strtoupper(REF), $future))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $marker->issue('t', REF, receiptAt('2200-01-01T00:00:00Z')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReceiptMarker(new KeyRing([256 => random_bytes(32)])))->toThrow(ConfigurationException::class);
});

it('maps a ledger delivery id to a canonical UUID and back', function (): void {
    foreach (range(1, 50) as $_) {
        $id = Ulid::generate(new DateTimeImmutable);
        $uuid = DeliveryReference::messageIdFor($id);

        expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
            ->and(DeliveryReference::deliveryIdFor((string) $uuid))->toBe($id);
    }
    expect(DeliveryReference::messageIdFor('nope'))->toBeNull()
        ->and(DeliveryReference::messageIdFor(str_repeat('Z', 26)))->toBeNull()
        ->and(DeliveryReference::deliveryIdFor('NOT-A-UUID'))->toBeNull();
});

it('clamps a phone clock into [delivery creation, server now]', function (?string $reported, string $expected): void {
    $now = receiptAt('2026-11-20T10:00:00Z');
    $floor = receiptAt('2026-11-20T09:00:00Z');
    $clamped = ClientTimestamp::clamp($reported === null ? null : receiptAt($reported), $now, $floor);

    expect($clamped->format('H:i:s'))->toBe($expected);
})->with([
    'absent' => [null, '10:00:00'],
    'inside' => ['2026-11-20T09:30:00Z', '09:30:00'],
    'in the future' => ['2026-11-20T23:00:00Z', '10:00:00'],
    'before the delivery existed' => ['2020-01-01T00:00:00Z', '09:00:00'],
    'exactly now' => ['2026-11-20T10:00:00Z', '10:00:00'],
]);

it('parses only strict ISO-8601 instants', function (string $value, bool $valid): void {
    expect(ClientTimestamp::parse($value) !== null)->toBe($valid);
})->with([
    ['2026-11-20T10:00:00Z', true],
    ['2026-11-20T10:00:00.123+01:00', true],
    ['2026-11-20T10:00Z', true],
    ['2026-11-20T10:00:00', false],
    ['yesterday', false],
    ['@1700000000', false],
    ['2026-13-45T10:00:00Z', false],
    [str_repeat('1', 60), false],
]);
