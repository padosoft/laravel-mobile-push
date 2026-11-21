<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Campaigns\Audience\KeysetCursor;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignException;
use Padosoft\MobilePush\Laravel\Campaigns\Pacing;

it('round-trips keyset cursors', function (string $kind, int $position): void {
    expect(KeysetCursor::decode($kind, KeysetCursor::encode($kind, $position)))->toBe($position);
})->with([['id', 0], ['id', 4711], ['b', PHP_INT_MAX], ['i', 1]]);

it('reads a missing cursor as the start', function (): void {
    expect(KeysetCursor::decode('id', null))->toBe(0);
});

it('refuses a corrupted cursor instead of restarting from zero', function (?string $stored): void {
    expect(fn () => KeysetCursor::decode('id', $stored))->toThrow(CampaignException::class);
})->with([
    'other audience' => 'b:10',
    'negative' => 'id:-1',
    'leading zero' => 'id:010',
    'whitespace' => 'id: 10',
    'trailing' => "id:10\n",
    'overflow' => 'id:99999999999999999999',
    'empty position' => 'id:',
    'bare number' => '10',
]);

it('cannot encode a negative position or an odd kind', function (string $kind, int $position): void {
    expect(fn () => KeysetCursor::encode($kind, $position))->toThrow(InvalidArgumentException::class);
})->with([['id', -1], ['ID', 1], ['', 1], ['a:b', 1]]);

it('sizes a slice by the pacing', function (int $slice, ?int $rate, int $expected): void {
    expect(Pacing::sliceSize($slice, $rate))->toBe($expected);
})->with([
    'unpaced' => [1000, null, 1000],
    'slower than a slice per minute' => [1000, 50, 50],
    'faster' => [1000, 60_000, 1000],
    'one per minute' => [1000, 1, 1],
]);

it('schedules the next slice from the commit, rounding up, never catching up', function (int $written, ?int $rate, ?int $expected): void {
    expect(Pacing::nextSliceAt(1_000_000, $written, $rate))->toBe($expected === null ? null : 1_000_000 + $expected);
})->with([
    'unpaced' => [1000, null, null],
    '50 at 50/min = one minute' => [50, 50, 60_000],
    '1000 at 60000/min = one second' => [1000, 60_000, 1_000],
    '1 at 7/min rounds up' => [1, 7, 8_572],
    'nothing written' => [0, 50, 0],
]);

it('turns a slot into a whole-second queue delay', function (?int $at, int $now, int $expected): void {
    expect(Pacing::delaySeconds($at, $now))->toBe($expected);
})->with([
    'no slot' => [null, 5_000, 0],
    'past' => [4_000, 5_000, 0],
    'exactly now' => [5_000, 5_000, 0],
    '1 ms ahead' => [5_001, 5_000, 1],
    '60 s ahead' => [65_000, 5_000, 60],
]);

it('refuses a rate outside the bounds', function (int $rate): void {
    expect(fn () => Pacing::assertRate($rate))->toThrow(InvalidArgumentException::class);
})->with([0, -5, Pacing::MAX_RATE + 1]);
