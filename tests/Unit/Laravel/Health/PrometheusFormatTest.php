<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Health\ErrorRate;
use Padosoft\MobilePush\Laravel\Health\Metric;
use Padosoft\MobilePush\Laravel\Health\MetricType;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;

it('escapes label values and help text', function (): void {
    $text = PrometheusExporter::format([
        new Metric('mobile_push_x_total', MetricType::Counter, "Line one\nback\\slash", ['connection' => "a\"b\\c\nd"], 3),
    ]);

    expect($text)->toBe("# HELP mobile_push_x_total Line one\\nback\\\\slash\n# TYPE mobile_push_x_total counter\nmobile_push_x_total{connection=\"a\\\"b\\\\c\\nd\"} 3\n");
});

it('orders metrics and series stably regardless of input order', function (): void {
    $a = new Metric('mobile_push_b', MetricType::Gauge, 'b', ['k' => '2'], 1);
    $b = new Metric('mobile_push_b', MetricType::Gauge, 'b', ['k' => '1'], 2);
    $c = new Metric('mobile_push_a', MetricType::Gauge, 'a', [], 1.5);

    expect(PrometheusExporter::format([$a, $b, $c]))->toBe(PrometheusExporter::format([$c, $b, $a]))
        ->and(PrometheusExporter::format([$a, $b, $c]))->toStartWith("# HELP mobile_push_a a\n# TYPE mobile_push_a gauge\nmobile_push_a 1.5\n");
});

it('applies the minimum sample before judging an error rate', function (int $sample, int $errors, int $min, bool $expected): void {
    expect(ErrorRate::exceeds($sample, $errors, $min, 0.5))->toBe($expected);
})->with([
    'too few samples' => [9, 9, 10, false],
    'exactly the minimum, at threshold' => [10, 5, 10, true],
    'enough samples, below threshold' => [100, 49, 10, false],
    'no traffic' => [0, 0, 10, false],
]);
