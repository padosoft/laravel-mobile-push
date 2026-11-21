<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/**
 * A quarantined device and what the probe will do with it. When `$send` is false, `$row` already holds the final
 * answer (no connection, simulation, blocked, unreadable) and nothing will be sent for it.
 */
final readonly class ProbeItem
{
    public function __construct(
        public PushDevice $device,
        public ProbeRow $row,
        public bool $send = false,
        public ?BindingRef $target = null,
        public ?Destination $destination = null,
    ) {}
}
