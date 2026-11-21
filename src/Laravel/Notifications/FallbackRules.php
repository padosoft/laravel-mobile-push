<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * The single place that decides whether the push is KNOWN not to have been accepted for the person, so another
 * channel may be considered. Evaluated in this order; the first matching rule wins:
 *
 *  1. no result at all: fallback only when `fallback.when_no_destination` (default true);
 *  2. a topic, condition or broadcast destination: never (unknown audience);
 *  3. any Deferred: not decided yet, no fallback now;
 *  4. any Accepted, Validated or OutcomeUnknown: no fallback (the person may have the push);
 *  5. any Skipped by consent/eligibility or frequency cap: no fallback (the push was suppressed on purpose; a
 *     permission to be pushed to is checked per channel, and a cap must not be bypassed through mail);
 *  6. any Simulated: no fallback (nothing real was attempted);
 *  7. at least one Rejected, Failed or Expired (SendStatus::allowsFallback()): fallback;
 *  8. otherwise (only other Skipped, e.g. safe mode or open circuit): no fallback.
 */
final class FallbackRules
{
    public static function decide(FallbackFacts $facts, bool $whenNoDestination = true): FallbackDecision
    {
        if ($facts->total() === 0) {
            return $whenNoDestination
                ? new FallbackDecision(true, FallbackReason::NoDestination)
                : new FallbackDecision(false, FallbackReason::NoDestinationDisabled);
        }
        if ($facts->unknownAudience()) {
            return new FallbackDecision(false, FallbackReason::UnknownAudience);
        }
        if ($facts->count(SendStatus::Deferred) > 0) {
            return new FallbackDecision(false, FallbackReason::Undecided);
        }
        if ($facts->count(SendStatus::Accepted) + $facts->count(SendStatus::Validated) > 0) {
            return new FallbackDecision(false, FallbackReason::Accepted);
        }
        if ($facts->count(SendStatus::OutcomeUnknown) > 0) {
            return new FallbackDecision(false, FallbackReason::OutcomeUnknown);
        }
        if ($facts->suppressedByPolicy()) {
            return new FallbackDecision(false, FallbackReason::SuppressedByPolicy);
        }
        if ($facts->count(SendStatus::Simulated) > 0) {
            return new FallbackDecision(false, FallbackReason::Simulated);
        }
        $known = 0;
        foreach (SendStatus::cases() as $status) {
            if ($status->allowsFallback()) {
                $known += $facts->count($status);
            }
        }

        return $known > 0
            ? new FallbackDecision(true, FallbackReason::NotAccepted)
            : new FallbackDecision(false, FallbackReason::NothingKnownRefused);
    }
}
