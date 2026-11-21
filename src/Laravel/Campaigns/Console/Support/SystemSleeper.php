<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console\Support;

final class SystemSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep(max(0, $seconds));
    }
}
