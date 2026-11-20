<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

enum GuardKind: string
{
    case Auth = 'auth';
    case DestinationQuality = 'destination_quality';
    /**
     * Reported by Guards::before(), whose transitions (half-open) do not say which breaker produced them.
     */
    case PreSendCheck = 'pre_send_check';
}
