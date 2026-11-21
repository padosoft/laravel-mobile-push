<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\ApnsCredential;
use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Stories about the in-process retry loop of one send: how long it waits, what stops it, and how the bearer and
 * the credential are handled between passes.
 */

function notSent(): TransportFailure
{
    return new TransportFailure(TransportEvidence::NotSent, 7, 'connect() refused', false);
}

/** An APNs key whose .p8 file cannot be read on this server. */
function unreadableKey(): AccessTokenProvider
{
    return new class implements AccessTokenProvider
    {
        public int $calls = 0;

        public function token(): AuthToken
        {
            $this->calls++;

            throw new AuthenticationException('The signing key cannot be loaded.');
        }

        public function refreshAfterRejection(AuthToken $rejected): AuthToken
        {
            return $this->token();
        }

        public function identity(): string
        {
            return SendKit::TEAM.':BROKEN1111';
        }
    };
}

it('waits inline exactly the planned backoff when it is below the inline threshold, not the threshold itself', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(notSent(), new Response(200));
    $sender = $kit->sender($transport, policy: new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, inlineThresholdMs: 5000));

    [$result] = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live()));

    expect($kit->sleeps)->toBe([1000])
        ->and($transport->requests())->toHaveCount(2)
        ->and($result->status)->toBe(SendStatus::Accepted)
        ->and($result->attempts)->toBe(2);
});

/*
 * Story: a worker is asked to wait one second before resending two alerts that never left; the container is
 * frozen for ten seconds instead, and the alerts' five-second lifetime is over when it wakes up. Neither may be
 * sent: both expire, with the cause of the first attempt kept.
 */
it('expires the pending destinations without sending them when the inline wait overran the message lifetime', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(notSent(), notSent());
    $sleeps = [];
    $sender = new Sender(
        $transport,
        $kit->guards(),
        new SafeModeGuard($kit->index),
        $kit->clock,
        new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, inlineThresholdMs: 1000),
        [],
        function (int $ms) use ($kit, &$sleeps): void {
            $sleeps[] = $ms;
            $kit->clock->advance(10);   // the worker was paused far longer than asked
        },
    );

    $results = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('Your code', '123456')->ttl(5), SendKit::apnsTokens(2), SendKit::live()));

    expect($sleeps)->toBe([1000])
        ->and($transport->requests())->toHaveCount(2)
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Expired)
        ->and(array_map(fn ($r) => $r->reason->code, $results))->each->toBe(ReasonCode::Expired)
        ->and($results[1]->reason->rawReason)->toBe('transport_not_sent')
        ->and($results[1]->attempts)->toBe(1);
});

/*
 * Story: the primary .p8 key cannot be read on a freshly deployed server; the binding has a secondary key. No
 * request was made with the primary, so switching costs no attempt: every destination of the window goes out once,
 * signed by the secondary.
 */
it('fails over to the secondary key when no primary bearer can be obtained, for every destination of the window', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $observer = new RecordingObserver;
    $driver = $kit->apnsDriver(ApnsCredential::token(unreadableKey(), SendKit::TEAM, 'BROKEN1111'), $kit->apnsCredential('NEWKEY2222'), window: 3);

    $results = SendKit::all($kit->sender($transport, $kit->guards(authThreshold: 1), observers: [$observer])->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Accepted)
        ->and(array_map(fn ($r) => $r->attempts, $results))->each->toBe(1)
        ->and(array_map(fn ($r) => $r->meta['credential_generation'], $results))->each->toBe('1')
        ->and($transport->requests())->toHaveCount(3)
        ->and($kit->signers['NEWKEY2222']->signatures)->toBe(1)
        ->and(array_filter($observer->transitions, fn ($t) => $t->transition === BreakerTransition::FailedOver))->toHaveCount(1);
});

it('fails every destination without a request when neither key can produce a bearer, and stops there', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $primary = unreadableKey();
    $secondary = unreadableKey();
    $driver = $kit->apnsDriver(ApnsCredential::token($primary, SendKit::TEAM, 'BROKEN1111'), ApnsCredential::token($secondary, SendKit::TEAM, 'BROKEN2222'), window: 3);

    $results = SendKit::all($kit->sender($transport, $kit->guards(authThreshold: 1))->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Failed)
        ->and(array_map(fn ($r) => $r->reason->code, $results))->each->toBe(ReasonCode::AuthTokenInvalid)
        ->and($results[0]->reason->hint)->toContain('generation 1')
        ->and($transport->requests())->toBe([])
        ->and($primary->calls)->toBe(1)
        ->and($secondary->calls)->toBe(1);
});

