<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\Eligibility;
use Padosoft\MobilePush\Laravel\Devices\Ineligibility;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Devices\ResolvedDevice;

/**
 * Turns claimed deliveries into the destinations to send, right before the network.
 *
 * Raw destinations come decrypted from the ledger. Registry deliveries carry no token: their device rows are read in
 * ONE query per chunk (scoped to the effect's binding) and must still be active, of the same generation and the same
 * owner, otherwise the delivery is skipped with PolicyIneligible: the rules are DeviceEligibility's own, applied by
 * DeviceEligibility::resolveMany() (verdict and row in the same query). A delivery whose personal data was erased
 * (forget) has neither and is skipped as cancelled.
 *
 * A reference missing from the effect's binding whose row exists under ANOTHER binding (a device registered on app A
 * queued through the connection of app B of the same provider) or another scope is skipped with BindingMismatch, not
 * PolicyIneligible, with the hint a direct send gives (DeviceEligibilityGate, same rules and wording): the device is
 * fine, the connection is wrong; only a connection name of the SAME scope is named, another tenant's names are never
 * echoed. Device rows never move between bindings, so this is a stable fact. It costs one extra query, only for the
 * references missing from the binding; a row really gone (pruned, deleted) keeps PolicyIneligible / row_missing. No
 * BindingMismatchDetected event: that event is for a connection re-assigned to another app, and the direct path does
 * not dispatch it for this case either.
 */
final class RegistryResolver
{
    private readonly DeviceEligibility $eligibility;

    public function __construct(?DeviceEligibility $eligibility = null)
    {
        $this->eligibility = $eligibility ?? new DeviceEligibility;
    }

    /**
     * @param  list<ClaimedDelivery>  $claimed
     * @return array{0: list<array{0: ClaimedDelivery, 1: Destination}>, 1: list<array{0: ClaimedDelivery, 1: Reason}>}
     *                                                                                                                  [to send, to skip]
     */
    public function resolve(BindingRef $binding, array $claimed): array
    {
        $send = $skip = $registry = [];
        foreach ($claimed as $c) {
            if ($c->destination !== null) {
                $send[] = [$c, $c->destination];
            } elseif ($c->delivery->deviceRef !== null) {
                $registry[] = $c;
            } else {
                $skip[] = [$c, Reason::of(ReasonCode::Cancelled, null, 'The destination was erased (forget) before it was sent.')];
            }
        }

        if ($registry === []) {
            return [$send, $skip];
        }

        $refs = [];
        foreach ($registry as $c) {
            if ($c->delivery->deviceRef !== null) {
                $refs[] = $c->delivery->deviceRef;
            }
        }
        $resolved = $this->eligibility->resolveMany($refs, $binding);
        $elsewhere = $this->registeredElsewhere($refs, $resolved, $binding);

        foreach ($registry as $c) {
            $ref = $c->delivery->deviceRef;
            if ($ref === null) {
                continue;
            }
            if (isset($elsewhere[$ref->id])) {
                $skip[] = [$c, Reason::of(ReasonCode::BindingMismatch, null, $elsewhere[$ref->id])];

                continue;
            }
            $device = $resolved[$ref->id] ?? null;
            $reason = $device?->eligibility->reason();
            $destination = $device !== null && $device->eligible() && $device->device !== null
                ? self::destinationOf($device->device, $c->delivery->kind)
                : null;
            if ($destination === null) {
                $skip[] = [$c, $reason ?? Eligibility::no(Ineligibility::RowMissing)->reason() ?? Reason::of(ReasonCode::PolicyIneligible)];

                continue;
            }
            $send[] = [$c, $destination];
        }

        return [$send, $skip];
    }

    /**
     * The references missing from the binding whose row exists under another binding or scope, with the hint to
     * report (the wording of DeviceEligibilityGate). Only the connection name of the SAME scope is named.
     *
     * @param  list<DestinationRef>  $refs
     * @param  array<string, ResolvedDevice>  $resolved
     * @return array<string, string> DestinationRef::$id => hint
     */
    private function registeredElsewhere(array $refs, array $resolved, BindingRef $binding): array
    {
        $missing = [];
        foreach ($refs as $ref) {
            if (($resolved[$ref->id] ?? null)?->eligibility->cause === Ineligibility::RowMissing) {
                $missing[$ref->id] = $ref;
            }
        }
        if ($missing === []) {
            return [];
        }

        $hints = [];
        foreach ($this->eligibility->resolveMany(array_values($missing)) as $id => $found) {
            $row = $found->device;
            if ($row === null) {
                continue;   // really gone: row_missing stands
            }
            $hints[$id] = $row->scope === $binding->scope
                ? sprintf('The device is registered on the connection "%s", not on "%s": send it through its own connection, or to its owner (each device goes to the connection it was registered on). Nothing was sent.', $row->binding_name, $binding->name)
                : sprintf('The device is registered in another scope, not on the connection "%s" of scope "%s". Nothing was sent.', $binding->name, $binding->scope);
        }

        return $hints;
    }

    /** The registry value of the stored kind; never converted between kinds (an FCM FID only from the fid column). */
    private static function destinationOf(PushDevice $row, DestinationKind $kind): ?Destination
    {
        try {
            if ($row->kind === $kind) {
                return $row->destination();
            }

            return $kind === DestinationKind::FcmFid ? $row->fidDestination() : null;
        } catch (PrivacyException|\InvalidArgumentException) {
            return null;
        }
    }
}
