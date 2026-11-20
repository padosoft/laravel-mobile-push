<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode as Code;
use Padosoft\MobilePush\Core\Outcome\SendStatus as S;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPlanner;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;

function retryNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-20T10:00:00Z');
}

function retryPlanner(array $policy = []): RetryPlanner
{
    return new RetryPlanner(new RetryPolicy(...($policy + ['jitter' => 0.0])));
}

function retryUnavailable(?int $minDelay = null): Decision
{
    return new Decision(S::Failed, Reason::of(Code::ProviderUnavailable, 'ServiceUnavailable', 'down'), retryable: true, minDelaySeconds: $minDelay);
}

function retryLostResponse(): Decision
{
    return new Decision(S::OutcomeUnknown, Reason::of(Code::TransportUnknown), retryable: true);
}

it('keeps terminal classifications as they are', function (Decision $decision): void {
    $planner = retryPlanner();
    $plan = $planner->plan($decision, RetryBudget::start($planner->policy(), retryNow()), true, retryNow());

    expect($plan->status)->toBe($decision->status)
        ->and($plan->reason)->toBe($decision->reason)
        ->and($plan->nextAttemptAt)->toBeNull()
        ->and($plan->isTerminal())->toBeTrue()
        ->and($plan->budget->attempts)->toBe(1);
})->with([
    'accepted' => [new Decision(S::Accepted, Reason::none())],
    'validated' => [new Decision(S::Validated, Reason::of(Code::Validated))],
    'rejected' => [new Decision(S::Rejected, Reason::of(Code::DestinationUnregistered, 'Unregistered'), destinationAction: DestinationAction::Invalidate)],
    'rejected even if marked retryable' => [new Decision(S::Rejected, Reason::of(Code::PayloadInvalid), retryable: true)],
    'non-retryable failure' => [new Decision(S::Failed, Reason::of(Code::AuthTokenInvalid), credentialAction: CredentialAction::ReportAuthFailure)],
]);

it('defers a retryable failure with backoff and never earlier than the provider minimum', function (): void {
    $planner = retryPlanner(['backoffBaseMs' => 1000]);
    $budget = RetryBudget::start($planner->policy(), retryNow());

    $first = $planner->plan(retryUnavailable(), $budget, false, retryNow());
    $withMin = $planner->plan(retryUnavailable(900), $budget, false, retryNow());

    expect($first->status)->toBe(S::Deferred)
        ->and($first->delayMs)->toBe(1000)
        ->and($first->nextAttemptAt?->format('H:i:s.v'))->toBe('10:00:01.000')
        ->and($first->reason->code)->toBe(Code::ProviderUnavailable)
        ->and($first->inline)->toBeFalse()
        ->and($withMin->nextAttemptAt?->format('H:i:s'))->toBe('10:15:00');
});

it('marks short waits as inline according to the policy threshold', function (): void {
    $planner = retryPlanner(['backoffBaseMs' => 200, 'inlineThresholdMs' => 250]);
    $budget = RetryBudget::start($planner->policy(), retryNow());

    expect($planner->plan(retryUnavailable(), $budget, false, retryNow())->inline)->toBeTrue()
        ->and($planner->plan(retryUnavailable(1), $budget, false, retryNow())->inline)->toBeFalse();
});

it('ends with RetryBudgetExhausted when attempts run out, keeping the cause in rawReason', function (): void {
    $planner = retryPlanner(['maxAttempts' => 2]);
    $budget = RetryBudget::start($planner->policy(), retryNow());

    $plan = $planner->plan(retryUnavailable(), $budget, false, retryNow());
    $plan = $planner->plan(retryUnavailable(), $plan->budget, false, retryNow());

    expect($plan->status)->toBe(S::Failed)
        ->and($plan->reason->code)->toBe(Code::RetryBudgetExhausted)
        ->and($plan->reason->rawReason)->toBe('ServiceUnavailable')
        ->and($plan->cause()?->code)->toBe(Code::ProviderUnavailable)
        ->and($plan->budget->attempts)->toBe(2);
});

it('distinguishes the two deadlines: logical expiry gives Expired, maxElapsed gives Failed', function (?string $expiry, S $status, Code $code): void {
    $planner = retryPlanner(['maxElapsedSeconds' => 600]);
    $budget = RetryBudget::start($planner->policy(), retryNow(), $expiry === null ? null : new DateTimeImmutable($expiry));

    $plan = $planner->plan(retryUnavailable(900), $budget, false, retryNow());

    expect($plan->status)->toBe($status)
        ->and($plan->reason->code)->toBe($code)
        ->and($plan->reason->rawReason)->toBe('ServiceUnavailable')
        ->and($plan->nextAttemptAt)->toBeNull();
})->with([
    'expiry before maxElapsed' => ['2026-11-20T10:05:00Z', S::Expired, Code::Expired],
    'maxElapsed before expiry' => ['2026-11-20T12:00:00Z', S::Failed, Code::RetryBudgetExhausted],
    'no expiry' => [null, S::Failed, Code::RetryBudgetExhausted],
]);

