<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: the provider refuses the bearer as expired, and no other bearer can be had right now. Sending the refused
 * one again cannot work: it only spends an attempt and a request. The destinations wait instead.
 */
it('does not send a refused APNs token again when Apple\'s 20-minute floor forbids minting a new one', function () {
    $kit = new SendKit;
    $driver = $kit->apnsDriver(window: 10);
    $transport = (new FakeTransport)->defaultTo(SendKit::apnsReason(403, 'ExpiredProviderToken'));   // a token minted this second
    $observer = new RecordingObserver;
    $start = $kit->clock->now();

    $results = SendKit::all($kit->sender($transport, observers: [$observer])->send($driver, PushMessage::make('Hi', 'Batch'), SendKit::apnsTokens(3), SendKit::live()));

    $bearers = array_unique(array_map(static fn (Request $r): string => $r->headers['authorization'], $transport->requests()));
    expect($transport->requests())->toHaveCount(3)                           // one request each: the refused bearer is not sent twice
        ->and($bearers)->toHaveCount(1)
        ->and($kit->signers['KEY1111111']->signatures)->toBe(1)               // nothing new was signed
        ->and($observer->refreshes)->toBe([])                                 // and nobody is told a refresh happened
        ->and(array_map(static fn ($r) => $r->status, $results))->each->toBe(SendStatus::Deferred)
        ->and(array_map(static fn ($r) => $r->reason->code, $results))->each->toBe(ReasonCode::AuthTokenExpired)
        ->and(array_map(static fn ($r) => $r->attempts, $results))->each->toBe(1)
        ->and(array_map(static fn ($r) => $r->nextAttemptAt > $start, $results))->each->toBeTrue()
        ->and($kit->sleeps)->toBe([]);
});

it('does not send a refused FCM access token again when the refresh at Google fails', function () {
    $kit = new SendKit;
    // The first exchange gives a token; the refresh after the rejection finds Google's token endpoint failing.
    $oauth = SendKit::oauthAnswering('ya29.token-a')->defaultTo(new Response(500, [], 'backend error', '1.1'));
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmError(401, 'UNAUTHENTICATED'));
    $observer = new RecordingObserver;

    $results = SendKit::all($kit->sender($transport, observers: [$observer])
        ->send($kit->fcmDriver($oauth), PushMessage::make('Hi', 'There'), [SendKit::fcmToken(1), SendKit::fcmToken(2)], SendKit::live()));

    expect($transport->requests())->toHaveCount(2)                           // one send each, never the refused bearer a second time
        ->and($observer->refreshes)->toBe([])
        ->and(array_map(static fn ($r) => $r->status, $results))->each->toBe(SendStatus::Deferred)
        ->and(array_map(static fn ($r) => $r->reason->code, $results))->each->toBe(ReasonCode::AuthTokenExpired)
        ->and(array_map(static fn ($r) => $r->attempts, $results))->each->toBe(1);
});
