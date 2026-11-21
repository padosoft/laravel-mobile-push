<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Bus;
use Padosoft\MobilePush\Laravel\Queue\QueueSettings;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Queue\RelayFollowUps;
use Padosoft\MobilePush\Laravel\Queue\SendJobDispatcher;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Log\AbstractLogger;

/*
 * The follow-up markers are an optimisation: with the cache store down, a follow-up relay is still dispatched (a
 * duplicate relay is a no-op for the ledger, a missing one leaves deferred deliveries waiting), the job still runs,
 * nothing throws, and the dead store is not hammered. With a working store the dedupe is unchanged.
 */

final class ThrowingStore implements Store
{
    public int $calls = 0;

    private function down(): never
    {
        $this->calls++;

        throw new RuntimeException('Connection refused');
    }

    public function get($key)
    {
        $this->down();
    }

    public function many(array $keys)
    {
        $this->down();
    }

    public function put($key, $value, $seconds)
    {
        $this->down();
    }

    public function putMany(array $values, $seconds)
    {
        $this->down();
    }

    public function increment($key, $value = 1)
    {
        $this->down();
    }

    public function decrement($key, $value = 1)
    {
        $this->down();
    }

    public function forever($key, $value)
    {
        $this->down();
    }

    public function touch($key, $seconds)
    {
        $this->down();
    }

    public function forget($key)
    {
        $this->down();
    }

    public function flush()
    {
        $this->down();
    }

    public function getPrefix()
    {
        return '';
    }
}

final class WarningsLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    public array $warnings = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = [(string) $message, $context];
        }
    }
}

function followUps(Store $store, FakeClock $clock, ?WarningsLogger $logger = null): RelayFollowUps
{
    $settings = QueueSettings::fromConfig(config());

    return new RelayFollowUps(new Repository($store), new SendJobDispatcher(app(Dispatcher::class), $settings), $clock, $settings, $logger);
}

/** @return list<RelayDeliveriesJob> */
function relayJobs(): array
{
    return Bus::dispatched(RelayDeliveriesJob::class)->values()->all();
}

beforeEach(function (): void {
    Bus::fake();
    config()->set('mobile-push.queue.follow_up_bucket', 0);
});

it('dispatches every follow-up without a marker when the store throws, lets the job start, and throws nothing', function (): void {
    $clock = new FakeClock;
    $store = new ThrowingStore;
    $logger = new WarningsLogger;
    $followUps = followUps($store, $clock, $logger);
    $at = $clock->now()->modify('+900 seconds');

    $first = $followUps->schedule('default', 'eff-1', $at);
    $second = $followUps->schedule('default', 'eff-1', $at);   // a working store would drop this one
    $followUps->started('default', 'eff-1', $at->getTimestamp());

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and(relayJobs())->toHaveCount(2)
        ->and(relayJobs()[0]->effectId)->toBe('eff-1')
        ->and(relayJobs()[0]->followUpAt)->toBe($at->getTimestamp())
        // one call failed; the store then rests for RETRY_AFTER_SECONDS and is not called again
        ->and($store->calls)->toBe(1)
        ->and($logger->warnings)->toHaveCount(1)
        ->and($logger->warnings[0][1])->toBe(['exception' => RuntimeException::class]);
});

it('tries the store again after the rest, and logs again at most once a minute', function (): void {
    $clock = new FakeClock;
    $store = new ThrowingStore;
    $logger = new WarningsLogger;
    $followUps = followUps($store, $clock, $logger);

    $followUps->schedule('default', 'eff-1', $clock->now());
    $clock->advance(RelayFollowUps::RETRY_AFTER_SECONDS);
    $followUps->schedule('default', 'eff-2', $clock->now());
    $clock->advance(60);
    $followUps->schedule('default', 'eff-3', $clock->now());

    expect(relayJobs())->toHaveCount(3)
        ->and($store->calls)->toBe(3)
        ->and($logger->warnings)->toHaveCount(2);
});

it('keeps the dedupe with a working store: a later or equal request is dropped, an earlier one replaces the marker', function (): void {
    $clock = new FakeClock;
    $store = new ArrayStore;
    $followUps = followUps($store, $clock);
    $at = $clock->now()->modify('+900 seconds');

    expect($followUps->schedule('default', 'eff-1', $at))->toBeTrue()
        ->and($followUps->schedule('default', 'eff-1', $at))->toBeFalse()
        ->and($followUps->schedule('default', 'eff-1', $at->modify('+60 seconds')))->toBeFalse()
        ->and($followUps->schedule('default', 'eff-1', $at->modify('-60 seconds')))->toBeTrue()
        ->and(relayJobs())->toHaveCount(2);

    // the job of the earlier time clears its own marker: a new request is dispatched again
    $followUps->started('default', 'eff-1', $at->modify('-60 seconds')->getTimestamp());
    expect($followUps->schedule('default', 'eff-1', $at))->toBeTrue()
        ->and(relayJobs())->toHaveCount(3);
});
