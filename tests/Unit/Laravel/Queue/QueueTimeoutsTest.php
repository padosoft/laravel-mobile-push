<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Queue\QueueTimeouts;
use Padosoft\MobilePush\Laravel\Queue\TimeoutLink;

/** @return array<string, string> */
function verdicts(...$args): array
{
    $out = [];
    foreach (QueueTimeouts::evaluate(...$args)->links as $link) {
        $out[$link->lower.'<'.$link->upper] = $link->status->value;
    }

    return $out;
}

it('requires a margin of max(5 s, 10%) between neighbours', function (float $lower, float $upper, string $status): void {
    expect(verdicts(transportTimeout: $lower, chunkDeadline: $upper, jobTimeout: 10_000, workerTimeout: 20_000, retryAfter: 40_000)['transport_timeout<chunk_deadline'])
        ->toBe($status);
})->with([
    'equal' => [30, 30, 'violation'],
    '4 s over a small value' => [10, 14, 'violation'],
    '5 s over a small value' => [10, 15, 'ok'],
    '10% over a large value' => [300, 330, 'ok'],
    'just under 10%' => [300, 329.9, 'violation'],
    'transport in milliseconds' => [29.999, 35, 'ok'],
]);

it('checks job < retry_after when the worker timeout is unknown, and the lease only when given', function (): void {
    expect(verdicts(30, 60, 120, null, 100))->toBe([
        'transport_timeout<chunk_deadline' => 'ok',
        'chunk_deadline<job_timeout' => 'ok',
        'job_timeout<worker_timeout' => 'not_verifiable',
        'worker_timeout<retry_after' => 'not_verifiable',
        'job_timeout<retry_after' => 'violation',
    ])->and(verdicts(30, 60, 120, 150, 300, 66))->toMatchArray(['chunk_deadline<lease_seconds' => 'ok'])
        ->and(verdicts(30, 60, 120, 150, 300, 65))->toMatchArray(['chunk_deadline<lease_seconds' => 'violation']);
});

it('checks the widest supervisor against retry_after and the narrowest against the job', function (): void {
    expect(verdicts(30, 60, 120, workerTimeout: 140, retryAfter: 160, workerTimeoutMax: 200))->toMatchArray([
        'job_timeout<worker_timeout' => 'ok',
        'worker_timeout<retry_after' => 'violation',
    ]);
});

it('names the smallest fix on both sides of a violation', function (): void {
    $link = QueueTimeouts::evaluate(30, 60, 120, 150, 155)->violations()[0];

    expect($link)->toBeInstanceOf(TimeoutLink::class)
        ->and($link->remedy)->toContain('Raise retry_after to at least 165 s')
        ->and($link->remedy)->toContain('lower worker_timeout to at most 140.909 s');
});
