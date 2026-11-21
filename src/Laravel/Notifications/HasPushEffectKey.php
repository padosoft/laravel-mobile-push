<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/**
 * Optional hook: the logical effect key of the push. Default (see EffectKeys): the notification id combined with
 * the notifiable's class and key. Return a stable business key (for example "order-42-shipped") to deduplicate
 * across notification instances too.
 */
interface HasPushEffectKey
{
    public function pushEffectKey(mixed $notifiable): ?string;
}
