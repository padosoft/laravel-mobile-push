<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Documented exit codes of `mobile-push:send` (see the command's help):
 *
 *  0  everything accepted (or validated by FCM `validate_only`)
 *  1  partial results: at least one destination was rejected, failed, skipped, deferred or expired
 *  2  invalid input or configuration: nothing was sent
 *  3  simulated: nothing was sent (the effective mode is "simulate")
 *  4  at least one unknown outcome (the request may or may not have been processed)
 *
 * An unknown outcome wins over everything else: it is the one a caller must not retry blindly.
 */
final class SendExit
{
    public const OK = 0;

    public const PARTIAL = 1;

    public const INVALID = 2;

    public const SIMULATED = 3;

    public const UNKNOWN = 4;

    /**
     * @param  array<string, int>  $counts  SendStatus value => number of destinations
     */
    public static function fromCounts(array $counts): int
    {
        $count = static fn (SendStatus $status): int => $counts[$status->value] ?? 0;

        if (array_sum($counts) === 0) {
            return self::INVALID;
        }
        if ($count(SendStatus::OutcomeUnknown) > 0) {
            return self::UNKNOWN;
        }
        $notAccepted = 0;
        foreach ([SendStatus::Rejected, SendStatus::Failed, SendStatus::Deferred, SendStatus::Expired, SendStatus::Skipped] as $status) {
            $notAccepted += $count($status);
        }
        if ($notAccepted > 0) {
            return self::PARTIAL;
        }

        return $count(SendStatus::Simulated) > 0 ? self::SIMULATED : self::OK;
    }

    /** @return array<int, string> */
    public static function descriptions(): array
    {
        return [
            self::OK => 'everything accepted (or validated)',
            self::PARTIAL => 'partial: some destinations were rejected, failed, skipped, deferred or expired',
            self::INVALID => 'invalid input or configuration: nothing was sent',
            self::SIMULATED => 'simulated: nothing was sent',
            self::UNKNOWN => 'at least one unknown outcome',
        ];
    }
}
