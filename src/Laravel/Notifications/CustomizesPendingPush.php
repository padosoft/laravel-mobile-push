<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Padosoft\MobilePush\Laravel\Sending\PendingSend;

/**
 * Optional hook: last-moment customisation of every PendingSend the channel builds (traffic class, expiry,
 * gates, ...). It runs after the connection, scope and effect key were applied and must return a PendingSend.
 */
interface CustomizesPendingPush
{
    public function pushOptions(PendingSend $pending, mixed $notifiable): PendingSend;
}
