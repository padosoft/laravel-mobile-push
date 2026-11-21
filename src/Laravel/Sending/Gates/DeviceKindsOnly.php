<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Gates;

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Identity\DestinationKind;

/**
 * Restricts a gate to installation destinations of the device registry: APNs device and VoIP tokens, FCM
 * registration tokens and installation ids. Every other kind passes untouched: Live Activity tokens and push-to-start
 * tokens carry a DestinationRef of the Live Activities tables (not of the devices table) and their transitions are
 * never frequency-capped; topics, conditions and broadcast channels are not one person.
 *
 * Positions are remapped, so the inner gate sees a dense list and its denials land on the right items.
 */
final readonly class DeviceKindsOnly implements DestinationGate
{
    public const KINDS = [
        DestinationKind::ApnsDeviceToken,
        DestinationKind::ApnsVoipToken,
        DestinationKind::FcmToken,
        DestinationKind::FcmFid,
    ];

    public function __construct(private DestinationGate $inner) {}

    public static function isDeviceKind(DestinationKind $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public function inner(): DestinationGate
    {
        return $this->inner;
    }

    public function check(GateContext $context, array $items): array
    {
        $subset = [];
        $positions = [];
        foreach ($items as $position => $item) {
            if (self::isDeviceKind($item->destination->kind())) {
                $subset[] = $item;
                $positions[] = $position;
            }
        }
        if ($subset === []) {
            return [];
        }

        $denials = [];
        foreach ($this->inner->check($context, $subset) as $index => $denial) {
            if (isset($positions[$index])) {
                $denials[$positions[$index]] = $denial;
            }
        }

        return $denials;
    }
}
