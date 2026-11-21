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
    /** @var list<array{0: callable(object, Notification, string): mixed, 1: \Closure(object, Notification, string): mixed}> the callback as the application passed it, and its closure */
    private array $checks = [];

    /** @param  callable(object, Notification, string): mixed  $check */
    public function allowUsing(callable $check): void
    {
        $this->checks[] = [$check, \Closure::fromCallable($check)];
    }

    /**
     * Undo allowUsing(): remove every check registered with this very callable (the same closure instance, or an
     * identical string / array callable). Returns how many were removed. For long-lived runtimes (Octane), where
     * a per-request registration must not pile up.
     *
     * @param  callable(object, Notification, string): mixed  $check
     */
    public function forget(callable $check): int
    {
        $before = count($this->checks);
        $this->checks = array_values(array_filter($this->checks, static fn (array $entry): bool => $entry[0] !== $check));

        return $before - count($this->checks);
    }

    /** Number of checks registered with allowUsing(). */
    public function count(): int
    {
        return count($this->checks);
    }

    public function allows(object $notifiable, Notification $notification, string $channel): bool
    {
        foreach ($this->checks as [, $check]) {
            if ($check($notifiable, $notification, $channel) !== true) {
                return false;
            }
        }

        return true;
    }

    /** Remove every registered check (nothing registered = every channel allowed). */
    public function flush(): void
    {
        $this->checks = [];
    }
}
