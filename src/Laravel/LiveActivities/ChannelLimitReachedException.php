<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Exceptions\PushException;

/** The local cap of broadcast channels for this app and environment is reached; nothing was sent to APNs. */
final class ChannelLimitReachedException extends PushException {}
