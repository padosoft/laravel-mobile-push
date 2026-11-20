<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

/**
 * The public outcome of one destination (see docs/contracts/outcomes.md).
 *
 * Accepted means "accepted by the provider", never "delivered" or "seen".
 */
enum SendStatus: string
{
    case Accepted = 'accepted';
    /** The provider definitively refused this request: retrying it unchanged cannot succeed. */
    case Rejected = 'rejected';
    /** Known NOT accepted, and no attempt is left: the provider was unavailable or unreachable until the retry budget ended. */
    case Failed = 'failed';
    /** A new attempt is planned (SendResult::$nextAttemptAt is set). */
    case Deferred = 'deferred';
    /** It is not known whether the provider processed the request. Never treated as failed-without-sending. */
    case OutcomeUnknown = 'outcome_unknown';
    /** The logical expiry passed before an attempt could be made: nothing was sent. */
    case Expired = 'expired';
    /** A local rule prevented the attempt (consent, frequency cap, safe mode, open circuit, binding mismatch). */
    case Skipped = 'skipped';
    /** Simulation mode: no network was touched. */
    case Simulated = 'simulated';
    /** FCM validate_only: the provider validated the request without sending it. */
    case Validated = 'validated';

    /** No further attempt will be made for this destination and effect. */
    public function isTerminal(): bool
    {
        return $this !== self::Deferred;
    }

    /** The provider took charge of the message. */
    public function isAccepted(): bool
    {
        return $this === self::Accepted;
    }

    /** Whether a fallback channel may even be considered: only when the push is KNOWN not to have been accepted. */
    public function allowsFallback(): bool
    {
        return in_array($this, [self::Rejected, self::Failed, self::Expired], true);
    }
}
