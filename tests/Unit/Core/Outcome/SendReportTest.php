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

it('says "all accepted" only for a report that has results and nothing but accepted ones', function (array $statuses, bool $all) {
    $report = new SendReport(reportResults(count($statuses), fn (int $i) => $statuses[$i]));

    expect($report->allAccepted())->toBe($all);
})->with([
    'no result at all' => [[], false],
    'one accepted' => [[SendStatus::Accepted], true],
    'two accepted' => [[SendStatus::Accepted, SendStatus::Accepted], true],
    'one accepted, one deferred' => [[SendStatus::Accepted, SendStatus::Deferred], false],
    'one simulated' => [[SendStatus::Simulated], false],
]);

it('reports an unknown outcome as soon as there is one, and none when there is none', function (array $statuses, bool $unknown) {
    expect((new SendReport(reportResults(count($statuses), fn (int $i) => $statuses[$i])))->hasUnknown())->toBe($unknown);
})->with([
    'empty' => [[], false],
    'accepted only' => [[SendStatus::Accepted, SendStatus::Rejected], false],
    'exactly one unknown' => [[SendStatus::Accepted, SendStatus::OutcomeUnknown], true],
]);

it('keeps the first results up to the limit and says when there were more', function (int $results, int $kept, bool $truncated) {
    // Each accessor is asked first on a report of its own: any of them has to read the source by itself.
    $make = static fn (): SendReport => new SendReport(reportResults($results), retain: 3);

    expect($make()->retained())->toHaveCount($kept)
        ->and($make()->isTruncated())->toBe($truncated)
        ->and($make()->count())->toBe($results)
        ->and(array_map(static fn (SendResult $r): string => $r->destination->value(), $make()->retained()))
        ->toBe(array_map(static fn (int $i): string => 't'.$i, range(0, $kept - 1)));
})->with([
    'fewer than the limit' => [2, 2, false],
    'exactly the limit' => [3, 3, false],
    'one more than the limit' => [4, 3, true],
]);

it('throw() names every count and stays silent when nothing went wrong', function () {
    $statuses = [
        ...array_fill(0, 2, SendStatus::Accepted),
        ...array_fill(0, 1, SendStatus::Rejected),
        ...array_fill(0, 2, SendStatus::Failed),
        ...array_fill(0, 3, SendStatus::OutcomeUnknown),
        ...array_fill(0, 4, SendStatus::Expired),
    ];
    $bad = new SendReport(reportResults(count($statuses), fn (int $i) => $statuses[$i]));
    $clean = [SendStatus::Accepted, SendStatus::Deferred, SendStatus::Skipped, SendStatus::Simulated, SendStatus::Validated];
    $fine = new SendReport(reportResults(count($clean), fn (int $i) => $clean[$i]));

    expect(fn () => $bad->throw())->toThrow(SendFailedException::class, '10 of 12 push destinations were not accepted (rejected: 1, failed: 2, unknown outcome: 3, expired: 4).')
        ->and($fine->throw())->toBe($fine);

    // one bad result is enough, whichever kind it is
    foreach ([SendStatus::Rejected, SendStatus::Failed, SendStatus::OutcomeUnknown, SendStatus::Expired] as $status) {
        expect(fn () => (new SendReport(reportResults(2, fn (int $i) => $i === 0 ? SendStatus::Accepted : $status)))->throw())
            ->toThrow(SendFailedException::class, '1 of 2 push destinations were not accepted');
    }
});

it('lets a fallback channel be considered only when the push is known not to have been accepted', function (SendStatus $status, bool $allowed) {
    expect($status->allowsFallback())->toBe($allowed);
})->with([
    [SendStatus::Rejected, true], [SendStatus::Failed, true], [SendStatus::Expired, true],
    [SendStatus::Accepted, false], [SendStatus::OutcomeUnknown, false], [SendStatus::Deferred, false],
    [SendStatus::Skipped, false], [SendStatus::Simulated, false], [SendStatus::Validated, false],
]);
