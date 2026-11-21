<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

enum StoreStatus: string
{
    /** The store was processed (zero rows is a valid answer). */
    case Done = 'done';
    /** The store does not exist here (module disabled, table missing, classes absent): nothing was touched. */
    case NotPresent = 'not_present';
    /** The store exists but could not be processed. */
    case Failed = 'failed';
}
