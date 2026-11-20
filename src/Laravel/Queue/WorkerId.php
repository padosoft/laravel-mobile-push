<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** A lease owner unique to one relay run: host, process and a random part (fits the 191-character column). */
final class WorkerId
{
    public static function generate(): string
    {
        $host = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) gethostname()) ?? 'host';

        return substr($host === '' ? 'host' : $host, 0, 120).':'.getmypid().':'.bin2hex(random_bytes(12));
    }
}
