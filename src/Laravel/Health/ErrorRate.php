<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Error-rate arithmetic. A "sample" is an outcome that carries a provider verdict or a delivery failure
 * (accepted, rejected, failed, outcome_unknown); local decisions (skipped, simulated, validated, deferred, expired)
 * are not sampled. An "error" is failed, outcome_unknown, or rejected for a reason that is not plain destination churn
 * (an unregistered or wrong token is normal life, not a provider incident).
 */
final class ErrorRate
{
    private const DESTINATION_REASONS = [
        ReasonCode::DestinationUnregistered, ReasonCode::DestinationRejected, ReasonCode::DestinationMismatch,
        ReasonCode::DestinationRateLimited, ReasonCode::ChannelUnknown,
    ];

    public static function isSample(SendStatus $status): bool
    {
        return in_array($status, [SendStatus::Accepted, SendStatus::Rejected, SendStatus::Failed, SendStatus::OutcomeUnknown], true);
    }

    public static function isError(SendStatus $status, ReasonCode $reason): bool
    {
        return match ($status) {
            SendStatus::Failed, SendStatus::OutcomeUnknown => true,
            SendStatus::Rejected => ! in_array($reason, self::DESTINATION_REASONS, true),
            default => false,
        };
    }

    /** The rate, or null when the sample is below the minimum (too little traffic to say anything). */
    public static function rate(int $sample, int $errors, int $minSample): ?float
    {
        if ($sample <= 0 || $sample < max(1, $minSample)) {
            return null;
        }

        return min(1.0, max(0, $errors) / $sample);
    }

    public static function exceeds(int $sample, int $errors, int $minSample, float $threshold): bool
    {
        $rate = self::rate($sample, $errors, $minSample);

        return $rate !== null && $rate >= $threshold;
    }
}
