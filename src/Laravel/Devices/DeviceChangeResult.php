<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

final readonly class DeviceChangeResult
{
    public function __construct(public DeviceChange $change, public ?PushDevice $device = null) {}

    public function changed(): bool
    {
        return $this->change->changed();
    }
}
