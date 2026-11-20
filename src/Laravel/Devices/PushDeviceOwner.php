<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Illuminate\Database\Eloquent\Model;

/** Optional base class for an owner model; any model can use HasPushDevices directly instead. */
abstract class PushDeviceOwner extends Model
{
    use HasPushDevices;
}
