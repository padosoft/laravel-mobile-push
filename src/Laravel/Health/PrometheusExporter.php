<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;

/**
 * Renders the Prometheus text exposition format (version 0.0.4): the recorded counters/gauges plus gauges computed at
 * scrape time (ledger backlog, log-sink failures and drops, series count).
 *
 * Scrape-time gauges never leave unbounded labels: ledger metrics are global (no label), sink stats are summed over
 * sinks. Sink and buffer counters are PROCESS-LOCAL (they live in the SinkManager of the process that serves the scrape);
 * the ledger gauges are read from the database and are global.
 */
final class PrometheusExporter
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * @param  \Closure(): ?DeliveryStore  $ledger  returns null when the durable profile is off
     * @param  \Closure(): ?SinkManager  $sinks
     */
    public function __construct(
        private readonly MetricsRecorder $recorder,
        private readonly \Closure $ledger,
        private readonly \Closure $sinks,
    ) {}

    /** @return list<Metric> */
    public function collect(): array
    {
        $metrics = $this->recorder->snapshot();
        $metrics[] = new Metric('mobile_push_metrics_series', MetricType::Gauge, 'Number of recorded metric series (bounded by metrics.max_series).', [], count($metrics));

        try {
            $store = ($this->ledger)();
            if ($store !== null) {
                try {
                    $health = $store->health();
                    $metrics[] = new Metric('mobile_push_ledger_reachable', MetricType::Gauge, '1 when the delivery ledger could be read at scrape time.', [], 1);
                    $metrics[] = new Metric('mobile_push_ledger_due', MetricType::Gauge, 'Deliveries due now (pending or deferred, next attempt reached).', [], $health->due);
                    $metrics[] = new Metric('mobile_push_ledger_pending', MetricType::Gauge, 'Deliveries waiting for a first attempt.', [], $health->pending);
                    $metrics[] = new Metric('mobile_push_ledger_deferred', MetricType::Gauge, 'Deliveries waiting for a retry.', [], $health->deferred);
                    $metrics[] = new Metric('mobile_push_ledger_in_flight', MetricType::Gauge, 'Deliveries leased by a worker right now.', [], $health->inFlight);
                    $metrics[] = new Metric('mobile_push_ledger_unknown', MetricType::Gauge, 'Deliveries ended with an unknown outcome.', [], $health->unknown);
                    $metrics[] = new Metric('mobile_push_ledger_oldest_due_age_seconds', MetricType::Gauge, 'Age of the oldest due delivery in seconds (0 when nothing is due). Growing means the relay is stuck.', [], round(($health->oldestDueAgeMs ?? 0) / 1000, 3));
                } catch (\Throwable) {
                    $metrics[] = new Metric('mobile_push_ledger_reachable', MetricType::Gauge, '1 when the delivery ledger could be read at scrape time.', [], 0);
                }
            }
        } catch (\Throwable) {
        }

        try {
            $sinks = ($this->sinks)();
            if ($sinks !== null) {
                $stats = $sinks->stats();
                $written = $failed = $dropped = 0;
                foreach ($stats['sinks'] as $sink) {
                    $written += $sink['written'];
                    $failed += $sink['failed'];
                    $dropped += $sink['dropped'];
                }
                $metrics[] = new Metric('mobile_push_log_records_written_total', MetricType::Counter, 'Log records written by the sinks of this process.', [], $written);
                $metrics[] = new Metric('mobile_push_log_records_failed_total', MetricType::Counter, 'Log records a sink failed to write (this process).', [], $failed);
                $metrics[] = new Metric('mobile_push_log_records_dropped_total', MetricType::Counter, 'Log records dropped because the buffer was full (this process).', [], $dropped);
                $metrics[] = new Metric('mobile_push_log_buffered', MetricType::Gauge, 'Log records waiting in the buffer of this process.', [], $stats['buffered']);
            }
        } catch (\Throwable) {
        }

        return $metrics;
    }

    public function render(): string
    {
        return self::format($this->collect());
    }

    /** @param  list<Metric>  $metrics */
    public static function format(array $metrics): string
    {
        $byName = [];
        foreach ($metrics as $metric) {
            $byName[$metric->name][] = $metric;
        }
        ksort($byName);

        $out = '';
        foreach ($byName as $name => $series) {
            $first = $series[0];
            $out .= '# HELP '.$name.' '.self::escapeHelp($first->help)."\n";
            $out .= '# TYPE '.$name.' '.$first->type->value."\n";
            $lines = [];
            foreach ($series as $metric) {
                $lines[self::labelString($metric->labels)] = $name.self::labelString($metric->labels).' '.self::number($metric->value);
            }
            ksort($lines);
            $out .= implode("\n", $lines)."\n";
        }

        return $out;
    }

    /** @param  array<string, string>  $labels */
    public static function labelString(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        ksort($labels);
        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name.'="'.self::escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    public static function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    public static function escapeHelp(string $text): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $text);
    }

    public static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        return $value == floor($value) && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
