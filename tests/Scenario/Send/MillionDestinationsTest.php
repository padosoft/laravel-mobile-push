<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/**
 * Drives $n lazily generated destinations through the real pipeline. The fake transport answers 1 in 100 with 410
 * and forgets what it recorded every 1000 requests (so the oracle itself stays flat); it counts every request.
 *
 * @return array{requests: int, counts: array<string, int>, results: int, growth: int}
 */
function sendToMany(int $n): array
{
    $kit = new SendKit;
    $requests = 0;
    $transport = new FakeTransport;
    $transport->respondUsing(function (Request $r) use (&$requests, $transport) {
        if (++$requests % 1000 === 0) {
            $transport->reset();
        }

        return $requests % 100 === 0 ? SendKit::apnsReason(410, 'Unregistered', ['timestamp' => 1_763_600_000_000]) : new Response(200);
    });

    gc_collect_cycles();
    $baseline = memory_get_usage();
    $peak = 0;
    $seen = 0;
    $report = $kit->sender($transport)->send($kit->apnsDriver(window: 500), PushMessage::make('Big', 'Campaign'), SendKit::apnsTokens($n), SendKit::live(trafficClass: TrafficClass::Bulk));
    $report->each(function (SendResult $r) use (&$seen, &$peak) {
        if (++$seen % 10_000 === 0) {
            $peak = max($peak, memory_get_usage());
        }
    });

    return ['requests' => $requests, 'counts' => $report->counts(), 'results' => $seen, 'growth' => $peak - $baseline];
}

/*
 * Story: a campaign to every installation. The input is a lazy cursor; memory must not grow with it.
 */
it('streams 100,000 destinations with flat memory and exact counters', function () {
    $run = sendToMany(100_000);

    expect($run['results'])->toBe(100_000)
        ->and($run['requests'])->toBe(100_000)
        ->and($run['counts'][SendStatus::Accepted->value])->toBe(99_000)
        ->and($run['counts'][SendStatus::Rejected->value])->toBe(1_000)
        ->and(array_sum($run['counts']))->toBe(100_000)
        ->and($run['growth'])->toBeLessThan(8 * 1024 * 1024);
});

it('streams 1,000,000 destinations with flat memory and exact counters', function () {
    $run = sendToMany(1_000_000);

    expect($run['results'])->toBe(1_000_000)
        ->and($run['requests'])->toBe(1_000_000)
        ->and($run['counts'][SendStatus::Accepted->value])->toBe(990_000)
        ->and($run['counts'][SendStatus::Rejected->value])->toBe(10_000)
        ->and($run['growth'])->toBeLessThan(8 * 1024 * 1024);
})->group('slow');
