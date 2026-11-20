<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Laravel\Logging\LogPruner;
use Padosoft\MobilePush\Laravel\Logging\PushLog;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

/** Retention. */
it('deletes only rows older than the retention, in chunks', function () {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    app()->instance(ClockInterface::class, $clock);
    F::migrate();
    F::configure('database');

    $ages = [1, 5, 29, 31, 32, 33, 40, 60, 90, 120]; // days old; 7 are beyond 30 days
    foreach ($ages as $i => $days) {
        F::manager()->recordResult(F::binding(), F::result(F::token($i)), ['at' => $clock->now()->modify("-{$days} days")]);
    }
    F::manager()->flush();
    expect(PushLog::query()->count())->toBe(10);

    $deletes = 0;
    DB::listen(function ($query) use (&$deletes): void {
        if (str_starts_with(strtolower($query->sql), 'delete')) {
            $deletes++;
        }
    });

    $pruner = app(LogPruner::class);
    expect($pruner->prune(30, chunk: 100, dryRun: true))->toBe(7)
        ->and(PushLog::query()->count())->toBe(10);

    $removed = $pruner->prune(30, chunk: 4);

    expect($removed)->toBe(7)
        ->and($deletes)->toBe(2)                      // 4 + 3
        ->and(PushLog::query()->count())->toBe(3)
        ->and(PushLog::query()->min('created_at'))->toStartWith('2026-10-22');
});
