<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmOutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\CredentialAction;
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

$expired = static fn (): Response => new Response(403, ['apns-id' => K::APNS_ID], '{"reason":"ExpiredProviderToken"}');

it('token expired on a long-lived worker: refresh, immediate retry in the same budget, accepted', function () use ($expired): void {
    $transport = (new FakeTransport)->push($expired(), new Response(200, ['apns-id' => K::APNS_ID]));
    $clock = new FakeClock('2026-11-20T10:00:00Z');

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), new RetryPlanner, $clock, K::apnsRequest());

    expect($transport->requests())->toHaveCount(2)
        ->and($plans[0]->status)->toBe(SendStatus::Deferred)
        ->and($plans[0]->decision?->credentialAction)->toBe(CredentialAction::RefreshToken)
        ->and($plans[0]->delayMs)->toBe(0)
        ->and($plans[0]->inline)->toBeTrue()
        ->and($clock->now()->format(DATE_ATOM))->toBe('2026-11-20T10:00:00+00:00')
        ->and(end($plans)->status)->toBe(SendStatus::Accepted)
        ->and(end($plans)->budget->attempts)->toBe(2);
});

it('a token that keeps expiring does not loop forever: one immediate retry, then backoff, then the budget ends', function () use ($expired): void {
    $transport = (new FakeTransport)->defaultTo($expired());
    $planner = new RetryPlanner(new RetryPolicy(maxAttempts: 4, jitter: 0.0));

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), $planner, new FakeClock, K::apnsRequest());
    $final = end($plans);

    expect($transport->requests())->toHaveCount(4)
        ->and(array_map(static fn ($p) => $p->delayMs, array_slice($plans, 0, 3)))->toBe([0, 2000, 4000])
        ->and($final->status)->toBe(SendStatus::Failed)
        ->and($final->reason->code)->toBe(ReasonCode::RetryBudgetExhausted)
        ->and($final->reason->rawReason)->toBe('ExpiredProviderToken')
        ->and($final->budget->tokenRefreshes)->toBe(1);
});

it('connection refused three times: NotSent retries consume the budget and end Failed with the transport reason', function (): void {
    $transport = (new FakeTransport)->defaultTo(new TransportFailure(TransportEvidence::NotSent, 7, 'Connection refused', false));
    $clock = new FakeClock('2026-11-20T10:00:00Z');

    $plans = K::drive($transport, new FcmOutcomeClassifier($clock), K::fcmContext(), new RetryPlanner(new RetryPolicy(maxAttempts: 3, jitter: 0.0)), $clock, K::fcmRequest());
    $final = end($plans);

    expect($transport->requests())->toHaveCount(3)
        ->and(array_map(static fn ($p) => $p->status, $plans))->toBe([SendStatus::Deferred, SendStatus::Deferred, SendStatus::Failed])
        ->and($final->reason->code)->toBe(ReasonCode::RetryBudgetExhausted)
        ->and($final->reason->rawReason)->toBe('transport_not_sent')
        ->and($final->cause()?->code)->toBe(ReasonCode::TransportNotSent)
        ->and($final->decision?->meta)->toBe(['transport_errno' => '7'])
        ->and($final->status->allowsFallback())->toBeTrue()
        ->and($clock->now()->format(DATE_ATOM))->toBe('2026-11-20T10:00:03+00:00');
});
