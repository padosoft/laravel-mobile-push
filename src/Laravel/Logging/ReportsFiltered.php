<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

/** A sink that deliberately skips some records (so they are not counted as written). */
interface ReportsFiltered
{
    /** Cumulative number of records received but not persisted by design. */
    public function filtered(): int;
}
