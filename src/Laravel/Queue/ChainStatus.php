<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** Verdict on one link of the timeout chain. */
enum ChainStatus: string
{
    case Ok = 'ok';
    case Violation = 'violation';
    /** One side cannot be read from this process (CLI flag, SQS attribute, unknown driver): never reported as ok. */
    case NotVerifiable = 'not_verifiable';
    /** The link does not exist for this backend (the sync driver has no worker and no redelivery). */
    case NotApplicable = 'not_applicable';
}
