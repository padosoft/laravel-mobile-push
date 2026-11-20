<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;

/**
 * The state machine of a Live Activity transition, as a pure function of one row version. Evaluated in order:
 *
 *  1. State: `ended` / `expired` accept nothing; `ending` accepts only the end (a resend); `requested` has no
 *     update token yet; an undeliverable row (token quarantined/invalidated, channel not active) sends nothing.
 *  2. Ticket order: a transition dispatched earlier than one already applied (or pending) is stale and skipped,
 *     so an old queued update can never overwrite a newer state or reopen an end.
 *  3. Time: the request carries the CURRENT Unix second. If a request of this row already carried this second
 *     (or a later one), sending now would be discarded by Apple and inventing the next second is forbidden, so the
 *     transition is deferred to the next second as the row's single pending transition (latest state wins).
 *  4. Priority: high-priority updates pass the hourly limiter; beyond it they go out at priority 5.
 */
final readonly class TransitionRules
{
    public function __construct(private HourlyLimiter $limiter) {}

    public function decide(RowSnapshot $row, TransitionCommand $command, int $now): TransitionDecision
    {
        $isEnd = $command->event === LiveActivityEvent::End;

        $refusal = match (true) {
            $command->event === LiveActivityEvent::Start => [ReasonCode::RequestInvalid, 'A start is not a transition of an existing activity.'],
            $row->state === ActivityState::Ended => [ReasonCode::PolicyIneligible, 'The activity has ended: nothing is sent after an end.'],
            $row->state === ActivityState::Expired => [ReasonCode::PolicyIneligible, 'The activity is past its local expiry: the server stopped sending.'],
            $row->state === ActivityState::Ending && ! $isEnd => [ReasonCode::PolicyIneligible, 'The activity is ending: an update can no longer be sent.'],
            $row->state === ActivityState::Requested => [ReasonCode::PolicyIneligible, 'The app has not reported the update token of this activity yet.'],
            ! $row->deliverable => [ReasonCode::PolicyIneligible, 'No usable destination: the update token is quarantined or invalidated, or the channel is not active.'],
            default => null,
        };

        if ($refusal !== null) {
            return TransitionDecision::skip($refusal[0], $refusal[1]);
        }

        if (! $this->inOrder($row, $command)) {
            return TransitionDecision::skip(ReasonCode::Cancelled, 'Superseded: a transition dispatched later has already been applied or is pending.');
        }

        if ($row->lastTimestamp !== null && $now <= $row->lastTimestamp) {
            return TransitionDecision::defer(
                $row->lastTimestamp + 1,
                'Coalesced: a request already carried this second; the latest state goes out in the next second.',
            );
        }

        $windowStartedAt = $row->hpWindowStartedAt;
        $count = $row->hpCount;
        $priority = $command->priority;
        $downgraded = false;

        if ($command->event === LiveActivityEvent::Update && $priority === Priority::High) {
            $verdict = $this->limiter->admit($windowStartedAt, $count, $now);
            $windowStartedAt = $verdict->windowStartedAt;
            $count = $verdict->count;

            if (! $verdict->high) {
                $priority = Priority::Normal;
                $downgraded = true;
            }
        }

        return TransitionDecision::send($now, $priority, $downgraded, $windowStartedAt, $count);
    }

    private function inOrder(RowSnapshot $row, TransitionCommand $command): bool
    {
        if ($command->fromPending) {
            return $row->pendingSeq === $command->seq && $command->seq >= $row->appliedSeq;
        }

        return $command->seq > $row->appliedSeq && ($row->pendingSeq === null || $command->seq > $row->pendingSeq);
    }
}
