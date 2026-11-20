<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\LiveActivities\Transition\HourlyLimiter;

it('admits high priority until the limit inside a fixed one-hour window', function (?int $max, ?int $windowStart, int $count, int $now, bool $high, ?int $expectedStart, int $expectedCount): void {
    $verdict = (new HourlyLimiter($max))->admit($windowStart, $count, $now);

    expect($verdict->high)->toBe($high)
        ->and($verdict->windowStartedAt)->toBe($expectedStart)
        ->and($verdict->count)->toBe($expectedCount);
})->with([
    'no limit: always high, nothing counted' => [null, null, 0, 1000, true, null, 0],
    'first attempt opens the window' => [3, null, 0, 1000, true, 1000, 1],
    'under the limit' => [3, 1000, 2, 1500, true, 1000, 3],
    'at the limit: low' => [3, 1000, 3, 1500, false, 1000, 3],
    'last second of the window: still low' => [3, 1000, 3, 4599, false, 1000, 3],
    'one hour later: new window' => [3, 1000, 3, 4600, true, 4600, 1],
    'clock went backwards: new window' => [3, 5000, 3, 4000, true, 4000, 1],
    'limit 0: never high' => [0, null, 0, 1000, false, 1000, 0],
]);
