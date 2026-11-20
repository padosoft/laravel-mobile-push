<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

enum Provider: string
{
    case Apns = 'apns';
    case Fcm = 'fcm';
}
