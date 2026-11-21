<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

/**
 * The pre-network verdict for one registry destination together with its row (DeviceEligibility::resolveMany()).
 * The row is present whenever it exists in the scope/binding, eligible or not; read the destination from it only
 * when the verdict is eligible.
 */
final readonly class ResolvedDevice
{
    public function __construct(
        public Eligibility $eligibility,
        public ?PushDevice $device = null,
    ) {}

    public function eligible(): bool
    {
        return $this->eligibility->eligible;
    }
}
