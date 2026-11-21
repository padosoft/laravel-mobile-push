<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Gates;

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;

/**
 * Re-checks every registry destination immediately before the network, one query per window: the row still exists
 * in this scope and binding, is active, has the generation and the owner it had when the send was prepared.
 * Anything else is Skipped with PolicyIneligible (the phone changed hands, the user logged out, the app was
 * reinstalled). Destinations without a registry reference pass untouched, and so do destinations that are not
 * device-registry kinds (DeviceKindsOnly::KINDS): a Live Activity / push-to-start token or a broadcast channel
 * carries a DestinationRef of the Live Activities tables, which must never be looked up in the devices table.
 */
final readonly class DeviceEligibilityGate implements DestinationGate
{
    public function __construct(private DeviceEligibility $eligibility) {}

    public function check(GateContext $context, array $items): array
    {
        /** @var list<DestinationRef> $refs */
        $refs = [];
        foreach ($items as $item) {
            $ref = self::registryRef($item);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }
        if ($refs === []) {
            return [];
        }

        $verdicts = $this->eligibility->checkMany($refs, $context->binding);

        $denials = [];
        foreach ($items as $position => $item) {
            $ref = self::registryRef($item);
            $verdict = $ref === null ? null : ($verdicts[$ref->id] ?? null);
            $reason = $verdict?->reason();
            if ($reason !== null) {
                $denials[$position] = GateDenial::skip($reason);
            }
        }

        return $denials;
    }

    private static function registryRef(SendItem $item): ?DestinationRef
    {
        return DeviceKindsOnly::isDeviceKind($item->destination->kind()) ? $item->destination->ref() : null;
    }
}
