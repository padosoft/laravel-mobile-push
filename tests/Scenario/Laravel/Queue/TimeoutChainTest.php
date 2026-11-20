<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Queue\ChainStatus;
use Padosoft\MobilePush\Laravel\Queue\QueueModule;
use Padosoft\MobilePush\Laravel\Queue\TimeoutLink;

/*
 * "Timeout chain": what `doctor` prints. transport < chunk deadline < job timeout < worker timeout < retry_after,
 * lease > chunk deadline, each with margin; what cannot be read is "not verifiable", never "ok".
 */

beforeEach(function (): void {
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300]);
    config()->set('mobile-push.queue.connection', 'database');
    config()->set('mobile-push.delivery.profile', 'durable');
    config()->set('mobile-push.transport.timeout_ms', 30_000);
    config()->set('mobile-push.queue.chunk_deadline', 60);
    config()->set('mobile-push.queue.timeout', 120);
    config()->set('mobile-push.queue.worker_timeout', 150);
    config()->set('mobile-push.ledger.lease_seconds', 120);
});

/** @return array<string, string> "lower<upper" => status */
function chainVerdicts(): array
{
    $out = [];
    foreach (QueueModule::timeouts(app())->links as $link) {
        $out[$link->lower.'<'.$link->upper] = $link->status->value;
    }

    return $out;
}

it('a consistent configuration validates, every link checked', function (): void {
    $chain = QueueModule::timeouts(app());

    expect($chain->isVerified())->toBeTrue()
        ->and(chainVerdicts())->toBe([
            'transport_timeout<chunk_deadline' => 'ok',
            'chunk_deadline<job_timeout' => 'ok',
            'job_timeout<worker_timeout' => 'ok',
            'worker_timeout<retry_after' => 'ok',
            'chunk_deadline<lease_seconds' => 'ok',
        ])
        ->and($chain->values['retry_after'])->toBe(300.0)
        ->and($chain->values['transport_timeout'])->toBe(30.0);
});

dataset('broken pairs', [
    'transport too slow for the chunk' => ['mobile-push.transport.timeout_ms', 58_000, 'transport_timeout<chunk_deadline', 'mobile-push.queue.chunk_deadline'],
    'chunk deadline eats the job' => ['mobile-push.queue.chunk_deadline', 115, 'chunk_deadline<job_timeout', 'mobile-push.queue.timeout', ['mobile-push.ledger.lease_seconds' => 300]],
    'worker kills the job first' => ['mobile-push.queue.worker_timeout', 125, 'job_timeout<worker_timeout', 'worker_timeout'],
    'queue redelivers a running job' => ['queue.connections.database.retry_after', 155, 'worker_timeout<retry_after', 'retry_after'],
    'lease ends before the chunk' => ['mobile-push.ledger.lease_seconds', 60, 'chunk_deadline<lease_seconds', 'mobile-push.ledger.lease_seconds'],
]);

it('reports each inconsistent pair with its remedy', function (string $key, int $value, string $pair, string $remedyMentions, array $alsoFine = []): void {
    config()->set($key, $value);
    foreach ($alsoFine as $other => $fine) {
        config()->set($other, $fine);
    }
    $chain = QueueModule::timeouts(app());

    $violations = $chain->violations();
    expect($chain->isConsistent())->toBeFalse()
        ->and(array_map(static fn (TimeoutLink $l): string => $l->lower.'<'.$l->upper, $violations))->toBe([$pair])
        ->and($violations[0]->remedy)->toContain($remedyMentions)->toStartWith('Raise ')
        ->and($violations[0]->describe())->toContain('violation');
})->with('broken pairs');

it('an uninspectable backend reports "not verifiable", never "ok"', function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs', 'queue' => 'push']);
    config()->set('mobile-push.queue.connection', 'sqs');
    config()->set('mobile-push.queue.worker_timeout', null);   // `queue:work --timeout` is a CLI flag

    $chain = QueueModule::timeouts(app());

    expect(chainVerdicts())->toMatchArray([
        'job_timeout<worker_timeout' => 'not_verifiable',
        'worker_timeout<retry_after' => 'not_verifiable',
        'job_timeout<retry_after' => 'not_verifiable',
    ])
        ->and($chain->isConsistent())->toBeTrue()
        ->and($chain->isVerified())->toBeFalse()
        ->and($chain->sources['retry_after'])->toContain('SQS visibility timeout')
        ->and($chain->notVerifiable()[0]->remedy)->toContain('mobile-push.queue.worker_timeout');

    config()->set('mobile-push.queue.visibility_timeout', 600);
    config()->set('mobile-push.queue.worker_timeout', 150);
    expect(QueueModule::timeouts(app())->isVerified())->toBeTrue();
});

it('reads the worker timeout from the Horizon supervisors that work the push queues', function (): void {
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('mobile-push.queue.connection', 'redis');
    config()->set('mobile-push.queue.worker_timeout', null);
    config()->set('app.env', 'production');
    config()->set('horizon.defaults', ['push' => ['connection' => 'redis', 'queue' => ['push', 'push-bulk'], 'timeout' => 140]]);
    config()->set('horizon.environments', ['production' => ['push' => ['maxProcesses' => 10], 'other' => ['queue' => ['emails'], 'timeout' => 30]]]);

    $chain = QueueModule::timeouts(app());

    expect($chain->values['worker_timeout'])->toBe(140.0)
        ->and($chain->sources['worker_timeout'])->toBe('horizon supervisors')
        ->and(chainVerdicts()['worker_timeout<retry_after'])->toBe('violation');   // 140 s worker, 90 s retry_after
});

it('the sync driver has no worker and no redelivery: those links do not apply', function (): void {
    config()->set('mobile-push.queue.connection', 'sync');
    config()->set('queue.connections.sync', ['driver' => 'sync']);

    $statuses = array_map(static fn (TimeoutLink $l): ChainStatus => $l->status, QueueModule::timeouts(app())->links);

    expect(chainVerdicts())->toMatchArray(['job_timeout<worker_timeout' => 'not_applicable', 'worker_timeout<retry_after' => 'not_applicable'])
        ->and($statuses)->not->toContain(ChainStatus::Violation, ChainStatus::NotVerifiable);
});
