<?php

declare(strict_types=1);

require_once __DIR__.'/HealthKit.php';
require_once __DIR__.'/MetricsDoubles.php';

use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\MetricType;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\HealthKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\MetricsRig as Rig;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\SpyStore;

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return list<array{labels: array<string, string>, value: int|float}> */
function queueWaitSeries(MetricsRecorder $recorder): array
{
    $series = [];
    foreach ($recorder->snapshot() as $metric) {
        if ($metric->name === MetricsRecorder::QUEUE_WAIT) {
            expect($metric->type)->toBe(MetricType::Gauge);
            $series[] = ['labels' => $metric->labels, 'value' => $metric->value];
        }
    }

    return $series;
}

it('records the wait of the latest job as a gauge labelled by provider, connection and environment only', function (): void {
    $rig = new Rig;
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 42);
    $rig->recorder->recordQueueWait(HealthKit::binding('android', Provider::Fcm), 7);
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 3);

    $series = queueWaitSeries($rig->recorder);
    usort($series, static fn (array $a, array $b): int => $a['labels']['connection'] <=> $b['labels']['connection']);

    expect($series)->toBe([
        ['labels' => Rig::labels('android', 'fcm'), 'value' => 7],
        ['labels' => Rig::labels('ios'), 'value' => 3],
    ]);

    $text = PrometheusExporter::format($rig->recorder->snapshot());
    expect($text)->toContain('# TYPE mobile_push_queue_wait_seconds gauge')
        ->toContain('# HELP mobile_push_queue_wait_seconds Seconds the most recent light-profile job')
        ->toContain('mobile_push_queue_wait_seconds{connection="ios",environment="production",provider="apns"} 3');
});

it('never goes negative: a clock skew gives 0', function (): void {
    $rig = new Rig;
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), -90);

    expect(queueWaitSeries($rig->recorder))->toBe([['labels' => Rig::labels('ios'), 'value' => 0]]);
});

it('keeps a zero wait (a job that started the second it was queued)', function (): void {
    $rig = new Rig;
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 0);

    expect(queueWaitSeries($rig->recorder))->toBe([['labels' => Rig::labels('ios'), 'value' => 0]]);
});

it('records nothing when metrics are disabled', function (): void {
    $rig = new Rig(enabled: false);
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 12);

    expect($rig->store->log)->toBe([])->and($rig->recorder->snapshot())->toBe([]);
});

it('the gauge disappears ten minutes after the last job started, and not a second before', function (): void {
    $rig = new Rig;
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 12);
    $key = Rig::seriesKey('s', MetricsRecorder::QUEUE_WAIT, Rig::labels('ios'));

    $rig->advance(599);
    expect($rig->repo->get($key))->toBe(12)->and(queueWaitSeries($rig->recorder))->toHaveCount(1);

    $rig->advance(1);
    expect($rig->repo->get($key))->toBeNull()->and(queueWaitSeries($rig->recorder))->toBe([]);
});

it('a newer job renews the life of the gauge', function (): void {
    $rig = new Rig;
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 12);
    $rig->advance(500);
    $rig->recorder->recordQueueWait(HealthKit::binding('ios'), 5);
    $rig->advance(500);

    expect(queueWaitSeries($rig->recorder))->toBe([['labels' => Rig::labels('ios'), 'value' => 5]]);
});

it('never throws when the store is down', function (): void {
    $down = new Rig(new class extends SpyStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache down');
        }
    });
    $down->recorder->recordQueueWait(HealthKit::binding('ios'), 1);

    expect($down->recorder->snapshot())->toBe([]);
});
