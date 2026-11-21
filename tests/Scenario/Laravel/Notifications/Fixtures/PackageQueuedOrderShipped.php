<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures;

use Padosoft\MobilePush\Laravel\Notifications\QueuesPush;

/** Not queued by Laravel; the push goes to the package queue. */
final class PackageQueuedOrderShipped extends OrderShipped implements QueuesPush {}
