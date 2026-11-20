<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryPlanner;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Outcome\OutcomeKit as K;
use Padosoft\MobilePush\Tests\Support\FakeClock;

it('wrong environment: BadDeviceToken quarantines the registration, is never retried and never invalidates', function (): void {
    $transport = (new FakeTransport)->defaultTo(new Response(400, ['apns-id' => K::APNS_ID], '{"reason":"BadDeviceToken"}'));
    // Even the most permissive policy must not retry a rejection.
    $planner = new RetryPlanner(new RetryPolicy(maxAttempts: 10, unknownOutcome: UnknownOutcomeRetry::Always));

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), $planner, new FakeClock, K::apnsRequest(), replaceable: true);
    $final = end($plans);

    expect($transport->requests())->toHaveCount(1)
        ->and($final->status)->toBe(SendStatus::Rejected)
        ->and($final->reason->code)->toBe(ReasonCode::DestinationRejected)
        ->and($final->decision?->destinationAction)->toBe(DestinationAction::Quarantine)
        ->and($final->decision?->invalidatedAtMs)->toBeNull()
        ->and($final->reason->hint)->toContain('environment');
});

it('uninstalled app: 410 Unregistered invalidates with the exact millisecond timestamp', function (): void {
    $transport = (new FakeTransport)->push(new Response(410, ['apns-id' => K::APNS_ID], '{"reason":"Unregistered","timestamp":1763560800123}'));

    $plans = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(), new RetryPlanner, new FakeClock, K::apnsRequest());
    $final = end($plans);

    expect($transport->requests())->toHaveCount(1)
        ->and($final->status)->toBe(SendStatus::Rejected)
        ->and($final->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($final->decision?->destinationAction)->toBe(DestinationAction::Invalidate)
        ->and($final->decision?->invalidatedAtMs)->toBe(1763560800123)
        ->and((new DateTimeImmutable('@'.intdiv(1763560800123, 1000)))->format('Y-m-d'))->toBe('2025-11-19');
});

it('the same 410 on a broadcast channel touches no registration', function (): void {
    $transport = (new FakeTransport)->push(new Response(410, [], '{"reason":"Unregistered","timestamp":1763560800123}'));

    $final = K::drive($transport, new ApnsOutcomeClassifier, K::apnsContext(K::channel()), new RetryPlanner, new FakeClock, K::apnsRequest());

    expect(end($final)->decision?->destinationAction)->toBe(DestinationAction::None)
        ->and(end($final)->decision?->invalidatedAtMs)->toBeNull();
});
