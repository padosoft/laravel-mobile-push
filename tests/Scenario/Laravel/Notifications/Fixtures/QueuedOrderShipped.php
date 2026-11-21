<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Padosoft\MobilePush\Laravel\Notifications\QueuesPush;

/** Queued by Laravel; it ALSO carries the package marker, to prove that Laravel's job stays the single owner. */
final class QueuedOrderShipped extends OrderShipped implements QueuesPush, ShouldQueue
{
    use Queueable;
}
