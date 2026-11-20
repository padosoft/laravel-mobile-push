<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
