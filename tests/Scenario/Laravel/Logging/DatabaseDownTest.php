<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\CapturingHandler;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\RecordingTelemetry;

afterEach(fn () => SinkManager::forgetExtensions());

/** The database is down while pushes go out. */
it('keeps sending and logging elsewhere when the database table is unreachable', function () {
    // The table does not exist: every insert fails with a real QueryException.
    $telemetry = new RecordingTelemetry;
    app()->instance(TracerProviderInterface::class, $telemetry);
    app()->instance(MeterProviderInterface::class, $telemetry);
    F::configure('log,database,otel', ['buffer.size' => 50]);
    $manager = F::manager();

    $thrown = null;
    try {
        for ($i = 0; $i < 120; $i++) {
            $manager->recordResult(F::binding(), F::result(F::token($i)));
        }
        $manager->flush();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $stats = $manager->stats();
    expect($thrown)->toBeNull()
        ->and(count(CapturingHandler::$records))->toBe(120)
        ->and(count($telemetry->spans))->toBe(120)
        ->and($stats['sinks']['database']['failed'])->toBe(120)
        ->and($stats['sinks']['database']['written'])->toBe(0)
        ->and($stats['sinks']['database']['last_error'])->toBe(QueryException::class)
        ->and($stats['sinks']['log']['written'])->toBe(120)
        ->and($stats['sinks']['otel']['written'])->toBe(120)
        ->and($stats['buffered'])->toBe(0);
});

it('asks a failing sink exactly once per flush and never replays the batch', function () {
    $calls = 0;
    SinkManager::extend('flaky', function () use (&$calls) {
        return F::brokenSink($calls);
    });
    F::configure('log,flaky', ['buffer.size' => 10]);
    $manager = F::manager();

    for ($i = 0; $i < 25; $i++) {
        $manager->recordResult(F::binding(), F::result(F::token($i)));
    }
    $manager->flush();
    $manager->flush();
    $manager->flush();

    $stats = $manager->stats();
    expect($calls)->toBe(3)                       // 2 auto flushes (10 + 10) + the final 5; empty flushes do nothing
        ->and($stats['sinks']['flaky']['failed'])->toBe(25)
        ->and($stats['sinks']['flaky']['written'])->toBe(0)
        ->and($stats['sinks']['log']['written'])->toBe(25)
        ->and(count(CapturingHandler::$records))->toBe(25);
});
