<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Stored state of one delivery row (one logical destination of one effect).
 *
 * Non-terminal: Pending (never attempted, due at next_attempt_at), Deferred (another attempt planned at
 * next_attempt_at), Leased (a worker holds it, no request has left yet), Sending (the worker said the request is
 * about to leave: from here an expired lease means the outcome is UNKNOWN, never "not sent").
 * Terminal: one per public SendStatus except Deferred, plus Cancelled (tombstone written by cancel/forget).
 */
enum DeliveryState: string
{
    case Pending = 'pending';
    case Deferred = 'deferred';
    case Leased = 'leased';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case OutcomeUnknown = 'outcome_unknown';
    case Expired = 'expired';
    case Skipped = 'skipped';
    case Simulated = 'simulated';
    case Validated = 'validated';
    case Cancelled = 'cancelled';

    public static function fromStatus(SendStatus $status): self
    {
        return match ($status) {
            SendStatus::Accepted => self::Accepted,
            SendStatus::Rejected => self::Rejected,
            SendStatus::Failed => self::Failed,
            SendStatus::Deferred => self::Deferred,
            SendStatus::OutcomeUnknown => self::OutcomeUnknown,
            SendStatus::Expired => self::Expired,
            SendStatus::Skipped => self::Skipped,
            SendStatus::Simulated => self::Simulated,
            SendStatus::Validated => self::Validated,
        };
    }

    /**
     * The public status of a row read back from the ledger. Rows still waiting or in flight read as Deferred
     * (another attempt is coming); a cancelled row reads as Skipped (with ReasonCode::Cancelled).
     */
    public function toStatus(): SendStatus
    {
        return match ($this) {
            self::Pending, self::Deferred, self::Leased, self::Sending => SendStatus::Deferred,
            self::Accepted => SendStatus::Accepted,
            self::Rejected => SendStatus::Rejected,
            self::Failed => SendStatus::Failed,
            self::OutcomeUnknown => SendStatus::OutcomeUnknown,
            self::Expired => SendStatus::Expired,
            self::Skipped, self::Cancelled => SendStatus::Skipped,
            self::Simulated => SendStatus::Simulated,
            self::Validated => SendStatus::Validated,
        };
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Deferred, self::Leased, self::Sending], true);
    }

    /** Waiting for a worker (due once next_attempt_at has passed). */
    public function isWaiting(): bool
    {
        return $this === self::Pending || $this === self::Deferred;
    }

    /** Held by a worker under a lease. */
    public function isInFlight(): bool
    {
        return $this === self::Leased || $this === self::Sending;
    }

    /** @return list<string> */
    public static function values(self ...$states): array
    {
        return array_values(array_map(static fn (self $state): string => $state->value, $states));
    }
}
