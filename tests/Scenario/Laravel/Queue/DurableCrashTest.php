<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\DyingLedger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\WorkerDied;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Durable profile, the crash stories: whatever point the worker dies at, an accepted delivery is never sent again,
 * an attempt that may have left is never recorded as failed, and the work left is picked up after the lease expires.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->w = QueueWorld::boot('durable');
    $this->w->dispatcher->add($this->w->kit->apnsDriver());
    $this->w->dispatcher->window = 1;   // one request at a time, so the crash point is exact
    $this->queue = static fn (PushMessage $message, int $n = 10): string => app(QueuedSender::class)
        ->queue(SendKit::apnsBinding(), $message, QueueWorld::tokens($n), SendKit::live(effectKey: 'crash-1'), new QueueOptions);

    /** Kill the first relay with a dying ledger, then let a fresh worker recover after the lease expired. */
    $this->crashThenRecover = function (DyingLedger $dying): void {
        $real = app(Ledger::class);
        app()->instance(Ledger::class, $dying);
        expect(fn () => $this->w->run(Queue::pushed(RelayDeliveriesJob::class)->first()))->toThrow(WorkerDied::class);
        app()->instance(Ledger::class, $real);

        $this->beforeRecovery = QueueWorld::deliveryStates();
        $this->w->advance(61);   // lease_seconds = 60
        $this->sweep = $this->w->sweeper()->sweep();
        foreach (Queue::pushed(RelayDeliveriesJob::class)->slice(1) as $job) {
            $this->w->run($job);
        }
    };
});

it('crash in the middle of a chunk: the 4 accepted are never re-sent, the alert in flight ends unknown and is not re-sent', function (): void {
    ($this->queue)(PushMessage::make('Order', 'Shipped'));
    $dying = new DyingLedger(app(Ledger::class));
    $dying->dieOnStart = 5;

    ($this->crashThenRecover)($dying);

    $perToken = $this->w->apnsRequestsPerToken();
    expect($this->beforeRecovery)->toBe(['accepted' => 4, 'leased' => 5, 'sending' => 1])
        ->and($this->sweep->endedUnknown)->toBe(1)
        ->and($this->sweep->requeued)->toBe(5)
        ->and($perToken)->toHaveCount(9)   // the one in flight never went out again (nor, here, at all)
        ->and(array_sum($perToken))->toBe(9)
        ->and(array_values(array_unique($perToken)))->toBe([1])
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 9, 'outcome_unknown' => 1]);
});

it('crash in the middle of a chunk: a replaceable effect retries its in-flight delivery', function (): void {
    ($this->queue)(PushMessage::make('Score', '1-0')->replaceable()->ttl(3600));
    $dying = new DyingLedger(app(Ledger::class));
    $dying->dieOnStart = 5;

    ($this->crashThenRecover)($dying);

    $perToken = $this->w->apnsRequestsPerToken();
    expect($this->sweep->retriedAfterUnknown)->toBe(1)
        ->and($perToken)->toHaveCount(10)
        ->and(array_sum($perToken))->toBe(10)
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 10]);
});

it('crash after the response, before saving: the outcome is unknown, never failed, and the alert is not re-sent', function (): void {
    ($this->queue)(PushMessage::make('Order', 'Shipped'));
    $dying = new DyingLedger(app(Ledger::class));
    $dying->loseRecordOf = 5;

    ($this->crashThenRecover)($dying);

    $perToken = $this->w->apnsRequestsPerToken();
    expect($this->beforeRecovery)->toBe(['accepted' => 4, 'leased' => 5, 'sending' => 1])
        ->and($perToken)->toHaveCount(10)   // the lost one really went out, once
        ->and(array_sum($perToken))->toBe(10)
        ->and(array_values(array_unique($perToken)))->toBe([1])
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 9, 'outcome_unknown' => 1]);
});

it('two relay jobs for the same effect at once: every delivery is sent exactly once', function (): void {
    config()->set('mobile-push.queue.chunk', 3);
    $effectId = ($this->queue)(PushMessage::make('Order', 'Shipped'));
    $second = false;
    $this->w->transport->respondUsing(function () use (&$second, $effectId) {
        if (! $second) {
            $second = true;   // while the first worker is on the network, a second one starts on the same effect
            $this->w->run(new RelayDeliveriesJob('default', $effectId));
        }

        return null;
    });

    $this->w->run(Queue::pushed(RelayDeliveriesJob::class)->first());

    $perToken = $this->w->apnsRequestsPerToken();
    expect($second)->toBeTrue()
        ->and($perToken)->toHaveCount(10)
        ->and(array_values(array_unique($perToken)))->toBe([1])
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 10]);
});
