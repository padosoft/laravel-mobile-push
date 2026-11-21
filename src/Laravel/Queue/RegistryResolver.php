<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\Eligibility;
use Padosoft\MobilePush\Laravel\Devices\Ineligibility;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/**
 * Turns claimed deliveries into the destinations to send, right before the network.
 *
 * Raw destinations come decrypted from the ledger. Registry deliveries carry no token: their device rows are read in
 * ONE query per chunk (scoped to the effect's binding) and must still be active, of the same generation and the same
 * owner, otherwise the delivery is skipped with PolicyIneligible: the rules are DeviceEligibility's own, applied by
 * DeviceEligibility::resolveMany() (verdict and row in the same query). A delivery whose personal data was erased
 * (forget) has neither and is skipped as cancelled.
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

        foreach ($registry as $c) {
            $ref = $c->delivery->deviceRef;
            if ($ref === null) {
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
