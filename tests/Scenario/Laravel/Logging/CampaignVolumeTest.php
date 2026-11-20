<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;

/**
 * 100,000 sends, every 50th one rejected (2,000 rejections).
 *
 * @return array{peak: int, growth: int}
 */
function runCampaign(int $n = 100_000): array
{
    $manager = F::manager();
    $binding = F::binding();
    $baseline = 0;

    for ($i = 0; $i < $n; $i++) {
        $rejected = $i % 50 === 0;
        $manager->recordResult($binding, $rejected
            ? F::result(F::token($i), SendStatus::Rejected, 'BadDeviceToken', ReasonCode::DestinationRejected)
            : F::result(F::token($i)));

        if ($i === 20_000) {
            gc_collect_cycles();
            $baseline = memory_get_usage();
        }
    }
    $manager->flush();
    gc_collect_cycles();

    return ['peak' => $manager->stats()['peak_buffered'], 'growth' => memory_get_usage() - $baseline];
}

/** A campaign of 100,000. */
it('writes only the non-accepted rows in failures mode, within a bounded buffer and flat memory', function () {
    F::migrate();
    F::configure('database', ['database.mode' => 'failures', 'buffer.size' => 500, 'buffer.max' => 1000]);

    $run = runCampaign();

    expect(DB::table('mobile_push_logs')->count())->toBe(2000)
        ->and(DB::table('mobile_push_logs')->where('status', '!=', 'rejected')->count())->toBe(0)
        ->and($run['peak'])->toBeLessThanOrEqual(1000)
        ->and($run['growth'])->toBeLessThan(8 * 1024 * 1024);

    $stats = F::manager()->stats()['sinks']['database'];
    expect($stats['written'])->toBe(2000)
        ->and($stats['filtered'])->toBe(98_000)
        ->and($stats['failed'])->toBe(0)
        ->and($stats['dropped'])->toBe(0);
})->group('slow-ish');

it('keeps every failure and about 1% of the successes in sampled mode', function () {
    F::migrate();
    F::configure('database', ['database.mode' => 'sampled', 'database.sample_rate' => 0.01, 'buffer.size' => 500]);

    runCampaign();

    $rejected = DB::table('mobile_push_logs')->where('status', 'rejected')->count();
    $accepted = DB::table('mobile_push_logs')->where('status', 'accepted')->count();

    // 98,000 successes at 1%: mean 980, standard deviation about 31; the band is roughly +-7 sigma.
    expect($rejected)->toBe(2000)
        ->and($accepted)->toBeGreaterThan(760)
        ->and($accepted)->toBeLessThan(1200);
})->group('slow-ish');
