<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Notifications\FallbackFacts;
use Padosoft\MobilePush\Laravel\Notifications\FallbackReason;
use Padosoft\MobilePush\Laravel\Notifications\FallbackRules;

it('decides the fallback from the combination of results', function (array $counts, bool $expected, FallbackReason $reason, array $flags = [], bool $whenNoDestination = true) {
    $facts = FallbackFacts::of($counts, ...$flags);

    $decision = FallbackRules::decide($facts, $whenNoDestination);

    expect($decision->fallback)->toBe($expected)
        ->and($decision->reason)->toBe($reason);
})->with([
    'no destination, allowed' => [[], true, FallbackReason::NoDestination],
    'no destination, switched off' => [[], false, FallbackReason::NoDestinationDisabled, [], false],
    'one rejected' => [['rejected' => 1], true, FallbackReason::NotAccepted],
    'one failed' => [['failed' => 1], true, FallbackReason::NotAccepted],
    'one expired' => [['expired' => 1], true, FallbackReason::NotAccepted],
    'rejected + failed' => [['rejected' => 1, 'failed' => 1], true, FallbackReason::NotAccepted],
    'rejected + accepted' => [['rejected' => 1, 'accepted' => 1], false, FallbackReason::Accepted],
    'rejected + validated' => [['rejected' => 1, 'validated' => 1], false, FallbackReason::Accepted],
    'rejected + unknown' => [['rejected' => 1, 'outcome_unknown' => 1], false, FallbackReason::OutcomeUnknown],
    'only unknown' => [['outcome_unknown' => 1], false, FallbackReason::OutcomeUnknown],
    'rejected + deferred' => [['rejected' => 1, 'deferred' => 1], false, FallbackReason::Undecided],
    'only deferred' => [['deferred' => 1], false, FallbackReason::Undecided],
    'rejected + opted out or capped' => [['rejected' => 1, 'skipped' => 1], false, FallbackReason::SuppressedByPolicy, ['suppressedByPolicy' => true]],
    'only opted out or capped' => [['skipped' => 1], false, FallbackReason::SuppressedByPolicy, ['suppressedByPolicy' => true]],
    'only skipped for safe mode' => [['skipped' => 1], false, FallbackReason::NothingKnownRefused],
    'rejected + skipped for an open circuit' => [['rejected' => 1, 'skipped' => 1], true, FallbackReason::NotAccepted],
    'simulated' => [['simulated' => 2], false, FallbackReason::Simulated],
    'rejected + simulated' => [['rejected' => 1, 'simulated' => 1], false, FallbackReason::Simulated],
    'a topic was rejected' => [['rejected' => 1], false, FallbackReason::UnknownAudience, ['unknownAudience' => true]],
    'no destination even with unknown audience flag unused' => [[], true, FallbackReason::NoDestination, ['unknownAudience' => true]],
]);

it('reads the facts from real results: consent and cap skips, audience kind, counters', function () {
    $device = ApnsDeviceToken::of(str_repeat('ab', 32));
    $facts = FallbackFacts::fromResults([
        new SendResult($device, SendStatus::Rejected, Reason::of(ReasonCode::DestinationUnregistered)),
        new SendResult($device, SendStatus::Skipped, Reason::of(ReasonCode::PolicyFrequencyCapped)),
    ]);
    $topic = FallbackFacts::fromResults([new SendResult(FcmTopic::of('news'), SendStatus::Rejected, Reason::of(ReasonCode::RequestInvalid))]);
    $circuit = FallbackFacts::fromResults([new SendResult($device, SendStatus::Skipped, Reason::of(ReasonCode::CircuitOpen))]);

    expect($facts->suppressedByPolicy())->toBeTrue()
        ->and($facts->counts()['rejected'])->toBe(1)
        ->and($facts->total())->toBe(2)
        ->and(FallbackRules::decide($facts)->fallback)->toBeFalse()
        ->and($topic->unknownAudience())->toBeTrue()
        ->and(FallbackRules::decide($topic)->reason)->toBe(FallbackReason::UnknownAudience)
        ->and($circuit->suppressedByPolicy())->toBeFalse();
});
