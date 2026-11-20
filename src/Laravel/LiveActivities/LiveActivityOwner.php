<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;

/** Optional base class for an owner model; any model can use HasLiveActivities directly instead. */
abstract class LiveActivityOwner extends Model
{
    use HasLiveActivities;
}
