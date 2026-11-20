<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

enum GuardKind: string
{
    case Auth = 'auth';
    case DestinationQuality = 'destination_quality';
    /**
     * @deprecated No longer produced: Guards::before() labels every transition with its breaker.
     */
    case PreSendCheck = 'pre_send_check';
}
