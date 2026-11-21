<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Integration\EffectSettled;
use Padosoft\MobilePush\Laravel\Integration\EffectSettlement;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Notifications\DurableFallbacks;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\PackageQueuedOrderShipped;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\Shopper;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\NotificationsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;

/*
 * Durable profile, a push queued by the notification channel (QueuesPush): its outcome is only known after the
 * relay, possibly much later. The fallback mail is decided when the effect settles (EffectSettled), from the
 * ledger's final results, exactly once.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->world = NotificationsWorld::install(['delivery.profile' => 'durable']);
    foreach (LedgerModule::migrationPaths() as $path) {
        (require $path)->up();
    }
    app()->forgetInstance(DatabaseLedger::class);
    Schema::create('shoppers_fixture', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('email');
    });
    DB::table('shoppers_fixture')->insert(['id' => 1, 'email' => 'ada@example.test']);
    $this->user = Shopper::query()->findOrFail(1);
    $this->world->iphone($this->user);

    /** Run every relay job not run yet, then the glue's after-relay check (what IntegrationModule does on JobProcessed). */
    $this->relay = function (): void {
        foreach (Queue::pushed(RelayDeliveriesJob::class) as $job) {
            if (isset($this->ran[spl_object_id($job)])) {
                continue;
            }
            $this->ran[spl_object_id($job)] = true;
            if ($job->followUpAt !== null) {
                $this->world->push->clock->advance(max(0, $job->delay ?? 0));
            }
            app()->call([$job, 'handle']);
            app(EffectSettlement::class)->check($job->scope, $job->effectId);
        }
    };
    $this->ran = [];
});

it('queued durably, the only device answers 410 an hour later: the mail goes out once', function (): void {
    $answers = [
        new Response(503, ['apns-id' => '11111111-2222-4333-8444-555555555555', 'retry-after' => '3600'], (string) json_encode(['reason' => 'ServiceUnavailable'])),
        new Response(410, ['apns-id' => '11111111-2222-4333-8444-555555555555'], (string) json_encode(['reason' => 'Unregistered'])),
    ];
    $this->world->push->answer = static function (Request $r) use (&$answers): ?Response {
        return str_contains($r->url, 'api.push.apple.com') ? array_shift($answers) : null;
    };

    $this->user->notify(new PackageQueuedOrderShipped(fallback: ['mail']));
    expect($this->world->mailCount())->toBe(0);

    ($this->relay)(); // 503: deferred, nothing is known yet
    expect($this->world->mailCount())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->value('state'))->toBe('deferred');

    ($this->relay)(); // the follow-up, an hour later: 410 Unregistered
    expect(DB::table('mobile_push_deliveries')->value('state'))->toBe('rejected')
        ->and($this->world->push->requestsTo('api.push.apple.com'))->toHaveCount(2)
        ->and($this->world->mailCount())->toBe(1)
        ->and($this->world->mails()[0]->getOriginalMessage()->getSubject())->toBe('Your order shipped')
        ->and($this->world->fellBack)->toHaveCount(1);

    // The settlement announced again (a lost "announced" marker, a duplicate relay): no second mail.
    $effectId = (string) DB::table('mobile_push_effects')->value('id');
    $counts = app(DatabaseLedger::class)->counts('default', $effectId);
    event(new EffectSettled('default', $effectId, $counts, null));
    event(new EffectSettled('default', $effectId, $counts, null));
    expect($this->world->mailCount())->toBe(1);
});

it('queued durably, the outcome stays unknown: no mail', function (): void {
    $this->world->push->answer = static fn (Request $r): mixed => str_contains($r->url, 'api.push.apple.com')
        ? new TransportFailure(TransportEvidence::Unknown, 56, 'connection reset after the request was written', true)
        : null;

    $this->user->notify(new PackageQueuedOrderShipped(fallback: ['mail']));
    for ($i = 0; $i < 6; $i++) {
        ($this->relay)();
    }

    expect(DB::table('mobile_push_deliveries')->value('state'))->toBe('outcome_unknown')
        ->and($this->world->mailCount())->toBe(0)
        ->and($this->world->fellBack)->toBe([]);
});

it('keeps the remembered notification encrypted, without the token, and only when a fallback was asked for', function (): void {
    $this->user->notify(new PackageQueuedOrderShipped(fallback: []));
    $this->user->notify(new PackageQueuedOrderShipped(fallback: ['mail']));

    $effects = DB::table('mobile_push_effects')->orderBy('created_at')->pluck('id')->all();
    $store = app('cache')->store();
    $keys = array_map(static fn (string $id): string => 'mobile-push:fallback:effect:'.hash('sha256', "default\0".$id), $effects);
    $kept = array_values(array_filter(array_map(static fn (string $k): mixed => $store->get($k), $keys)));

    expect($kept)->toHaveCount(1)
        ->and($kept[0])->toBeString()
        ->and(strlen($kept[0]))->toBeLessThanOrEqual(DurableFallbacks::MAX_BYTES)
        ->and($kept[0])->not->toContain('OrderShipped')
        ->and($kept[0])->not->toContain($this->world->tokens[0])
        ->and($kept[0])->not->toContain('ada@example.test');
});

it('does nothing with the light profile: a queued push never falls back', function (): void {
    config()->set('mobile-push.delivery.profile', 'light');
    SendingWorld::forgetSingletons();

    $this->user->notify(new PackageQueuedOrderShipped(fallback: ['mail']));

    expect(app(DurableFallbacks::class)->remember('default', 'x', $this->user, new PackageQueuedOrderShipped(fallback: ['mail']), 'k'))->toBeFalse()
        ->and($this->world->mailCount())->toBe(0);
});
