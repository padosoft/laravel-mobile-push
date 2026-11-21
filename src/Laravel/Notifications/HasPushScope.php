<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/**
 * Optional hook: the tenant scope the notification is sent in. Null = the manager's current scope.
 */
interface HasPushScope
{
    public function pushScope(mixed $notifiable): ?string;
}
