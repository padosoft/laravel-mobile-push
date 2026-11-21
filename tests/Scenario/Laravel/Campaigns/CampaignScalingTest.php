<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Laravel\Campaigns\Audience\DeviceAudience;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStatus;
use Padosoft\MobilePush\Laravel\Campaigns\Events\CampaignCompleted;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignProfiler;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns\CampaignWorld;

require_once __DIR__.'/CampaignWorld.php';
require_once __DIR__.'/CampaignProfiler.php';

/*
 * "Does a campaign scale linearly?": the full path (plan -> ledger -> relay -> record -> completion) at growing
 * audiences, timed per phase. Nothing may grow with the square of the audience.
 */

function useSqliteFile(): string
{
    $path = sys_get_temp_dir().'/mobile-push-scaling-'.getmypid().'.sqlite';
    @unlink($path);
    touch($path);
    $default = (string) config('database.default');
    config()->set('database.connections.'.$default.'.database', $path);
    DB::purge($default);

    return $path;
}

/** @return array{ms: float, profiler: CampaignProfiler} */
function profiledCampaign(int $devices, bool $file = false): array
{
    // A fresh database for every run (an in-memory SQLite database lives as long as its connection).
    DB::purge((string) config('database.default'));
    $path = $file ? useSqliteFile() : null;
    Event::fake([CampaignCompleted::class]);
    $w = CampaignWorld::boot();
    CampaignWorld::seedDevices($devices);
    $p = new CampaignProfiler;

    $t = hrtime(true);
    $p->start();
    $campaign = $w->dispatch(DeviceAudience::all());
    $p->drain($w);
    $p->start();
    $progress = $w->campaigns()->progress('default', $campaign->id);
    $p->stop();
    $ms = (hrtime(true) - $t) / 1e6;

    expect($progress->campaign->status)->toBe(CampaignStatus::Completed)
        ->and($progress->accepted)->toBe($devices)
        ->and($w->requestHistogram($devices))->toBe([0 => 0, 1 => $devices, 'many' => 0]);

    if (getenv('MOBILE_PUSH_PROFILE') !== false) {
        fwrite(STDERR, "\n".$p->report(($file ? 'file' : 'memory').' sqlite', $devices, $ms)."\n");
        foreach ($p->top(10) as [$sql, $qms, $n]) {
            fwrite(STDERR, sprintf("   %9.1f ms %7d x  %s\n", $qms, $n, substr($sql, 0, 220)));
        }
        foreach ($p->plans(10) as $sql => $plan) {
            fwrite(STDERR, '   PLAN '.substr($sql, 0, 120).' => '.$plan."\n");
        }
    }
    if ($path !== null) {
        DB::disconnect();
        @unlink($path);
    }

    return ['ms' => $ms, 'profiler' => $p];
}

it('profiles the campaign path (set MOBILE_PUSH_PROFILE=1 to run and print the per-phase table)', function (int $devices, bool $file): void {
    profiledCampaign($devices, $file);
})->with([
    [25_000, false], [50_000, false], [100_000, false], [200_000, false],
    [25_000, true], [50_000, true], [100_000, true], [200_000, true],
])->group('slow')->skip(getenv('MOBILE_PUSH_PROFILE') === false, 'profiling run: set MOBILE_PUSH_PROFILE=1');

it('a campaign scales linearly: 200,000 devices take at most a generous multiple of 8 times 25,000 devices', function (): void {
    $small = profiledCampaign(25_000)['ms'];
    $large = profiledCampaign(200_000)['ms'];
    $ratio = $large / $small;
    fwrite(STDERR, sprintf("\n   campaign 25,000: %.1f s, 200,000: %.1f s, ratio %.2f (linear = 8; per 1,000 destinations %.0f ms vs %.0f ms)\n", $small / 1000, $large / 1000, $ratio, $small / 25, $large / 200));

    // Linear growth is 8x. Index and cache effects of a bigger database make it ~11x on SQLite in memory; the
    // quadratic paths this guards against (a sort of every due row per claim, a scan of every device per slice or
    // chunk, a state index walked per UPDATE) made it far larger: 25,000 -> 50,000 alone was 3.1x before W5-P.
    expect($ratio)->toBeLessThan(8 * 1.75);
})->group('slow');
