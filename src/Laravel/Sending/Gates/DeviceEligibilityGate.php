<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Gates;

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\Eligibility;
use Padosoft\MobilePush\Laravel\Devices\Ineligibility;

/**
 * Re-checks every registry destination immediately before the network, one query per window: the row still exists
 * in this scope and binding, is active, has the generation and the owner it had when the send was prepared.
 * Anything else is Skipped with PolicyIneligible (the phone changed hands, the user logged out, the app was
 * reinstalled). Destinations without a registry reference pass untouched, and so do destinations that are not
 * device-registry kinds (DeviceKindsOnly::KINDS): a Live Activity / push-to-start token or a broadcast channel
 * carries a DestinationRef of the Live Activities tables, which must never be looked up in the devices table.
 *
 * A reference whose row exists but belongs to ANOTHER binding (a device registered on app A sent through the named
 * connection of app B, or a row of another scope) is Skipped with BindingMismatch, not PolicyIneligible: the device
 * is fine, the connection is wrong. Device rows never move between bindings (unique per binding identity), so this
 * is a stable fact. It costs one extra query, only for the references that are missing from the send's binding.
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

        $elsewhere = $this->registeredElsewhere($refs, $verdicts, $context->binding);

        $denials = [];
        foreach ($items as $position => $item) {
            $ref = self::registryRef($item);
            if ($ref !== null && isset($elsewhere[$ref->id])) {
                $denials[$position] = GateDenial::skip(ReasonCode::BindingMismatch, $elsewhere[$ref->id]);

                continue;
            }
            $verdict = $ref === null ? null : ($verdicts[$ref->id] ?? null);
            $reason = $verdict?->reason();
            if ($reason !== null) {
                $denials[$position] = GateDenial::skip($reason);
            }
        }

        return $denials;
    }

    /**
     * The references missing from the send's binding whose row exists under another binding or scope, with the hint
     * to report. Only the connection name of the SAME scope is named; another tenant's names are never echoed.
     *
     * @param  list<DestinationRef>  $refs
     * @param  array<string, Eligibility>  $verdicts
     * @return array<string, string> DestinationRef::$id => hint
     */
    private function registeredElsewhere(array $refs, array $verdicts, BindingRef $binding): array
    {
        $missing = [];
        foreach ($refs as $ref) {
            if (($verdicts[$ref->id] ?? null)?->cause === Ineligibility::RowMissing) {
                $missing[$ref->id] = $ref;
            }
        }
        if ($missing === []) {
            return [];
        }

        $hints = [];
        foreach ($this->eligibility->resolveMany(array_values($missing)) as $id => $resolved) {
            $row = $resolved->device;
            if ($row === null) {
                continue;   // really gone: row_missing stands
            }
            $hints[$id] = $row->scope === $binding->scope
                ? sprintf('The device is registered on the connection "%s", not on "%s": send it through its own connection, or to its owner (each device goes to the connection it was registered on). Nothing was sent.', $row->binding_name, $binding->name)
                : sprintf('The device is registered in another scope, not on the connection "%s" of scope "%s". Nothing was sent.', $binding->name, $binding->scope);
        }

        return $hints;
    }

    private static function registryRef(SendItem $item): ?DestinationRef
    {
        return DeviceKindsOnly::isDeviceKind($item->destination->kind()) ? $item->destination->ref() : null;
    }
}
