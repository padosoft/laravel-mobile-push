<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console\Support;

use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionOutcome;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionResult;

/** How the result of an update, an end or a publication is told to the operator (never a token, never a body). */
final class TransitionReport
{
    /** @return array<string, mixed> */
    public static function toArray(TransitionResult $result): array
    {
        return [
            'outcome' => $result->outcome->value,
            'status' => $result->status->value,
            'reason' => $result->reason->code->value,
            'raw_reason' => $result->reason->rawReason,
            'hint' => self::explanation($result),
            'sequence' => $result->seq,
            'timestamp' => $result->timestamp,
            'timestamp_iso' => $result->timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $result->timestamp),
            'priority' => $result->priority?->value,
            'priority_downgraded' => $result->downgraded,
            'next_attempt_at' => $result->nextAttemptAt?->format('Y-m-d\TH:i:sP'),
            'broadcast' => $result->broadcast,
            'publications' => $result->broadcast ? $result->publications() : null,
        ];
    }

    /** @return list<string> */
    public static function lines(TransitionResult $result): array
    {
        $lines = [];
        $lines[] = 'Outcome: '.$result->outcome->value.' ('.$result->status->value.')';
        $lines[] = self::explanation($result);

        if ($result->timestamp !== null) {
            $lines[] = 'Timestamp sent: '.$result->timestamp.' ('.gmdate('Y-m-d\TH:i:s\Z', $result->timestamp).')';
        }
        if ($result->priority !== null) {
            $lines[] = $result->downgraded
                ? 'Priority: '.$result->priority->value.' (downgraded from 10 by the hourly high-priority limiter: the update still goes out, at the device\'s discretion)'
                : 'Priority: '.$result->priority->value;
        }
        if ($result->nextAttemptAt !== null) {
            $lines[] = 'Next attempt: '.$result->nextAttemptAt->format('Y-m-d\TH:i:sP');
        }

        return $lines;
    }

    public static function exitCode(TransitionResult $result): int
    {
        return match (true) {
            $result->outcome === TransitionOutcome::Skipped => 1,
            $result->outcome === TransitionOutcome::Deferred && $result->result === null => 0,
            default => in_array($result->status, [SendStatus::Accepted, SendStatus::Simulated, SendStatus::Validated, SendStatus::Deferred], true) ? 0 : 1,
        };
    }

    private static function explanation(TransitionResult $result): string
    {
        return match (true) {
            $result->outcome === TransitionOutcome::Deferred && $result->result === null => 'Deferred: another update was already sent in this second. Nothing was sent now; the latest state goes out from the next second (run mobile-push:live-activity:flush, or let the scheduler do it).',
            $result->outcome === TransitionOutcome::Skipped => 'Skipped: nothing was sent'.($result->reason->hint === null ? '.' : ' because '.lcfirst(rtrim($result->reason->hint, '.')).'.'),
            $result->status === SendStatus::Accepted && $result->broadcast => 'APNs accepted the publication. This is 1 publication: APNs does not say how many devices received it, and the server never learns.',
            $result->status === SendStatus::Accepted => 'APNs accepted the request. Accepted is not delivered: the device may be offline or may drop it.',
            $result->status === SendStatus::OutcomeUnknown => 'The outcome is unknown: the request may or may not have reached APNs. A later update replaces it; nothing is retried blindly.',
            $result->status === SendStatus::Deferred => 'APNs did not answer definitively; a retry is planned.',
            $result->status === SendStatus::Simulated => 'Simulated: no request was sent.',
            default => 'APNs result: '.$result->status->value.' ('.$result->reason->code->value.($result->reason->rawReason === null ? '' : ', '.$result->reason->rawReason).').',
        };
    }
}
