<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Effect key of the push of one notification for one notifiable.
 *
 * Default: "n:<notification id>:<hash of notifiable class and key>". Laravel assigns the notification id once per
 * notify() call and serialises it into SendQueuedNotifications, so every retry of that job reuses it (the same
 * effect key, the same provider message ids). A notification without an id (the channel called by hand) gets no
 * effect key and therefore no idempotency.
 */
final class EffectKeys
{
    public static function forPush(object $notifiable, Notification $notification): ?string
    {
        if ($notification instanceof HasPushEffectKey || method_exists($notification, 'pushEffectKey')) {
            $custom = $notification->pushEffectKey($notifiable);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }
        $id = $notification->id ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return 'n:'.$id.':'.substr(hash('sha256', $notifiable::class.'|'.self::keyOf($notifiable)), 0, 20);
    }

    public static function keyOf(object $notifiable): string
    {
        if ($notifiable instanceof Model) {
            return (string) $notifiable->getKey();
        }
        if (method_exists($notifiable, 'getKey')) {
            return (string) $notifiable->getKey();
        }
        $id = $notifiable->id ?? null;

        return is_scalar($id) ? (string) $id : '';
    }

    /** Own id of the fallback effect on a channel. */
    public static function forFallback(string $pushEffectKey, string $channel): string
    {
        return 'fb:'.hash('sha256', $pushEffectKey.'|'.$channel);
    }

    private function __construct() {}
}
