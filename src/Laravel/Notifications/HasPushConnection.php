<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/**
 * Optional hook: the push connection (app) this notification is sent through. Null = let the package route by
 * provider and by the connection each registered device belongs to.
 */
interface HasPushConnection
{
    public function pushConnection(mixed $notifiable): ?string;
}
