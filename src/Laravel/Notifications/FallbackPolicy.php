<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Notifications\Notification;

/**
 * "May this person be contacted on that channel for this notification?" A permission to push is not a permission
 * to email. The host app registers one or more callbacks (typically in a service provider):
 *
 *     app(FallbackPolicy::class)->allowUsing(
 *         fn (object $notifiable, Notification $notification, string $channel): bool => $notifiable->wantsEmail(),
 *     );
 *
 * Every callback must return true (strictly) for the channel to be used; with none registered the answer is
 * "allowed". The callback runs after the push outcome justified a fallback and before anything is sent.
 */
final class FallbackPolicy
{
    /** @var list<\Closure(object, Notification, string): mixed> */
    private array $checks = [];

    /** @param  callable(object, Notification, string): mixed  $check */
    public function allowUsing(callable $check): void
    {
        $this->checks[] = \Closure::fromCallable($check);
    }

    public function allows(object $notifiable, Notification $notification, string $channel): bool
    {
        foreach ($this->checks as $check) {
            if ($check($notifiable, $notification, $channel) !== true) {
                return false;
            }
        }

        return true;
    }

    public function flush(): void
    {
        $this->checks = [];
    }
}
