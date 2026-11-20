<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * The single transition table of a delivery row. Both ledger implementations use it: the in-memory one applies it
 * directly, the database one turns sourcesOf() into the WHERE clause of a guarded UPDATE.
 *
 * Invariants:
 *  - a terminal state never changes (Accepted is never overwritten, a cancelled row is never resurrected);
 *  - a row in Sending never goes back to Pending: losing its lease means the outcome is unknown;
 *  - a cancel that meets an in-flight request does not lie about it: the request's own outcome is recorded, and
 *    only a further attempt is prevented;
 *  - a waiting row whose earlier attempt had an unknown outcome (`$unknownSeen`) can never end as Expired or
 *    Cancelled: the provider may have accepted that attempt, so stopping it ends as OutcomeUnknown.
 */
final class DeliveryStateMachine
{
    public static function apply(
        DeliveryState $from,
        DeliveryEvent $event,
        ?SendStatus $status = null,
        bool $cancelRequested = false,
        bool $unknownSeen = false,
    ): Transition {
        $stopped = $unknownSeen ? DeliveryState::OutcomeUnknown : null;

        return match ($event) {
            DeliveryEvent::Claim => $from->isWaiting()
                ? Transition::to($from, DeliveryState::Leased)
                : Transition::ignored($from),

            DeliveryEvent::StartAttempt => $from->isInFlight()
                ? Transition::to($from, DeliveryState::Sending)
                : Transition::ignored($from),

            DeliveryEvent::Record => self::record($from, $status, $cancelRequested, $unknownSeen),

            DeliveryEvent::LeaseLostBeforeSend => $from === DeliveryState::Leased
                ? Transition::to($from, DeliveryState::Pending)
                : Transition::ignored($from),

            DeliveryEvent::LeaseLostRetry => $from === DeliveryState::Sending
                ? Transition::to($from, $cancelRequested ? DeliveryState::OutcomeUnknown : DeliveryState::Deferred)
                : Transition::ignored($from),

            DeliveryEvent::LeaseLostFinal => $from === DeliveryState::Sending
                ? Transition::to($from, DeliveryState::OutcomeUnknown)
                : Transition::ignored($from),

            DeliveryEvent::Expire => $from->isWaiting()
                ? Transition::to($from, $stopped ?? DeliveryState::Expired)
                : Transition::ignored($from),

            DeliveryEvent::Cancel => match (true) {
                $from->isWaiting(), $from === DeliveryState::Leased => Transition::to($from, $stopped ?? DeliveryState::Cancelled),
                $from === DeliveryState::Sending => Transition::to($from, DeliveryState::Sending, requestsCancel: true),
                default => Transition::ignored($from),
            },
        };
    }

    /**
     * The states from which `$event` changes the row (used to build guarded UPDATE statements).
     *
     * @return list<DeliveryState>
     */
    public static function sourcesOf(DeliveryEvent $event): array
    {
        $sources = [];
        foreach (DeliveryState::cases() as $state) {
            $status = $event === DeliveryEvent::Record ? SendStatus::Accepted : null;
            if (self::apply($state, $event, $status)->allowed) {
                $sources[] = $state;
            }
        }

        return $sources;
    }

    private static function record(DeliveryState $from, ?SendStatus $status, bool $cancelRequested, bool $unknownSeen): Transition
    {
        if ($status === null || ! $from->isInFlight()) {
            return Transition::ignored($from);
        }

        $to = DeliveryState::fromStatus($status);
        if ($to === DeliveryState::Deferred && $cancelRequested) {
            $to = $unknownSeen ? DeliveryState::OutcomeUnknown : DeliveryState::Cancelled;
        }

        return Transition::to($from, $to);
    }
}
