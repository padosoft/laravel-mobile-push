<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\EffectOrigin;

/**
 * Durable profile: every delivery of an effect reached a final state (none pending, deferred or in flight) after a
 * relay run. Dispatched once per effect (see EffectSettlement).
 *
 * Carries only identifiers and counts: the scope, the effect id, the per-state delivery counts and the origin the
 * effect was created from (notifiable type/id and notification class, when it came from a Notification). No
 * destination, token or provider text.
 *
 * Fallback to another channel: a listener can re-evaluate it here. The package does not rebuild the original
 * Notification object (it is not stored); a host application that wants the fallback in the durable profile listens
 * to this event, rebuilds its notification from `origin` (or its own records) and calls
 * Padosoft\MobilePush\Laravel\Notifications\FallbackEvaluator::evaluate() with the effect's results
 * (DeliveryStore::results($scope, $effectId)). Remember that an OutcomeUnknown result never allows a fallback.
 */
final readonly class EffectSettled
{
    public function __construct(
        public string $scope,
        public string $effectId,
        public DeliveryCounts $counts,
        public ?EffectOrigin $origin,
    ) {}
}
