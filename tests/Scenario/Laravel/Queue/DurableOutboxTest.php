<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Queue\QueueModule;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Durable profile, the outbox half: the intent lives and dies with the business transaction; one relay job
 * carrying only ids is dispatched after the commit; the sweeper recovers intents whose job never made it.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->w = QueueWorld::boot('durable');
    $this->w->dispatcher->add($this->w->kit->apnsDriver());
    $this->queue = static fn (PushMessage $message, iterable $destinations, string $key = 'order-42'): string => app(QueuedSender::class)
        ->queue(SendKit::apnsBinding(), $message, $destinations, SendKit::live(effectKey: $key), new QueueOptions);
    $this->runRelays = function (): void {
        foreach (Queue::pushed(RelayDeliveriesJob::class) as $job) {
            $this->w->run($job);
        }
    };
});

it('order committed, push committed: effect and deliveries exist and one relay job is dispatched after the commit', function (): void {
    Schema::create('orders_fixture', static fn (Blueprint $t) => $t->id());
    $pushedInside = null;

    $effectId = DB::transaction(function () use (&$pushedInside): string {
        DB::table('orders_fixture')->insert([]);
        $id = ($this->queue)(PushMessage::make('Order', 'Paid'), QueueWorld::tokens(2));
        $pushedInside = Queue::pushed(RelayDeliveriesJob::class)->count();

        return $id;
    });

    expect($pushedInside)->toBe(0)
        ->and(DB::table('mobile_push_effects')->count())->toBe(1)
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(2);
    Queue::assertPushed(RelayDeliveriesJob::class, 1);
    $job = Queue::pushed(RelayDeliveriesJob::class)->first();
    expect([$job->scope, $job->effectId, $job->queue])->toBe(['default', $effectId, 'push'])
        ->and(array_keys(get_object_vars($job)))->not->toContain('message', 'destinations');

    $this->w->run($job);

    expect($this->w->transport->sentCount())->toBe(2)
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 2]);
});

it('order rolled back, push rolled back: no rows and no job', function (): void {
    Schema::create('orders_fixture', static fn (Blueprint $t) => $t->id());

    DB::beginTransaction();
    DB::table('orders_fixture')->insert([]);
    ($this->queue)(PushMessage::make('Order', 'Paid'), QueueWorld::tokens(2));
    DB::rollBack();

    expect(DB::table('orders_fixture')->count())->toBe(0)
        ->and(DB::table('mobile_push_effects')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('crash before the dispatch: the rows exist without a relay job, the sweeper finds them and nothing is lost', function (): void {
    // The intent committed, then the process died before the relay job reached the queue.
    $this->w->ledger()->recordIntent(new EffectIntent(SendKit::apnsBinding(), PushMessage::make('Order', 'Paid'), 'order-7'), QueueWorld::tokens(3));
    Queue::assertNothingPushed();

    $report = $this->w->sweeper()->sweep();

    expect($report->relaysDispatched)->toBe(['default']);
    Queue::assertPushed(RelayDeliveriesJob::class, 1);
    ($this->runRelays)();

    expect($this->w->apnsRequestsPerToken())->toHaveCount(3)
        ->and(array_unique($this->w->apnsRequestsPerToken()))->toHaveCount(1)
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 3])
        ->and($this->w->sweeper()->sweep()->relaysDispatched)->toBe([]);
});

it('same key twice: one effect, each destination sent once; another payload with the same key is refused before queuing', function (): void {
    $first = ($this->queue)(PushMessage::make('Order', 'Paid'), QueueWorld::tokens(3));
    $second = ($this->queue)(PushMessage::make('Order', 'Paid'), QueueWorld::tokens(3));

    expect($second)->toBe($first);
    Queue::assertPushed(RelayDeliveriesJob::class, 1);

    expect(fn () => ($this->queue)(PushMessage::make('Order', 'Refunded'), QueueWorld::tokens(3)))
        ->toThrow(IdempotencyConflictException::class);
    Queue::assertPushed(RelayDeliveriesJob::class, 1);

    ($this->runRelays)();
    ($this->runRelays)();

    $perToken = $this->w->apnsRequestsPerToken();
    ksort($perToken);
    expect($perToken)->toBe([
        QueueWorld::token(0)->value() => 1, QueueWorld::token(1)->value() => 1, QueueWorld::token(2)->value() => 1,
    ])->and(DB::table('mobile_push_effects')->count())->toBe(1);
});

it('two tenants on one worker: same effect key and tokens in two scopes never read or send each other', function (): void {
    foreach (['acme', 'beta'] as $scope) {
        $this->w->dispatcher->add($this->w->kit->apnsDriver($this->w->kit->apnsCredential(scope: $scope), binding: SendKit::apnsBinding('ios', $scope)));
    }
    $ids = [];
    foreach (['acme', 'beta'] as $scope) {
        $ids[$scope] = app(QueuedSender::class)->queue(SendKit::apnsBinding('ios', $scope), PushMessage::make('Hi', 'from '.$scope),
            QueueWorld::tokens(3), SendKit::live(effectKey: 'order-1'), new QueueOptions);
    }
    $bodies = static fn (array $requests): array => array_values(array_unique(array_map(
        static fn (Request $r): string => json_decode($r->body, true)['aps']['alert']['body'], $requests)));

    // A job of one tenant naming the other tenant's effect id claims nothing.
    $this->w->run(new RelayDeliveriesJob('acme', $ids['beta']));
    expect($this->w->transport->sentCount())->toBe(0);

    $this->w->run(new RelayDeliveriesJob('acme', $ids['acme']));
    expect($this->w->transport->sentCount())->toBe(3)
        ->and($bodies($this->w->transport->requests()))->toBe(['from acme'])
        ->and(QueueWorld::deliveryStates($ids['beta']))->toBe(['pending' => 3]);

    $this->w->transport->reset();
    $this->w->run(new RelayDeliveriesJob('beta', null));
    expect($this->w->transport->sentCount())->toBe(3)
        ->and($bodies($this->w->transport->requests()))->toBe(['from beta'])
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 6]);
});

it('the sweeper is scheduled every minute in the durable profile', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn ($event): bool => $event->description === QueueModule::SWEEP_EVENT);

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
