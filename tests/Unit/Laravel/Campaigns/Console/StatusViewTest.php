<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\StatusView;
use Padosoft\MobilePush\Laravel\Campaigns\ThroughputEstimate;

it('formats durations', function (int $seconds, string $expected): void {
    expect(StatusView::duration($seconds))->toBe($expected);
})->with([[0, '0s'], [59, '59s'], [60, '1m 0s'], [200, '3m 20s'], [3600, '1h 0m'], [7384, '2h 3m']]);

it('always labels the estimate as a guess, and says what it does not cover', function (): void {
    $recent = StatusView::estimateLine(new ThroughputEstimate(12.5, 200, ThroughputEstimate::BASIS_RECENT, 60, false));
    $none = StatusView::estimateLine(new ThroughputEstimate(0.0, null, ThroughputEstimate::BASIS_SINCE_START, 5, true));
    $done = StatusView::estimateLine(new ThroughputEstimate(3.0, 0, ThroughputEstimate::BASIS_SINCE_START, 30, true));

    expect($recent)->toContain('a guess, not a promise')->toContain('3m 20s')->toContain('12.5 deliveries/s')->toContain('over the last 60s')->toContain('not yet planned are not included')
        ->and($none)->toContain('a guess')->toContain('no pace measured yet')
        ->and($done)->toContain('about 0s left')->not->toContain('not yet planned')
        ->and(StatusView::estimateLine(null))->toContain('has not started');
});
