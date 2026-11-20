<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending\Events;

use Padosoft\MobilePush\Core\Identity\BindingRef;

/** The provider account (Firebase project, Apple team) is paused until `$until` (quota, provider unavailable). */
final readonly class ProviderThrottled
{
    public function __construct(
        public BindingRef $binding,
        /** AccountKey: "fcm:<project>" or "apns:<team>". */
        public string $accountKey,
        public \DateTimeImmutable $until,
    ) {}
}
