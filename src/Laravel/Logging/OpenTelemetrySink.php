<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * One span per record plus three metrics, through the OpenTelemetry API only: the host app decides the SDK and the
 * exporter. Without an SDK the API is a no-op, which is NOT telemetry delivered (the module cannot tell).
 *
 * Metric attributes are bounded: provider, binding, environment, status, reason, push type, traffic class. Ids
 * (destination, user, campaign, message) appear only on spans, and never a token.
 *
 * Span attributes use OpenTelemetry semantic-convention names where one exists (messaging.system,
 * messaging.operation.name, messaging.operation.type, messaging.message.id, messaging.message.body.size,
 * error.type, http.response.status_code); package-specific ones live under `mobile_push.`.
 */
final class OpenTelemetrySink implements LogSink
{
    public const INSTRUMENTATION = 'padosoft/laravel-mobile-push';

    public const SPAN_NAME = 'mobile-push send';

    public const METRIC_OUTCOMES = 'mobile_push.send.outcomes';

    public const METRIC_DURATION = 'mobile_push.send.duration';

    public const METRIC_PAYLOAD = 'mobile_push.send.payload.size';

    private TracerInterface $tracer;

    private CounterInterface $outcomes;

    private HistogramInterface $duration;

    private HistogramInterface $payloadSize;

    public function __construct(TracerProviderInterface $tracers, MeterProviderInterface $meters)
    {
        $this->tracer = $tracers->getTracer(self::INSTRUMENTATION);
        $meter = $meters->getMeter(self::INSTRUMENTATION);
        $this->outcomes = $meter->createCounter(self::METRIC_OUTCOMES, '{outcome}', 'Send outcomes by status and reason.');
        $this->duration = $meter->createHistogram(self::METRIC_DURATION, 's', 'Duration of one send to the provider.');
        $this->payloadSize = $meter->createHistogram(self::METRIC_PAYLOAD, 'By', 'Size of the compiled payload.');
    }

    /** True when the OpenTelemetry API package is installed. */
    public static function isInstalled(): bool
    {
        return interface_exists(TracerProviderInterface::class) && interface_exists(MeterProviderInterface::class);
    }

    public function write(LogRecord ...$records): void
    {
        foreach ($records as $record) {
            $this->metrics($record);
            $this->span($record);
        }
    }

    public function flush(): void {}

    /** @return non-empty-array<non-empty-string, string> */
    public static function metricAttributes(LogRecord $r): array
    {
        $attributes = [
            'messaging.system' => $r->provider,
            'mobile_push.binding' => $r->bindingName,
            'mobile_push.environment' => $r->environment,
            'mobile_push.status' => $r->status->value,
            'mobile_push.reason' => $r->reasonCode->value,
        ];

        if ($r->pushType !== null) {
            $attributes['mobile_push.push_type'] = $r->pushType;
        }
        if ($r->trafficClass !== null) {
            $attributes['mobile_push.traffic_class'] = $r->trafficClass;
        }

        return $attributes;
    }

    private function metrics(LogRecord $r): void
    {
        $attributes = self::metricAttributes($r);

        $this->outcomes->add(1, $attributes);

        if ($r->durationMs !== null) {
            $this->duration->record($r->durationMs / 1000, $attributes);
        }
        if ($r->payloadBytes !== null) {
            $this->payloadSize->record($r->payloadBytes, $attributes);
        }
    }

    private function span(LogRecord $r): void
    {
        $endNanos = (int) $r->at->format('U') * 1_000_000_000 + (int) $r->at->format('u') * 1000;
        $startNanos = $endNanos - ($r->durationMs ?? 0) * 1_000_000;

        $span = $this->tracer->spanBuilder(self::SPAN_NAME)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($startNanos)
            ->startSpan();

        $span->setAttributes(array_filter([
            'messaging.system' => $r->provider,
            'messaging.operation.name' => $r->operation,
            'messaging.operation.type' => 'send',
            'messaging.message.id' => $r->providerMessageId,
            'messaging.message.body.size' => $r->payloadBytes,
            'http.response.status_code' => $r->httpStatus,
            'mobile_push.binding' => $r->bindingName,
            'mobile_push.app_id' => $r->appId,
            'mobile_push.environment' => $r->environment,
            'mobile_push.status' => $r->status->value,
            'mobile_push.reason' => $r->reasonCode->value,
            'mobile_push.outcome_unknown' => $r->status === SendStatus::OutcomeUnknown,
            'mobile_push.push_type' => $r->pushType,
            'mobile_push.traffic_class' => $r->trafficClass,
            'mobile_push.attempts' => $r->attempts,
            'mobile_push.destination.kind' => $r->destinationKind,
            'mobile_push.destination.index' => $r->destinationIndex,
            'mobile_push.destination.masked' => $r->destinationMasked,
            'mobile_push.delivery_id' => $r->deliveryId,
            'mobile_push.effect_id' => $r->effectId,
            'mobile_push.campaign_id' => $r->campaignId,
        ], static fn (mixed $v): bool => $v !== null));

        if (in_array($r->status, [SendStatus::Rejected, SendStatus::Failed], true)) {
            $span->setAttribute('error.type', $r->reasonCode->value);
            $span->setStatus(StatusCode::STATUS_ERROR, $r->reasonCode->value);
        }

        $span->end($endNanos);
    }
}
