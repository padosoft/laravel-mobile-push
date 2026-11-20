<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * The ONLY component that decides whether a destination gets another attempt. Pure planning: no sleep, no I/O,
 * the current time is passed in.
 *
 * Usage, per destination and effect:
 *
 *     $budget = RetryBudget::start($policy, $clock->now(), $message->expiresAt);
 *     loop:
 *         if (($stop = $planner->beforeAttempt($budget, $clock->now())) !== null) { report $stop; break; }
 *         $decision = $classifier->classify($exchange, $context);          // one attempt
 *         $plan = $planner->plan($decision, $budget, $replaceable, $clock->now());
 *         $budget = $plan->budget;                                          // persist it
 *         if ($plan->isTerminal()) { report $plan; break; }
 *         // Deferred: wait inline when $plan->inline, otherwise re-queue for $plan->nextAttemptAt
 *
 * Reason semantics of the plans:
 *  - Deferred: the reason is the cause of the retry, exactly as classified.
 *  - Terminal as classified (Accepted, Validated, Rejected, non-retryable Failed, unknown outcome not retried):
 *    the classified reason, unchanged.
 *  - Gave up on a retryable condition because attempts ran out or the next attempt would fall at/after
 *    first attempt + maxElapsed: ReasonCode::RetryBudgetExhausted.
 *  - Gave up because the next attempt would fall at/after the message's logical expiry, or the expiry passed
 *    before a planned attempt: ReasonCode::Expired.
 *    In both "gave up" cases the cause is not lost: Reason::$rawReason holds the cause's raw provider string
 *    (e.g. "ServiceUnavailable") or, when there is none, the cause's ReasonCode value (e.g. "transport_not_sent"),
 *    and RetryPlan::$decision keeps the full classification.
 *  - Status of a "gave up" plan: Failed (RetryBudgetExhausted) or Expired (Expired); but if ANY attempt of this
 *    budget ended with an unknown outcome the provider may already have accepted the message, so the status is
 *    OutcomeUnknown instead (never Failed or Expired: no fallback, no "not sent" claim). The same holds for a
 *    non-retryable Failed after an unknown attempt. A Rejected answer stays Rejected: it is about the request
 *    itself (an unchanged copy would have been refused too).
 */
