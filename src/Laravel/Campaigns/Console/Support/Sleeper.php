<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console\Support;

/** Waits between two refreshes of `campaign:status --watch`. Bind another implementation to make the loop testable. */
interface Sleeper
{
    public function sleep(int $seconds): void;
}
