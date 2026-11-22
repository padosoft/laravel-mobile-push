<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Health\ErrorRate;
use Padosoft\MobilePush\Laravel\Health\ErrorRateSample;

it('samples only the outcomes that carry a provider verdict or a delivery failure', function (SendStatus $status, bool $sampled): void {
    expect(ErrorRate::isSample($status))->toBe($sampled);
})->with([
    [SendStatus::Accepted, true],
    [SendStatus::Rejected, true],
    [SendStatus::Failed, true],
    [SendStatus::OutcomeUnknown, true],
    [SendStatus::Deferred, false],
    [SendStatus::Expired, false],
    [SendStatus::Skipped, false],
    [SendStatus::Simulated, false],
    [SendStatus::Validated, false],
]);

it('covers every status in the table above', function (): void {
    expect(count(SendStatus::cases()))->toBe(9);
});

it('counts failed and unknown outcomes as errors whatever the reason, and an accepted push never', function (ReasonCode $reason): void {
    expect(ErrorRate::isError(SendStatus::Failed, $reason))->toBeTrue()
        ->and(ErrorRate::isError(SendStatus::OutcomeUnknown, $reason))->toBeTrue()
        ->and(ErrorRate::isError(SendStatus::Accepted, $reason))->toBeFalse()
        ->and(ErrorRate::isError(SendStatus::Deferred, $reason))->toBeFalse()
        ->and(ErrorRate::isError(SendStatus::Expired, $reason))->toBeFalse()
        ->and(ErrorRate::isError(SendStatus::Skipped, $reason))->toBeFalse()
        ->and(ErrorRate::isError(SendStatus::Simulated, $reason))->toBeFalse()
        ->and(ErrorRate::isError(SendStatus::Validated, $reason))->toBeFalse();
})->with(fn (): array => array_map(static fn (ReasonCode $c): array => [$c], ReasonCode::cases()));

it('does not count a rejection caused by the destination itself, and counts every other rejection', function (ReasonCode $reason): void {
    $churn = [
        ReasonCode::DestinationUnregistered, ReasonCode::DestinationRejected, ReasonCode::DestinationMismatch,
        ReasonCode::DestinationRateLimited, ReasonCode::ChannelUnknown,
    ];

    expect(ErrorRate::isError(SendStatus::Rejected, $reason))->toBe(! in_array($reason, $churn, true));
})->with(fn (): array => array_map(static fn (ReasonCode $c): array => [$c], ReasonCode::cases()));

it('rates errors over samples, only from the minimum sample on, and never above 1', function (int $sample, int $errors, int $min, ?float $rate): void {
    expect(ErrorRate::rate($sample, $errors, $min))->toBe($rate)
        ->and((new ErrorRateSample(['provider' => 'apns', 'connection' => 'x', 'environment' => 'production'], $sample, $errors))->rate($min))->toBe($rate);
})->with([
    'below the minimum' => [49, 49, 50, null],
    'exactly the minimum' => [50, 25, 50, 0.5],
    'above it' => [200, 50, 50, 0.25],
    'no errors' => [100, 0, 50, 0.0],
    'all errors' => [10, 10, 5, 1.0],
    'more errors than samples is capped' => [10, 15, 5, 1.0],
    'negative errors are zero' => [10, -3, 5, 0.0],
    'no samples' => [0, 0, 0, null],
    'no samples, no errors, minimum above' => [0, 3, 5, null],
    'negative samples' => [-4, 1, 0, null],
    'a minimum of zero means one sample' => [1, 1, 0, 1.0],
    'a negative minimum means one sample' => [2, 1, -9, 0.5],
]);

it('says a rate exceeds the threshold at or above it, and never on too little traffic', function (int $sample, int $errors, int $min, float $threshold, bool $exceeds): void {
    expect(ErrorRate::exceeds($sample, $errors, $min, $threshold))->toBe($exceeds);
})->with([
    'on the threshold' => [100, 50, 50, 0.5, true],
    'above' => [100, 51, 50, 0.5, true],
    'just below' => [100, 49, 50, 0.5, false],
    'too little traffic' => [10, 10, 50, 0.5, false],
    'threshold 0 with no errors still trips' => [100, 0, 50, 0.0, true],
]);
