<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** Everything that can happen to a delivery row. See DeliveryStateMachine for the transition table. */
enum DeliveryEvent: string
{
    /** A worker takes the row under a lease. */
    case Claim = 'claim';
    /** The worker is about to write the request: from now on a lost lease means an unknown outcome. */
    case StartAttempt = 'start_attempt';
    /** The worker records a SendResult (status given separately). */
    case Record = 'record';
    /** The lease expired and no request had left: simply due again. */
    case LeaseLostBeforeSend = 'lease_lost_before_send';
    /** The lease expired after the request may have left, and the unknown-outcome policy allows another attempt. */
    case LeaseLostRetry = 'lease_lost_retry';
    /** The lease expired after the request may have left, and no further attempt is allowed. */
    case LeaseLostFinal = 'lease_lost_final';
    /** The effect's logical expiry passed while the row was waiting. */
    case Expire = 'expire';
    /** Cancel / forget tombstone. */
    case Cancel = 'cancel';
}