final readonly class RetryPlanner
{
    private Backoff $backoff;

    public function __construct(private RetryPolicy $policy = new RetryPolicy, ?Backoff $backoff = null)
    {
        $this->backoff = $backoff ?? new Backoff($policy);
    }

    public function policy(): RetryPolicy
    {
        return $this->policy;
    }

    /**
     * Call before EVERY attempt, the first one included. Returns a terminal plan when the attempt must not touch
     * the network (logical expiry passed), null when the attempt may proceed.
     *
     * Only the logical expiry blocks here: an attempt planned before the maxElapsed deadline is still made even if
     * the worker picks it up late, because it was planned as a valid attempt.
     *
     * @param  Reason|null  $lastCause  the reason of the previous attempt, if known (kept in rawReason)
     */
    public function beforeAttempt(RetryBudget $budget, \DateTimeImmutable $now, ?Reason $lastCause = null): ?RetryPlan
    {
        if (! $budget->isExpired($now)) {
            return null;
        }

        return new RetryPlan(
            $budget->unknownOutcomeSeen ? SendStatus::OutcomeUnknown : SendStatus::Expired,
            Reason::of(
                ReasonCode::Expired,
                $lastCause === null ? null : self::rawOf($lastCause),
                'The message expired before this attempt; nothing more was sent. Lengthen the expiry or reduce delays if it should have been delivered.',
            ),
            $budget,
        );
    }

    /**
     * Whether one more attempt could still be made now (used e.g. by credential failover before it sends again
     * with another credential: the failover attempt counts in the same budget).
     */
    public function hasAttemptLeft(RetryBudget $budget, \DateTimeImmutable $now): bool
    {
        if ($budget->singleAttempt) {
            return $budget->attempts < 1;
        }

        return $budget->attempts < $this->policy->maxAttempts && $now < $budget->deadline();
    }

    /**
     * Plan what follows ONE classified attempt.
     *
     * @param  RetryBudget  $budget  the budget as it was before this attempt (the attempt is recorded here)
     * @param  bool  $replaceable  a later copy of this message replaces the earlier one on the device
     */
    public function plan(Decision $decision, RetryBudget $budget, bool $replaceable, \DateTimeImmutable $now): RetryPlan
    {
        $budget = $budget->withAttempt();

        switch ($decision->status) {
            case SendStatus::OutcomeUnknown:
                $budget = $budget->withUnknownOutcome();
                if (! $decision->retryable || ! $this->policy->unknownOutcome->allows($replaceable)) {
                    return new RetryPlan(SendStatus::OutcomeUnknown, $decision->reason, $budget, decision: $decision);
                }

                return $this->retry($decision, $budget, $now);

            case SendStatus::Failed:
                if (! $decision->retryable) {
                    return new RetryPlan(
                        $budget->unknownOutcomeSeen ? SendStatus::OutcomeUnknown : SendStatus::Failed,
                        $decision->reason,
                        $budget,
                        decision: $decision,
                    );
                }

                return $this->retry($decision, $budget, $now);

            default:
                // Accepted, Validated, Rejected (and any status a classifier should not produce) are terminal.
                return new RetryPlan($decision->status, $decision->reason, $budget, decision: $decision);
        }
    }

    private function retry(Decision $decision, RetryBudget $budget, \DateTimeImmutable $now): RetryPlan
    {
        // Now or never: the one attempt is spent and the logical expiry has already passed. An unknown outcome stays
        // OutcomeUnknown (giveUp() keeps that rule); anything retryable ends as Expired.
        if ($budget->singleAttempt) {
            return $this->giveUp($decision, $budget, ReasonCode::Expired);
        }

        if ($budget->attempts >= $this->policy->maxAttempts) {
            return $this->giveUp($decision, $budget, ReasonCode::RetryBudgetExhausted);
        }

        // A stale provider token is fixed locally by the refresh: the first time, retry at once.
        // A second expiry within the same budget (clock skew, broken refresh) falls back to normal backoff, so it
        // can never loop faster than the backoff and always ends with the budget.
        if ($decision->credentialAction === CredentialAction::RefreshToken && $budget->tokenRefreshes === 0) {
            $budget = $budget->withTokenRefresh();
            $delayMs = max(0, ($decision->minDelaySeconds ?? 0) * 1000);
        } else {
            $delayMs = $this->backoff->delayMs($budget->attempts, $decision->minDelaySeconds);
        }

        $next = $now->modify(sprintf('+%d milliseconds', $delayMs));

        if ($next >= $budget->deadline()) {
            return $this->giveUp(
                $decision,
                $budget,
                $budget->deadlineIsExpiry() ? ReasonCode::Expired : ReasonCode::RetryBudgetExhausted,
            );
        }

        return new RetryPlan(
            SendStatus::Deferred,
            $decision->reason,
            $budget,
            $next,
            $delayMs,
            $delayMs <= $this->policy->inlineThresholdMs,
            $decision,
        );
    }

    private function giveUp(Decision $decision, RetryBudget $budget, ReasonCode $code): RetryPlan
    {
        $status = match (true) {
            $budget->unknownOutcomeSeen => SendStatus::OutcomeUnknown,
            $code === ReasonCode::Expired => SendStatus::Expired,
            default => SendStatus::Failed,
        };

        $why = $code === ReasonCode::Expired
            ? 'The next attempt would fall after the message expiry, so no further attempt was planned.'
            : sprintf('Retry budget exhausted after %d attempt(s).', $budget->attempts);
        $hint = $decision->reason->hint === null ? $why : $why.' Last cause: '.$decision->reason->hint;

        return new RetryPlan($status, Reason::of($code, self::rawOf($decision->reason), $hint), $budget, decision: $decision);
    }

    private static function rawOf(Reason $cause): string
    {
        return $cause->rawReason ?? $cause->code->value;
    }
}
