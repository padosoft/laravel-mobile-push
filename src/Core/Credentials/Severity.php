<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

enum Severity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
}
