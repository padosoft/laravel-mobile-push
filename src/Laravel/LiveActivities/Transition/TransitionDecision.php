<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

final readonly class TransitionDecision
{
    private function __construct(
        public DecisionKind $kind,
        public Reason $reason,
        /** Send: the Unix second the request carries (always the current second). */
        public ?int $timestamp = null,
        public ?Priority $priority = null,
        /** Send: true when high priority was asked for and the limiter turned it into priority 5. */
        public bool $downgraded = false,
        public ?int $hpWindowStartedAt = null,
        public int $hpCount = 0,
        /** Defer: the first Unix second at which the pending transition may be sent. */
        public ?int $dueAt = null,
        /** Skip: the row is past its local expiry and must be marked `expired` now (in the same compare-and-set). */
        public bool $expire = false,
    ) {}

    public static function send(int $timestamp, Priority $priority, bool $downgraded, ?int $hpWindowStartedAt, int $hpCount): self
    {
        return new self(DecisionKind::Send, Reason::none(), $timestamp, $priority, $downgraded, $hpWindowStartedAt, $hpCount);
    }

    public static function defer(int $dueAt, string $hint): self
    {
        return new self(DecisionKind::Defer, Reason::of(ReasonCode::PolicyFrequencyCapped, null, $hint), dueAt: $dueAt);
    }

    public static function skip(ReasonCode $code, string $hint): self
    {
        return new self(DecisionKind::Skip, Reason::of($code, null, $hint));
    }

    public static function expired(): self
    {
        return new self(DecisionKind::Skip, Reason::of(
            ReasonCode::PolicyIneligible, null,
            'The local expiry (local_expires_at) of the activity has passed: the server stopped sending.',
        ), expire: true);
    }
}
