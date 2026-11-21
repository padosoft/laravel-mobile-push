<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Laravel\Queue\ChunkDeadline;
use Padosoft\MobilePush\Laravel\Queue\QueuedDestination;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/** @return list<array<string, mixed>> */
function deadlineEntries(int $n): array
{
    return array_map(static fn (int $i): array => QueuedDestination::of(ApnsDeviceToken::of(sprintf('%064x', $i + 1)))->toArray(), range(0, $n - 1));
}

/**
 * Pulls the generator the way the Sender does: $window items, then the time a window takes, then the next window.
 *
 * @param  list<int>  $windowSeconds  seconds each successive window takes
 * @return list<int> how many items each window received
 */
function pullInWindows(ChunkDeadline $deadline, FakeClock $clock, array $entries, int $window, array $windowSeconds): array
{
    $sizes = [];
    $current = 0;
    foreach ($deadline->items($entries) as $item) {
        $current++;
        if ($current === $window) {
            $sizes[] = $current;
            $current = 0;
            $clock->advance(array_shift($windowSeconds) ?? 0);
        }
    }
    if ($current > 0) {
        $sizes[] = $current;
    }

    return $sizes;
}

it('yields and hands over disjoint parts that make up the whole chunk, in order', function (array $windowSeconds, array $expectedWindows): void {
    $clock = new FakeClock;
    $entries = deadlineEntries(10);
    $deadline = new ChunkDeadline($clock, 10);

    $sizes = pullInWindows($deadline, $clock, $entries, 2, $windowSeconds);
    $sent = array_sum($sizes);
    $rest = array_map(static fn (QueuedDestination $d): array => $d->toArray(), $deadline->remaining($entries));

    expect($sizes)->toBe($expectedWindows)
        ->and($deadline->reached())->toBe($sent < 10)
        ->and([...array_slice($entries, 0, $sent), ...$rest])->toBe($entries);
})->with([
    'everything fits' => [[1, 1, 1, 1, 1], [2, 2, 2, 2, 2]],
    'past the deadline after the third window' => [[3, 3, 4, 1], [2, 2, 2]],
    'less time left than the slowest window' => [[1, 6, 1, 1], [2, 2]],   // at 7 s, 3 s left < 6 s seen
    'exactly on the deadline' => [[5, 5], [2, 2]],
    'one window longer than the whole deadline' => [[30], [2]],
]);

it('always hands the first destination to the Sender, so a chain of handovers is never longer than the chunk', function (): void {
    $clock = new FakeClock;
    $entries = deadlineEntries(3);
    $deadline = new ChunkDeadline($clock, 1);
    $clock->advance(60);   // the job started late: the deadline is already behind us

    $sizes = pullInWindows($deadline, $clock, $entries, 1, [0, 0, 0]);

    expect($sizes)->toBe([1])
        ->and($deadline->remaining($entries))->toHaveCount(2);
});

it('hands over nothing when the generator was not run to its deadline (a send that threw before the end)', function (): void {
    $clock = new FakeClock;
    $entries = deadlineEntries(5);
    $deadline = new ChunkDeadline($clock, 10);
    $deadline->items($entries)->current();

    expect($deadline->reached())->toBeFalse()
        ->and($deadline->remaining($entries))->toBe([]);
});
