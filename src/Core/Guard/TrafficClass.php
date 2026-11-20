<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

enum TrafficClass: string
{
    /** One-to-one pushes the user is waiting for (codes, receipts): may use the reserved share. */
    case Transactional = 'transactional';
    /** Campaigns and fan-out: can never consume the reserved share. */
    case Bulk = 'bulk';
}
