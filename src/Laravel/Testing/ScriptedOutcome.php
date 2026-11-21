<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * What the intent fake answers for one destination. Build one with the factories, or pass a plain SendStatus
 * wherever an outcome is accepted.
 *
 * The answer goes through the real classification, retry planning and reporting, so `deferred()` produces a real
 * Deferred result with a next attempt time, and a rejection with DestinationUnregistered invalidates a registry
 * device exactly as a real APNs 410 would.
 */
final readonly class ScriptedOutcome
{
    private function __construct(
        public SendStatus $status,
        public ReasonCode $reason,
        public int $delaySeconds = 0,
    ) {}

    public static function accepted(): self
    {
        return new self(SendStatus::Accepted, ReasonCode::None);
    }

    public static function validated(): self
    {
        return new self(SendStatus::Validated, ReasonCode::Validated);
    }

    /** The provider refused the push (a final answer for this destination). */
    public static function rejected(ReasonCode $reason = ReasonCode::RequestInvalid): self
    {
        return new self(SendStatus::Rejected, $reason);
    }

    /** Known not accepted, no attempt left. */
    public static function failed(ReasonCode $reason = ReasonCode::ProviderUnavailable): self
    {
        return new self(SendStatus::Failed, $reason);
    }

    /** The request may or may not have been processed (connection lost after it was written). */
    public static function unknown(): self
    {
        return new self(SendStatus::OutcomeUnknown, ReasonCode::TransportUnknown);
    }

    /** Not accepted now, to be tried again later (the report carries the next attempt time). */
    public static function deferred(int $afterSeconds = 60, ReasonCode $reason = ReasonCode::ProviderUnavailable): self
    {
        return new self(SendStatus::Deferred, $reason, max(1, $afterSeconds));
    }

    /**
     * @throws \InvalidArgumentException for statuses the fake cannot answer with (Skipped, Simulated, Expired are
     *                                   decided before the provider, by gates, the execution mode and the expiry)
     */
    public static function fromStatus(SendStatus $status): self
    {
        return match ($status) {
            SendStatus::Accepted => self::accepted(),
            SendStatus::Validated => self::validated(),
            SendStatus::Rejected => self::rejected(),
            SendStatus::Failed => self::failed(),
            SendStatus::OutcomeUnknown => self::unknown(),
            SendStatus::Deferred => self::deferred(),
            SendStatus::Skipped, SendStatus::Simulated, SendStatus::Expired => throw new \InvalidArgumentException(sprintf(
                'The fake cannot answer with "%s": that status is decided before the provider is asked (gates, execution mode, expiry). Script Accepted, Validated, Rejected, Failed, Deferred or OutcomeUnknown.',
                $status->value,
            )),
        };
    }

    public function toDecision(?string $providerMessageId): Decision
    {
        $action = match ($this->reason) {
            ReasonCode::DestinationUnregistered => DestinationAction::Invalidate,
            ReasonCode::DestinationRejected, ReasonCode::DestinationMismatch => DestinationAction::Quarantine,
            default => DestinationAction::None,
        };

        return match ($this->status) {
            SendStatus::Deferred => new Decision(SendStatus::Failed, Reason::of($this->reason), true, $this->delaySeconds),
            SendStatus::Accepted => new Decision(SendStatus::Accepted, Reason::none(), httpStatus: 200, providerMessageId: $providerMessageId),
            SendStatus::Validated => new Decision(SendStatus::Validated, Reason::of(ReasonCode::Validated), httpStatus: 200),
            SendStatus::OutcomeUnknown => new Decision(SendStatus::OutcomeUnknown, Reason::of(ReasonCode::TransportUnknown)),
            default => new Decision($this->status, Reason::of($this->reason), false, null, $action),
        };
    }
}
