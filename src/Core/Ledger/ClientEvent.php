<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** What the app itself reports about a delivery. Not proof of reading, and its absence is "not observed". */
enum ClientEvent: string
{
    case Received = 'received';
    case Opened = 'opened';

    /** The deliveries column that holds the (first) report of this event. */
    public function column(): string
    {
        return match ($this) {
            self::Received => 'client_received_at',
            self::Opened => 'client_opened_at',
        };
    }
}
