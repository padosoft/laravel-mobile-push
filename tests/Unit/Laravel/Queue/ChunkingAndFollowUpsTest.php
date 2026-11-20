<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Laravel\Queue\DestinationChunker;
use Padosoft\MobilePush\Laravel\Queue\FollowUp;
use Padosoft\MobilePush\Laravel\Queue\FollowUpPlanner;
use Padosoft\MobilePush\Laravel\Queue\QueuedDestination;

function qd(int $i, int $length = 64): QueuedDestination
{
    return QueuedDestination::of(ApnsDeviceToken::of(str_pad(dechex($i + 1), $length, '0', STR_PAD_LEFT)));
}

/** @return list<int> */
function chunkSizes(DestinationChunker $chunker, iterable $destinations, int $fixed = 0): array
{
    return array_map(count(...), iterator_to_array($chunker->chunks($destinations, $fixed), false));
}

it('cuts by count', function (): void {
    $all = array_map(qd(...), range(0, 1199));

    expect(chunkSizes(new DestinationChunker(500, 1_000_000), $all))->toBe([500, 500, 200]);
});

it('cuts by estimated bytes, the fixed part of every job included', function (): void {
    $one = qd(0)->estimatedBytes();
    $budget = DestinationChunker::ENVELOPE_BYTES + 2000 + 10 * $one;   // room for exactly 10 next to a 2000-byte message

    expect(chunkSizes(new DestinationChunker(500, $budget), array_map(qd(...), range(0, 24)), 2000))->toBe([10, 10, 5])
        ->and(chunkSizes(new DestinationChunker(500, $budget), array_map(qd(...), range(0, 24)), 0))->toBe([25]);
});

it('keeps at least one destination per job when the message alone is over the byte budget', function (): void {
    expect(chunkSizes(new DestinationChunker(500, 4096), array_map(qd(...), range(0, 2)), 10_000))->toBe([1, 1, 1]);
});

it('drops duplicates in-process: same kind and value; a different kind or registry generation is another destination', function (): void {
    $token = ApnsDeviceToken::of(str_repeat('ab', 32));
    $destinations = [
        QueuedDestination::of($token),
        QueuedDestination::of(ApnsDeviceToken::of(strtoupper(str_repeat('ab', 32)))),   // normalised: the same
        QueuedDestination::of($token->withRef(new DestinationRef('9', 1))),
        QueuedDestination::of($token->withRef(new DestinationRef('9', 2))),
        QueuedDestination::of(FcmToken::of(str_repeat('ab', 32))),
    ];

    expect(chunkSizes(new DestinationChunker(500, 1_000_000), $destinations))->toBe([4]);
});

it('round-trips a destination with its registry reference and budget, and never dumps the value', function (): void {
    $budget = RetryBudget::start(new RetryPolicy, new DateTimeImmutable('2026-11-20T10:00:00Z'))->withAttempt();
    $original = QueuedDestination::of(FcmToken::of('fake-registration-token-1')->withRef(new DestinationRef('42', 3, 'App\\User', '7')), $budget);

    $copy = QueuedDestination::fromArray(unserialize(serialize($original->toArray())));

    expect($copy->destination->equals($original->destination))->toBeTrue()
        ->and($copy->destination->ref())->toEqual(new DestinationRef('42', 3, 'App\\User', '7'))
        ->and($copy->budget?->attempts)->toBe(1)
        ->and(json_encode($original->__debugInfo()))->not->toContain('fake-registration-token-1');
});

dataset('buckets', [
    'one time' => [[90, 90, 90], 60, [[3, 90]]],
    'within the bucket: run at the latest' => [[60, 75, 119], 60, [[3, 119]]],
    'two groups' => [[60, 61, 900, 905], 60, [[2, 61], [2, 905]]],
    'no grouping' => [[60, 61], 0, [[1, 60], [1, 61]]],
    'unsorted input' => [[905, 60, 900, 61], 60, [[2, 61], [2, 905]]],
]);

it('groups deferred destinations by time bucket and never runs a group before its latest next attempt', function (array $offsets, int $bucket, array $expected): void {
    $now = new DateTimeImmutable('2026-11-20T10:00:00Z');
    $deferred = array_map(static fn (int $s, int $i): array => [qd($i), $now->modify("+{$s} seconds")], $offsets, array_keys($offsets));

    $plans = (new FollowUpPlanner($bucket))->plan($deferred, $now);

    expect(array_map(static fn (FollowUp $f): array => [count($f->destinations), $f->delaySeconds], $plans))->toBe($expected);
})->with('buckets');

it('rounds the delay up to whole seconds and never below zero', function (): void {
    $now = new DateTimeImmutable('2026-11-20T10:00:00.000Z');

    expect(FollowUpPlanner::delaySeconds(new DateTimeImmutable('2026-11-20T10:01:00.001Z'), $now))->toBe(61)
        ->and(FollowUpPlanner::delaySeconds(new DateTimeImmutable('2026-11-20T10:01:00.000Z'), $now))->toBe(60)
        ->and(FollowUpPlanner::delaySeconds(new DateTimeImmutable('2026-11-20T09:59:00Z'), $now))->toBe(0);
});
