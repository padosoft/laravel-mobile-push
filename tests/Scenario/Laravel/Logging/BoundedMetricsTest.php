<?php

declare(strict_types=1);

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Logging\OpenTelemetrySink;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\RecordingTelemetry;

/** Bounded metrics. */
it('keeps the number of metric label sets small however many destinations and users there are', function () {
    $telemetry = new RecordingTelemetry;
    app()->instance(TracerProviderInterface::class, $telemetry);
    app()->instance(MeterProviderInterface::class, $telemetry);
    F::configure('otel');
    $manager = F::manager();

    for ($i = 0; $i < 1000; $i++) {
        $result = $i % 10 === 0
            ? F::result(F::token($i), SendStatus::Rejected, 'BadDeviceToken', ReasonCode::DestinationRejected)
            : F::result(F::token($i));
        $manager->recordResult(F::binding(), $result, [
            'origin_type' => 'App\\Models\\User', 'origin_id' => 'user-'.$i,
            'campaign_id' => 'cmp-'.$i, 'effect_id' => 'eff-'.$i,
            'push_type' => $i % 2 === 0 ? 'alert' : 'background', 'traffic_class' => 'transactional',
            'duration_ms' => 20 + $i % 7, 'payload_bytes' => 300 + $i,
        ]);
    }
    $manager->flush();

    expect(count($telemetry->spans))->toBe(1000)
        ->and($telemetry->labelSets(OpenTelemetrySink::METRIC_OUTCOMES))->toBeLessThanOrEqual(6)
        ->and($telemetry->labelSets(OpenTelemetrySink::METRIC_DURATION))->toBeLessThanOrEqual(6)
        ->and($telemetry->labelSets(OpenTelemetrySink::METRIC_PAYLOAD))->toBeLessThanOrEqual(6)
        ->and($telemetry->units)->toBe([
            OpenTelemetrySink::METRIC_OUTCOMES => '{outcome}',
            OpenTelemetrySink::METRIC_DURATION => 's',
            OpenTelemetrySink::METRIC_PAYLOAD => 'By',
        ]);

    foreach ($telemetry->measurements as $m) {
        expect(array_keys($m['attributes']))->each->toBeIn([
            'messaging.system', 'mobile_push.binding', 'mobile_push.environment', 'mobile_push.status',
            'mobile_push.reason', 'mobile_push.push_type', 'mobile_push.traffic_class',
        ]);
    }
});

it('describes one outcome as one client span with convention attributes and an explicit unknown marker', function () {
    $telemetry = new RecordingTelemetry;
    app()->instance(TracerProviderInterface::class, $telemetry);
    app()->instance(MeterProviderInterface::class, $telemetry);
    F::configure('otel');

    $at = new DateTimeImmutable('2026-11-20T10:00:00.250Z');
    F::manager()->recordResult(F::binding(), F::result(F::token(1)), ['at' => $at, 'duration_ms' => 40, 'payload_bytes' => 512]);
    F::manager()->recordResult(F::binding(), F::result(F::token(2), SendStatus::Rejected, 'BadDeviceToken', ReasonCode::DestinationRejected));
    F::manager()->recordResult(F::binding(), F::result(F::token(3), SendStatus::OutcomeUnknown, null, ReasonCode::TransportUnknown));
    F::manager()->flush();

    [$ok, $rejected, $unknown] = $telemetry->spans;

    expect($ok['name'])->toBe('mobile-push send')
        ->and($ok['kind'])->toBe(SpanKind::KIND_CLIENT)
        ->and($ok['status'])->toBeNull()
        ->and($ok['attributes']['messaging.system'])->toBe('fcm')
        ->and($ok['attributes']['messaging.message.body.size'])->toBe(512)
        ->and($ok['attributes']['messaging.message.id'])->toStartWith('projects/demo/messages/')
        ->and($ok['attributes']['mobile_push.outcome_unknown'])->toBeFalse()
        ->and($ok['end'] - $ok['start'])->toBe(40_000_000)
        ->and($rejected['status'])->toBe('Error')
        ->and($rejected['attributes']['error.type'])->toBe('destination_rejected')
        ->and($unknown['status'])->toBeNull()
        ->and($unknown['attributes']['mobile_push.outcome_unknown'])->toBeTrue();
});
