<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

/** What the endpoint tells a client. One rejection for every reason: no oracle about which check failed. */
enum ReceiptOutcome: string
{
    case Recorded = 'recorded';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';

    public function isAcknowledged(): bool
    {
        return $this !== self::Rejected;
    }
}
