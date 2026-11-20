<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\AccountKey;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: a marketing burst hits the Firebase project quota. Nothing may be lost and no worker may sleep for a minute.
 */
it('defers the throttled destinations at least 60 s ahead, pauses the project, and defers the rest without sending', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmError(429, 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED', ['retry-after' => '30']));
    $observer = new RecordingObserver;
    $start = $kit->clock->now();
    $destinations = (function () {
        for ($i = 0; $i < 12; $i++) {
            yield SendKit::fcmToken($i);
        }
    })();

    $results = SendKit::all($kit->sender($transport, observers: [$observer])
        ->send($kit->fcmDriver(window: 5), PushMessage::make('Sale', 'Now'), $destinations, SendKit::live()));

    expect($transport->requests())->toHaveCount(5)          // only the first window reached FCM
        ->and($results)->toHaveCount(12)
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Deferred)
        ->and(array_map(fn ($r) => $r->reason->code, $results))->each->toBe(ReasonCode::QuotaExceeded)
        ->and(array_map(fn ($r) => $r->nextAttemptAt >= $start->modify('+60 seconds'), $results))->each->toBeTrue()
        ->and(array_map(fn ($r) => $r->attempts, array_slice($results, 0, 5)))->each->toBe(1)
        ->and(array_map(fn ($r) => $r->attempts, array_slice($results, 5)))->each->toBe(0)
        ->and($kit->sleeps)->toBe([])
        ->and($kit->clock->now())->toEqual($start)
        ->and($observer->throttles)->not->toBeEmpty();

    $paused = (new ThrottleGate($kit->guardStore, $kit->clock))->pausedUntil(AccountKey::of(Provider::Fcm, SendKit::PROJECT));
    expect($paused)->not->toBeNull()
        ->and($paused >= $start->modify('+60 seconds'))->toBeTrue();
});

it('lets the deferred destinations through once the pause is over', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(SendKit::fcmError(429, 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED'))->defaultTo(SendKit::fcmOk());
    $driver = $kit->fcmDriver();
    $sender = $kit->sender($transport);

    [$first] = SendKit::all($sender->send($driver, PushMessage::make('A', 'B'), [SendKit::fcmToken(1)], SendKit::live()));
    [$early] = SendKit::all($sender->send($driver, PushMessage::make('A', 'B'), [SendKit::fcmToken(2)], SendKit::live()));
    $kit->clock->set($first->nextAttemptAt ?? throw new LogicException('deferred without nextAttemptAt'));
    [$later] = SendKit::all($sender->send($driver, PushMessage::make('A', 'B'), [SendKit::fcmToken(2)], SendKit::live()));

    expect($first->status)->toBe(SendStatus::Deferred)
        ->and($early->status)->toBe(SendStatus::Deferred)
        ->and($early->attempts)->toBe(0)
        ->and($later->status)->toBe(SendStatus::Accepted)
        ->and($transport->requests())->toHaveCount(2);
});
