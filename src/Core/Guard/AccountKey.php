<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

use Padosoft\MobilePush\Core\Identity\Provider;

/** Key of the real provider account (Firebase project id, Apple team id). Never the connection alias. */
final class AccountKey
{
    public static function of(Provider $provider, string $accountId): string
    {
        return $provider->value.':'.$accountId;
    }
}
