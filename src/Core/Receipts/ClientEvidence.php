<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Receipts;

use Padosoft\MobilePush\Core\Ledger\Delivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;

/** Derived reads over a delivery that never rewrite its provider outcome. */
final class ClientEvidence
{
    /**
     * Accepted by the provider, or an unknown outcome that the phone confirmed (a report is evidence that the push
     * arrived). The stored state of an unknown outcome stays OutcomeUnknown.
     */
    public static function acceptedOrClientConfirmed(Delivery $delivery): bool
    {
        return $delivery->state === DeliveryState::Accepted
            || ($delivery->state === DeliveryState::OutcomeUnknown && self::clientReported($delivery));
    }

    public static function clientReported(Delivery $delivery): bool
    {
        return $delivery->clientReceivedAt !== null || $delivery->clientOpenedAt !== null;
    }
}
