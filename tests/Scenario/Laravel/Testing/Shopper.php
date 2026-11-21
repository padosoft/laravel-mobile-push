<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Testing;

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;

/** A user of the host application: its devices are typed destinations (made-up tokens). */
final class Shopper
{
    public function __construct(public readonly int $id, public readonly bool $android = false) {}

    /** @return list<\Padosoft\MobilePush\Core\Identity\Destination> */
    public function routeNotificationForPush(mixed $notification = null): array
    {
        return $this->android
            ? [FcmToken::of('fcm-token-'.$this->id.'-'.str_repeat('x', 40))]
            : [ApnsDeviceToken::of(self::apnsToken($this->id))];
    }

    public static function apnsToken(int $id): string
    {
        return substr(str_repeat(hash('sha256', 'shopper-'.$id), 2), 0, 64);
    }
}

