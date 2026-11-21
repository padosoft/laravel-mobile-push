<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse;

/** Recognisable fakes: if any of these strings shows up in an output, something leaked. */
final class Canary
{
    public const TOKEN = 'CANARY-DEVICE-TOKEN-0123456789abcdef0123456789abcdef';

    public const BEARER = 'ya29.CANARY-BEARER-abcdefghijklmnopqrstuvwxyz0123456789';

    public const RAW = 'CANARY-RAW-REASON-SECRET-9f8e7d6c5b4a';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::TOKEN, self::BEARER, self::RAW];
    }
}