it('applies the unknown-outcome policy', function (UnknownOutcomeRetry $policy, bool $replaceable, S $status): void {
    $planner = retryPlanner(['unknownOutcome' => $policy]);

    $plan = $planner->plan(retryLostResponse(), RetryBudget::start($planner->policy(), retryNow()), $replaceable, retryNow());

    expect($plan->status)->toBe($status)
        ->and($plan->budget->unknownOutcomeSeen)->toBeTrue();
})->with([
    'never, replaceable' => [UnknownOutcomeRetry::Never, true, S::OutcomeUnknown],
    'replaceable, alert' => [UnknownOutcomeRetry::Replaceable, false, S::OutcomeUnknown],
    'replaceable, update' => [UnknownOutcomeRetry::Replaceable, true, S::Deferred],
    'always, alert' => [UnknownOutcomeRetry::Always, false, S::Deferred],
]);

it('never turns an unknown outcome into Failed or Expired, even when later attempts fail', function (Decision $last, ?string $expiry): void {
    $planner = retryPlanner(['maxAttempts' => 2]);
    $budget = RetryBudget::start($planner->policy(), retryNow(), $expiry === null ? null : new DateTimeImmutable($expiry));

    $plan = $planner->plan(retryLostResponse(), $budget, true, retryNow());
    $plan = $planner->plan($last, $plan->budget, true, retryNow()->modify('+1 second'));

    expect($plan->status)->toBe(S::OutcomeUnknown)
        ->and($plan->status->allowsFallback())->toBeFalse();
})->with([
    'unknown again, attempts exhausted' => [retryLostResponse(), null],
    'then 503, attempts exhausted' => [retryUnavailable(), null],
    'then 503, past expiry' => [retryUnavailable(900), '2026-11-20T10:05:00Z'],
    'then a non-retryable failure' => [new Decision(S::Failed, Reason::of(Code::AuthTokenInvalid)), null],
]);

it('stops before the network once the logical expiry passed', function (): void {
    $planner = retryPlanner();
    $budget = RetryBudget::start($planner->policy(), retryNow(), new DateTimeImmutable('2026-11-20T10:10:00Z'));

    expect($planner->beforeAttempt($budget, new DateTimeImmutable('2026-11-20T10:09:00Z')))->toBeNull();

    $stop = $planner->beforeAttempt($budget, new DateTimeImmutable('2026-11-20T10:10:00Z'), Reason::of(Code::ProviderUnavailable, 'ServiceUnavailable'));
    $tainted = $planner->beforeAttempt($budget->withUnknownOutcome(), new DateTimeImmutable('2026-11-20T10:11:00Z'));

    expect($stop?->status)->toBe(S::Expired)
        ->and($stop?->reason->code)->toBe(Code::Expired)
        ->and($stop?->reason->rawReason)->toBe('ServiceUnavailable')
        ->and($tainted?->status)->toBe(S::OutcomeUnknown);
});

it('grants one immediate retry after a token refresh, then falls back to backoff', function (): void {
    $planner = retryPlanner(['backoffBaseMs' => 1000]);
    $expired = new Decision(S::Failed, Reason::of(Code::AuthTokenExpired, 'ExpiredProviderToken'), retryable: true, credentialAction: CredentialAction::RefreshToken);

    $first = $planner->plan($expired, RetryBudget::start($planner->policy(), retryNow()), false, retryNow());
    $second = $planner->plan($expired, $first->budget, false, retryNow());

    expect($first->status)->toBe(S::Deferred)
        ->and($first->delayMs)->toBe(0)
        ->and($first->inline)->toBeTrue()
        ->and($first->budget->tokenRefreshes)->toBe(1)
        ->and($second->delayMs)->toBe(2000)
        ->and($second->budget->attempts)->toBe(2);
});

it('reports whether a failover attempt still fits the budget', function (): void {
    $planner = retryPlanner(['maxAttempts' => 2, 'maxElapsedSeconds' => 60]);
    $budget = RetryBudget::start($planner->policy(), retryNow());

    expect($planner->hasAttemptLeft($budget->withAttempt(), retryNow()))->toBeTrue()
        ->and($planner->hasAttemptLeft($budget->withAttempt()->withAttempt(), retryNow()))->toBeFalse()
        ->and($planner->hasAttemptLeft($budget->withAttempt(), retryNow()->modify('+60 seconds')))->toBeFalse();
});
