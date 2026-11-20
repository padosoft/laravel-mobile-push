<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\SendFailedException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

function reportResults(int $n, ?callable $status = null): Generator
{
    for ($i = 0; $i < $n; $i++) {
        yield new SendResult(FcmTopic::of('t'.$i), $status ? $status($i) : SendStatus::Accepted, Reason::none());
    }
}

it('streams a million results with flat memory and exact counters', function () {
    $report = new SendReport(reportResults(1_000_000, fn (int $i) => $i % 10 === 0 ? SendStatus::OutcomeUnknown : SendStatus::Accepted), retain: 100);

    $before = memory_get_usage();
    $seen = 0;
    foreach ($report->results() as $result) {
        $seen++;
    }

    expect($seen)->toBe(1_000_000)
        ->and($report->count())->toBe(1_000_000)
        ->and($report->accepted())->toBe(900_000)
        ->and($report->countOf(SendStatus::OutcomeUnknown))->toBe(100_000)
        ->and($report->retained())->toHaveCount(100)
        ->and($report->isTruncated())->toBeTrue()
        ->and(memory_get_usage() - $before)->toBeLessThan(2 * 1024 * 1024);
});

it('counts without iterating first and never loses a destination', function () {
    $report = new SendReport(reportResults(7));

    expect($report->counts()[SendStatus::Accepted->value])->toBe(7)
        ->and(iterator_to_array($report->results(), false))->toHaveCount(7)
        ->and($report->allAccepted())->toBeTrue();
});

it('throws only on request, and reports unknown outcomes separately from rejections', function () {
    $report = new SendReport([
        new SendResult(FcmTopic::of('a'), SendStatus::Accepted, Reason::none()),
        new SendResult(FcmTopic::of('b'), SendStatus::Rejected, Reason::of(ReasonCode::PayloadInvalid, "bad\x00payload")),
        new SendResult(FcmTopic::of('c'), SendStatus::OutcomeUnknown, Reason::of(ReasonCode::TransportUnknown)),
    ]);

    expect($report->hasUnknown())->toBeTrue()
        ->and($report->retained()[1]->reason->rawReason)->toBe('bad?payload')
        ->and(fn () => $report->throw())->toThrow(SendFailedException::class, 'rejected: 1, failed: 0, unknown outcome: 1');
});