/*
 * Story: Google refuses the access token twice in a row (a revoked token, then one that a misbehaving proxy
 * strips). Each refused token is refreshed once; a token that was refreshed is not refreshed again; the second
 * refusal falls back to normal backoff.
 */
it('refreshes each refused access token once, also a second one refused in the same send', function () {
    $kit = new SendKit;
    $oauth = SendKit::oauthAnswering('ya29.token-a', 'ya29.token-b', 'ya29.token-c');
    $transport = (new FakeTransport)->push(SendKit::fcmError(401, 'UNAUTHENTICATED'), SendKit::fcmError(401, 'UNAUTHENTICATED'));
    $observer = new RecordingObserver;

    [$result] = SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->fcmDriver($oauth), PushMessage::make('A', 'B'), [SendKit::fcmToken(1)], SendKit::live()));

    expect(array_map(fn (Request $r) => $r->headers['authorization'], $transport->requests()))->toBe(['Bearer ya29.token-a', 'Bearer ya29.token-b'])
        ->and($oauth->requests())->toHaveCount(3)
        ->and($observer->refreshes)->toHaveCount(2)
        ->and($result->status)->toBe(SendStatus::Deferred)
        ->and($result->attempts)->toBe(2)
        ->and($result->nextAttemptAt)->toEqual($kit->clock->now()->modify('+2000 milliseconds'));
});

it('reports no token refresh to the observers when the refresh itself fails', function () {
    $kit = new SendKit;
    $oauth = SendKit::oauthAnswering('ya29.token-a')->push(new Response(500, [], '{"error":"internal_failure"}', '1.1'));
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmError(401, 'UNAUTHENTICATED'));
    $observer = new RecordingObserver;

    [$result] = SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->fcmDriver($oauth), PushMessage::make('A', 'B'), [SendKit::fcmToken(1)], SendKit::live()));

    expect($observer->refreshes)->toBe([])
        ->and($oauth->requests())->toHaveCount(2)
        ->and($result->status)->toBe(SendStatus::Deferred)
        ->and($result->reason->code)->toBe(ReasonCode::AuthTokenExpired);
});

/*
 * Story: a bulk send hits the application's own ceiling of two messages per minute. The two permits go out; the
 * other destinations are deferred to the instant the next permit exists, not to an arbitrary minute later.
 */
it('defers the destinations over the rate ceiling to the instant the next permit is available', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;

    $results = SendKit::all($kit->sender($transport, $kit->guards(ratePerMinute: 2))->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(5), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([
        SendStatus::Accepted, SendStatus::Accepted, SendStatus::Deferred, SendStatus::Deferred, SendStatus::Deferred,
    ])
        ->and($transport->requests())->toHaveCount(2)
        ->and($results[2]->reason->code)->toBe(ReasonCode::QuotaExceeded)
        ->and($results[2]->nextAttemptAt)->toEqual($kit->clock->now()->modify('+30 seconds'))
        ->and($results[4]->nextAttemptAt)->toEqual($kit->clock->now()->modify('+30 seconds'));
});

/*
 * Story: in one window an alert never left (to be resent in a second) and APNs refused the key for another device,
 * which opens the authentication breaker. The worker then sleeps past both the alert's lifetime and the breaker's
 * cool-down. The alert expires without a request; a pass with nothing left to send must not ask the breaker for
 * its single half-open probe, which would then be lost for every other worker until the next cool-down.
 */
it('does not consume the half-open probe of the breaker for a pass left with nothing to send', function () {
    $kit = new SendKit;
    $stuck = SendKit::apnsToken(1)->value();
    $transport = (new FakeTransport)->respondUsing(fn (Request $r) => SendKit::tokenOf($r) === $stuck ? notSent() : SendKit::apnsReason(403, 'InvalidProviderToken'));
    $observer = new RecordingObserver;
    $sender = new Sender(
        $transport,
        $kit->guards(authThreshold: 1),
        new SafeModeGuard($kit->index),
        $kit->clock,
        new RetryPolicy(backoffBaseMs: 1000, jitter: 0.0, inlineThresholdMs: 1000),
        [$observer],
        fn (int $ms) => $kit->clock->advance(120),
    );

    $results = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('Your code', '123456')->ttl(5), [SendKit::apnsToken(1), SendKit::apnsToken(2)], SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Expired, SendStatus::Failed])
        ->and($transport->requests())->toHaveCount(2)
        ->and(array_map(fn ($t) => $t->transition, $observer->transitions))->toBe([BreakerTransition::Opened]);
});
