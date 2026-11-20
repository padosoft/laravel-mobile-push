<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

/**
 * Provider environment. APNs has both; FCM bindings always use Production.
 * The environment of a device is declared by the app at registration, never guessed at send time.
 */
enum Environment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';
}
