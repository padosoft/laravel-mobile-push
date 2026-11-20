<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

enum HttpVersion: string
{
    case Http2 = '2';
    case Http11 = '1.1';
}
