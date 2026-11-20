<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/**
 * One sample of the metrics snapshot. Labels are limited to the bounded set documented on MetricsRecorder:
 * provider, connection, environment, status, reason, class (plus guard/transition for circuit metrics).
 */
final readonly class Metric
{
    /** @param  array<string, string>  $labels */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $labels,
        public int|float $value,
    ) {}
}
