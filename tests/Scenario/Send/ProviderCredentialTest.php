<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: a provider token minted 25 minutes ago is suddenly refused as expired (the server clock jumped). Ten
 * requests are in flight with it. One refresh must fix all of them.
 */
it('refreshes the provider token exactly once when many in-flight requests are rejected with it, and retries each once', function () {
    $kit = new SendKit;
    $driver = $kit->apnsDriver(window: 10);
    $stale = $driver->tokenProvider(0)?->token()->value;   // minted now, as by an earlier send
    $kit->clock->advance(25 * 60);

    $transport = (new FakeTransport)->respondUsing(fn (Request $r) => $r->headers['authorization'] === 'bearer '.$stale
        ? SendKit::apnsReason(403, 'ExpiredProviderToken')
        : new Response(200));
    $observer = new RecordingObserver;

    $results = SendKit::all($kit->sender($transport, observers: [$observer])->send($driver, PushMessage::make('Hi', 'Batch'), SendKit::apnsTokens(10), SendKit::live()));

    $bearers = array_map(fn (Request $r) => $r->headers['authorization'], $transport->requests());
    expect($transport->requests())->toHaveCount(20)
        ->and(array_count_values($bearers))->toHaveCount(2)                     // the stale bearer and ONE fresh one
        ->and($kit->signers['KEY1111111']->signatures)->toBe(2)                  // first mint + one refresh
        ->and($observer->refreshes)->toBe([['generation' => 0, 'identity' => 'token:'.SendKit::TEAM.':KEY1111111']])
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Accepted)
        ->and(array_map(fn ($r) => $r->attempts, $results))->each->toBe(2)
        ->and($kit->sleeps)->toBe([]);
});

/*
 * Story: the .p8 key is revoked in the Apple portal while a 10,000-recipient campaign is going out.
 */
it('stops calling APNs when the key is revoked: provider calls are bounded by the breaker, the rest is skipped', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::apnsReason(403, 'InvalidProviderToken'));
    $observer = new RecordingObserver;
    $sender = $kit->sender($transport, $kit->guards(authThreshold: 5), observers: [$observer]);

    $report = $sender->send($kit->apnsDriver(window: 100), PushMessage::make('Sale', 'Today only'), SendKit::apnsTokens(10_000), SendKit::live());
    $counts = $report->counts();

    expect(count($transport->requests()))->toBeLessThanOrEqual(5 + 100)
        ->and($counts['failed'])->toBe(count($transport->requests()))
        ->and($counts['skipped'])->toBe(10_000 - count($transport->requests()))
        ->and($report->count())->toBe(10_000);

    $first = $report->retained()[0];
    $skipped = $report->retained()[999];
    expect($first->reason->code)->toBe(ReasonCode::AuthTokenInvalid)
        ->and($first->reason->rawReason)->toBe('InvalidProviderToken')
        ->and($skipped->status)->toBe(SendStatus::Skipped)
        ->and($skipped->reason->code)->toBe(ReasonCode::CircuitOpen)
        ->and($skipped->attempts)->toBe(0);

    $opened = array_filter($observer->transitions, fn ($t) => $t->guard === GuardKind::Auth && $t->transition === BreakerTransition::Opened);
    expect($opened)->toHaveCount(1);
});

/*
 * Story: the team rotates keys. The old key is revoked before every server picked up the new one; the binding
 * carries the new key as its secondary credential.
 */
it('fails over to the secondary key once: later windows use it on their own pool, no destination pays more than one extra attempt', function () {
    $kit = new SendKit;
    $driver = $kit->apnsDriver($kit->apnsCredential('OLDKEY1111'), $kit->apnsCredential('NEWKEY2222'), window: 10);
    $old = $driver->tokenProvider(0)?->token()->value;
    $transport = (new FakeTransport)->respondUsing(fn (Request $r) => $r->headers['authorization'] === 'bearer '.$old
        ? SendKit::apnsReason(403, 'InvalidProviderToken')
        : new Response(200));
    $observer = new RecordingObserver;

    $results = SendKit::all($kit->sender($transport, $kit->guards(authThreshold: 5), observers: [$observer])
        ->send($driver, PushMessage::make('Hi', 'Rotation'), SendKit::apnsTokens(30), SendKit::live()));

    $recorded = $transport->recorded();
    $byOld = array_filter($recorded, fn ($e) => $e['request']->headers['authorization'] === 'bearer '.$old);
    $byNew = array_filter($recorded, fn ($e) => $e['request']->headers['authorization'] !== 'bearer '.$old);
    $oldPools = array_unique(array_map(fn ($e) => $e['profile']->poolKey, $byOld));
    $newPools = array_unique(array_map(fn ($e) => $e['profile']->poolKey, $byNew));

    expect($byOld)->toHaveCount(10)                       // only the window in flight used the revoked key
        ->and($byNew)->toHaveCount(30)
        ->and($oldPools)->toHaveCount(1)
        ->and($newPools)->toHaveCount(1)
        ->and(reset($oldPools))->not->toBe(reset($newPools))
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Accepted)
        ->and(max(array_map(fn ($r) => $r->attempts, $results)))->toBe(2)
        ->and(array_sum(array_map(fn ($r) => $r->attempts, $results)))->toBe(40)
        ->and(array_map(fn ($r) => $r->meta['credential_generation'], $results))->each->toBe('1');

    $failovers = array_filter($observer->transitions, fn ($t) => $t->transition === BreakerTransition::FailedOver);
    expect($failovers)->toHaveCount(1);
});

it('reports a destination Failed without any request when no bearer can be obtained, and feeds the breaker', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $broken = new class implements \Padosoft\MobilePush\Core\Auth\AccessTokenProvider
    {
        public function token(): \Padosoft\MobilePush\Core\Auth\AuthToken
        {
            throw new \Padosoft\MobilePush\Core\Exceptions\AuthenticationException('The signing key cannot be loaded.');
        }

        public function refreshAfterRejection(\Padosoft\MobilePush\Core\Auth\AuthToken $rejected): \Padosoft\MobilePush\Core\Auth\AuthToken
        {
            return $this->token();
        }

        public function identity(): string
        {
            return SendKit::TEAM.':BROKEN1111';
        }
    };
    $driver = $kit->apnsDriver(\Padosoft\MobilePush\Core\Apns\ApnsCredential::token($broken, SendKit::TEAM, 'BROKEN1111'), window: 2);

    $report = $kit->sender($transport, $kit->guards(authThreshold: 2))->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(10), SendKit::live());
    $results = SendKit::all($report);

    expect($transport->requests())->toBe([])
        ->and($results)->toHaveCount(10)
        ->and(array_map(fn ($r) => $r->status, array_slice($results, 0, 4)))->each->toBe(SendStatus::Failed)
        ->and($results[0]->reason->code)->toBe(ReasonCode::AuthTokenInvalid)
        ->and($results[0]->attempts)->toBe(0)
        ->and(array_map(fn ($r) => $r->reason->code, array_slice($results, 4)))->each->toBe(ReasonCode::CircuitOpen);
});
