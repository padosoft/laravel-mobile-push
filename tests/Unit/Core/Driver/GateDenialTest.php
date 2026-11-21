<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

it('builds a denial from a reason code and an optional hint', function () {
    $skip = GateDenial::skip(ReasonCode::PolicyIneligible, 'No consent.');
    $at = new DateTimeImmutable('2026-11-21T08:00:00Z');
    $defer = GateDenial::defer(ReasonCode::PolicyFrequencyCapped, $at);

    expect($skip->status)->toBe(SendStatus::Skipped)
        ->and($skip->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($skip->reason->hint)->toBe('No consent.')
        ->and($skip->retryAt)->toBeNull()
        ->and($defer->status)->toBe(SendStatus::Deferred)
        ->and($defer->reason->hint)->toBeNull()
        ->and($defer->retryAt)->toBe($at);
});

it('keeps a full reason as given, and replaces only its hint when one is passed', function () {
    $reason = Reason::of(ReasonCode::PolicyIneligible, 'consent:withdrawn', 'Withdrawn in the app.');

    $kept = GateDenial::skip($reason);
    $rehinted = GateDenial::skip($reason, 'Withdrawn by support.');

    expect($kept->reason)->toEqual($reason)
        ->and($rehinted->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($rehinted->reason->rawReason)->toBe('consent:withdrawn')
        ->and($rehinted->reason->hint)->toBe('Withdrawn by support.');
});
