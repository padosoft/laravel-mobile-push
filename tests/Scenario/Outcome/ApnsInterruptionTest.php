<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryPlanner;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Outcome\OutcomeKit as K;
use Padosoft\MobilePush\Tests\Support\FakeClock;

$lostResponse = static fn (): TransportFailure => new TransportFailure(TransportEvidence::Unknown, 16, 'HTTP/2 stream was reset after the request was written');

it('APNs accepted the alert but the response was lost: the outcome stays unknown, nothing is retried or invalidated', function () use ($lostResponse): void {
    $transport = (new FakeTransport)->push($lostResponse());
    $clock = new FakeClock;

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), new RetryPlanner, $clock, K::apnsRequest(), replaceable: false);
    $final = end($plans);

    expect($transport->requests())->toHaveCount(1)
        ->and($plans)->toHaveCount(1)
        ->and($final->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($final->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($final->status->allowsFallback())->toBeFalse()
        ->and($final->decision?->destinationAction)->toBe(DestinationAction::None)
        ->and($final->decision?->credentialAction)->toBe(CredentialAction::None)
        ->and($final->nextAttemptAt)->toBeNull();
});

it('the same loss on a replaceable Live Activity update is retried and then accepted', function () use ($lostResponse): void {
    $transport = (new FakeTransport)->push($lostResponse(), new Response(200, ['apns-id' => K::APNS_ID]));
    $clock = new FakeClock('2026-11-20T10:00:00Z');

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(K::liveActivity()), new RetryPlanner(new RetryPolicy(jitter: 0.0)), $clock, K::apnsRequest(), replaceable: true);

    expect($transport->requests())->toHaveCount(2)
        ->and(array_map(static fn ($p) => $p->status, $plans))->toBe([SendStatus::Deferred, SendStatus::Accepted])
        ->and($plans[0]->nextAttemptAt?->format(DATE_ATOM))->toBe('2026-11-20T10:00:01+00:00')
        ->and($plans[1]->decision?->providerMessageId)->toBe(K::APNS_ID)
        ->and($plans[1]->budget->attempts)->toBe(2)
        ->and($plans[1]->budget->unknownOutcomeSeen)->toBeTrue();
});

it('a replaceable update whose retries all fail after a lost response ends unknown, never failed', function () use ($lostResponse): void {
    $transport = (new FakeTransport)->push($lostResponse())->defaultTo(new Response(503, [], '{"reason":"ServiceUnavailable"}'));

    $plans = K::drive($transport, new ApnsOutcomeClassifier(serverErrorRetryDelaySeconds: 1), K::apnsContext(K::liveActivity()), new RetryPlanner(new RetryPolicy(maxAttempts: 3)), new FakeClock, K::apnsRequest(), replaceable: true);
    $final = end($plans);

    expect($transport->requests())->toHaveCount(3)
        ->and($final->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($final->reason->code)->toBe(ReasonCode::RetryBudgetExhausted)
        ->and($final->status->allowsFallback())->toBeFalse();
});

it('APNs is down for an hour and the alert expires in 10 minutes: it ends Expired after one attempt', function (): void {
    $transport = (new FakeTransport)->defaultTo(new Response(503, ['apns-id' => K::APNS_ID], '{"reason":"ServiceUnavailable"}'));
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $planner = new RetryPlanner;

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), $planner, $clock, K::apnsRequest(), expiresAt: new DateTimeImmutable('2026-11-20T10:10:00Z'));
    $final = end($plans);

    expect($transport->requests())->toHaveCount(1)
        ->and($final->status)->toBe(SendStatus::Expired)
        ->and($final->reason->code)->toBe(ReasonCode::Expired)
        ->and($final->reason->rawReason)->toBe('ServiceUnavailable')
        ->and($final->nextAttemptAt)->toBeNull()
        ->and($final->decision?->throttleProvider)->toBeTrue()
        ->and($final->status->allowsFallback())->toBeTrue();

    // A worker that would still pick the destination up later must not touch the network.
    $clock->advance(3600);
    expect($planner->beforeAttempt($final->budget, $clock->now())?->status)->toBe(SendStatus::Expired);
});

it('APNs 503 with a long-lived message is deferred by Apple\'s 15 minutes, then accepted', function (): void {
    $transport = (new FakeTransport)->push(new Response(503, [], '{"reason":"ServiceUnavailable"}'), new Response(200));
    $clock = new FakeClock('2026-11-20T10:00:00Z');

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), new RetryPlanner, $clock, K::apnsRequest(), expiresAt: new DateTimeImmutable('2026-11-21T10:00:00Z'));

    expect($transport->requests())->toHaveCount(2)
        ->and($plans[0]->status)->toBe(SendStatus::Deferred)
        ->and($plans[0]->nextAttemptAt >= new DateTimeImmutable('2026-11-20T10:15:00Z'))->toBeTrue()
        ->and($plans[0]->inline)->toBeFalse()
        ->and(end($plans)->status)->toBe(SendStatus::Accepted);
});
