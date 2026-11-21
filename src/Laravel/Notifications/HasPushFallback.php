<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/**
 * Optional hook: opt in to a fallback to other notification channels (for example ['mail']).
 *
 * A fallback is considered ONLY when the push is known not to have been accepted for that person (see
 * FallbackRules). The names "push", "apns" and "fcm" are ignored. Return [] to opt out for this notifiable.
 */
interface HasPushFallback
{
    /** @return list<string> */
    public function pushFallback(mixed $notifiable): array;
}
