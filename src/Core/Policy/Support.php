<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/** How a platform supports a unified capability. */
enum Support: string
{
    case Native = 'native';
    case RequiresAppCode = 'requires_app_code';
    case Unavailable = 'unavailable';
}
