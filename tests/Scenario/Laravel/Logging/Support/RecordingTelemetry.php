<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support;

use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;

/** In-memory tracer and meter providers implemented against the OpenTelemetry API interfaces. */
final class RecordingTelemetry implements MeterProviderInterface, TracerProviderInterface
{
    /** @var list<array{name: string, kind: int, attributes: array<string, mixed>, status: string|null, start: int|null, end: int|null}> */
    public array $spans = [];

    /** @var list<array{instrument: string, value: float|int, attributes: array<string, mixed>}> */
    public array $measurements = [];

    /** @var array<string, string> instrument => unit */
    public array $units = [];

    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        $telemetry = $this;

        return new class($telemetry) implements TracerInterface
        {
            public function __construct(private readonly RecordingTelemetry $telemetry) {}

            public function spanBuilder(string $spanName): SpanBuilderInterface
            {
                return new RecordingSpanBuilder($this->telemetry, $spanName);
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    public function getMeter(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): MeterInterface
    {
        $telemetry = $this;

        return new class($telemetry) implements MeterInterface
        {
            private NoopMeter $noop;

            public function __construct(private readonly RecordingTelemetry $telemetry)
            {
                $this->noop = new NoopMeter;
            }

            public function batchObserve(callable $callback, AsynchronousInstrument $instrument, AsynchronousInstrument ...$instruments): ObservableCallbackInterface
            {
                return $this->noop->batchObserve($callback, $instrument, ...$instruments);
            }

            public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): CounterInterface
            {
                $this->telemetry->units[$name] = (string) $unit;

                return new class($this->telemetry, $name) implements CounterInterface
                {
                    public function __construct(private readonly RecordingTelemetry $telemetry, private readonly string $name) {}

                    public function isEnabled(): bool
                    {
                        return true;
                    }

                    public function add(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
                    {
                        $this->telemetry->measurements[] = ['instrument' => $this->name, 'value' => $amount, 'attributes' => iterator_to_array($attributes)];
                    }
                };
            }

            public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): HistogramInterface
            {
                $this->telemetry->units[$name] = (string) $unit;

                return new class($this->telemetry, $name) implements HistogramInterface
                {
                    public function __construct(private readonly RecordingTelemetry $telemetry, private readonly string $name) {}

                    public function isEnabled(): bool
                    {
                        return true;
                    }

                    public function record(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
                    {
                        $this->telemetry->measurements[] = ['instrument' => $this->name, 'value' => $amount, 'attributes' => iterator_to_array($attributes)];
                    }
                };
            }

            public function createObservableCounter(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks): ObservableCounterInterface
            {
                return $this->noop->createObservableCounter($name, $unit, $description);
            }

            public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): GaugeInterface
            {
                return $this->noop->createGauge($name, $unit, $description);
            }

            public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks): ObservableGaugeInterface
            {
                return $this->noop->createObservableGauge($name, $unit, $description);
            }

            public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounterInterface
            {
                return $this->noop->createUpDownCounter($name, $unit, $description);
            }

            public function createObservableUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array|callable $advisory = [], callable ...$callbacks): ObservableUpDownCounterInterface
            {
                return $this->noop->createObservableUpDownCounter($name, $unit, $description);
            }
        };
    }

    /** Distinct attribute sets seen on one instrument. */
    public function labelSets(string $instrument): int
    {
        $sets = [];
        foreach ($this->measurements as $m) {
            if ($m['instrument'] === $instrument) {
                $attributes = $m['attributes'];
                ksort($attributes);
                $sets[json_encode($attributes)] = true;
            }
        }

        return count($sets);
    }

    /** Everything an exporter would see, flattened to one string (for canary searches). */
    public function everything(): string
    {
        return json_encode([$this->spans, $this->measurements], JSON_THROW_ON_ERROR);
    }
}

final class RecordingSpanBuilder implements SpanBuilderInterface
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    private int $kind = 0;

    private ?int $start = null;

    public function __construct(private readonly RecordingTelemetry $telemetry, private readonly string $name) {}

    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        foreach ($attributes as $k => $v) {
            $this->attributes[(string) $k] = $v;
        }

        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        $this->start = $timestampNanos;

        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        $this->kind = $spanKind;

        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return new RecordingSpan($this->telemetry, $this->name, $this->kind, $this->attributes, $this->start);
    }
}

final class RecordingSpan extends Span
{
    private ?string $status = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly RecordingTelemetry $telemetry,
        private readonly string $name,
        private readonly int $kind,
        private array $attributes,
        private readonly ?int $start,
    ) {}

    public function getContext(): SpanContextInterface
    {
        return SpanContext::getInvalid();
    }

    public function isRecording(): bool
    {
        return true;
    }

    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $k => $v) {
            $this->attributes[(string) $k] = $v;
        }

        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        return $this;
    }

    public function recordException(\Throwable $exception, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        $this->status = $code;

        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        $this->telemetry->spans[] = [
            'name' => $this->name, 'kind' => $this->kind, 'attributes' => $this->attributes,
            'status' => $this->status, 'start' => $this->start, 'end' => $endEpochNanos,
        ];
    }
}
