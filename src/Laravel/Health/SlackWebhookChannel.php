<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Slack incoming-webhook channel, so the package does not require laravel/slack-notification-channel.
 * The route is the webhook URL (https only); the payload is the already redacted OpsAlert text.
 */
final class SlackWebhookChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof OpsAlert || ! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }
        $url = $notifiable->routeNotificationFor(self::class, $notification);
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return;
        }

        Http::timeout(5)->connectTimeout(3)->post($url, $notification->toSlackWebhook())->throw();
    }
}
