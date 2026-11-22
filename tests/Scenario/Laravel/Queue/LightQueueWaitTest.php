<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Health\HealthSettings;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Light profile, queue age: a job that waits in the queue backend reports how long it waited when a worker starts it.
 * The oracles are the recorder's snapshot and the Prometheus text; time is the world's fake clock.
 */

/** @return list<SendPushJob> */
function waitJobs(): array
{
    return Queue::pushed(SendPushJob::class)->values()->all();
}

/** @return array<string, int|float> connection => seconds waited */
function waitGauge(): array
{
    $out = [];
    foreach (app(MetricsRecorder::class)->snapshot() as $metric) {
        if ($metric->name === MetricsRecorder::QUEUE_WAIT) {
            expect(array_keys($metric->labels))->toBe(['connection', 'environment', 'provider']);
            $out[$metric->labels['connection']] = $metric->value;
        }
    }

    return $out;
}

function queueOne(QueueWorld $w, int $tokens = 2): SendPushJob
{
    $w->dispatcher->add($w->kit->apnsDriver());
    app(QueuedSender::class)->queue(SendKit::apnsBinding(), PushMessage::make('Sale', 'Starts now'), QueueWorld::tokens($tokens), SendKit::live(), new QueueOptions);

    return waitJobs()[0];
}

it('a job that waited 42 seconds in the queue shows a wait of 42 on the gauge and on the Prometheus page', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $job = queueOne($w);
    expect($job->queuedAt)->toBe($w->kit->clock->now()->getTimestamp());

    $w->advance(42);
    $w->run($job);

    expect($w->transport->sentCount())->toBe(2)
        ->and(waitGauge())->toBe(['ios' => 42]);
    $text = app(PrometheusExporter::class)->render();
    expect($text)->toContain('# TYPE mobile_push_queue_wait_seconds gauge')
        ->toContain('mobile_push_queue_wait_seconds{connection="ios",environment="production",provider="apns"} 42')
        ->not->toContain(QueueWorld::token(0)->value());
});

it('the gauge follows the most recent job and expires when no job runs for ten minutes', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $job = queueOne($w);

    $w->advance(30);
    $w->run($job);
    expect(waitGauge())->toBe(['ios' => 30]);

    $w->advance(20);
    $w->run($job);   // the same payload run again: 50 s since it was queued
    expect(waitGauge())->toBe(['ios' => 50]);

    $w->advance(600);
    expect(waitGauge())->toBe([]);
});

it('a clock that went backwards between queueing and running gives 0, not a negative wait', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $job = queueOne($w);

    $w->advance(-15);
    $w->run($job);

    expect(waitGauge())->toBe(['ios' => 0]);
});

it('a job built without an instant (or serialized before the field existed) runs and records nothing', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light');
    $job = queueOne($w);
    $serialized = serialize($job);
    $old = preg_replace('/s:8:"queuedAt";i:\d+;/', '', $serialized, -1, $removed);
    $old = preg_replace_callback('/^(O:\d+:"[^"]+":)(\d+)/', static fn (array $m): string => $m[1].((int) $m[2] - 1), (string) $old);
    expect($removed)->toBe(1);

    $legacy = unserialize((string) $old);
    expect($legacy)->toBeInstanceOf(SendPushJob::class)->and($legacy->queuedAt)->toBeNull();

    $w->advance(60);
    $w->run($legacy);

    expect($w->transport->sentCount())->toBe(2)->and(waitGauge())->toBe([]);
});

it('sends nothing different and records nothing when metrics are disabled', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light', ['metrics.enabled' => false]);
    app()->forgetInstance(HealthSettings::class);
    app()->forgetInstance(MetricsRecorder::class);
    $job = queueOne($w);

    $w->advance(10);
    $w->run($job);

    expect($w->transport->sentCount())->toBe(2)->and(waitGauge())->toBe([]);
});

it('a follow-up job handed over by the chunk deadline carries its own instant and reports its own wait', function (): void {
    Queue::fake();
    $w = QueueWorld::boot('light', ['queue.chunk_deadline' => 10]);
    $w->dispatcher->window = 2;
    $first = queueOne($w, 6);
    $queuedAt = $first->queuedAt;
    $w->transport->respondUsing(static function () use ($w) {
        $w->advance(4);

        return new Response(200);
    });

    $w->advance(5);
    $w->run($first);
    expect(waitGauge())->toBe(['ios' => 5]);

    $jobs = waitJobs();
    expect($jobs)->toHaveCount(2)
        ->and($jobs[1]->queuedAt)->toBe($w->kit->clock->now()->getTimestamp())
        ->and($jobs[1]->queuedAt)->toBeGreaterThan($queuedAt);

    $w->advance(3);
    $w->run($jobs[1]);
    expect(waitGauge())->toBe(['ios' => 3]);
});
