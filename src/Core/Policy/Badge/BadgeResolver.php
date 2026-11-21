<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Badge;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\PushMessage;

/**
 * The entry point the sender calls once per owner and effect, before sending to that owner's devices.
 *
 * Returns the message to actually send: when `incrementBadge` is set and no explicit `badge()` was given, a clone with
 * `badge` = the incremented value (and `incrementBadge` cleared); otherwise the very same instance. The original is
 * never modified. Calling it again for the same effect and owner returns the same number, so every device of the
 * owner and every retry or continuation gets one value. An explicit `badge()` wins and never touches the counter.
 * When the counter store is unavailable the message is returned unchanged (no badge change on the device is better
 * than a wrong number); callers that must know can use `BadgeCounter::increment()` directly.
 */
final readonly class BadgeResolver
{
    public function __construct(
        private BadgeCounter $counter,
        private bool $perBinding = false,
        private bool $enabled = true,
    ) {}

    /**
     * @param  string  $subject  the owner, e.g. from Subject::owner()
     * @param  ?string  $effectKey  the logical effect; null = no stable identity, every call increments
     */
    public function resolve(PushMessage $message, string $scope, string $subject, ?string $effectKey, ?BindingRef $binding = null): PushMessage
    {
        if (! $this->enabled || ! $message->incrementBadge || $message->badge !== null) {
            return $message;
        }

        try {
            $value = $this->counter->increment($scope, $this->subjectKey($subject, $binding), $effectKey);
        } catch (\Throwable) {
            return $message;
        }

        $resolved = clone $message;
        $resolved->badge = $value;
        $resolved->incrementBadge = false;

        return $resolved;
    }

    /** The counter subject of an owner (for `reset()` when the app opens), honouring the per-binding setting. */
    public function subjectKey(string $subject, ?BindingRef $binding = null): string
    {
        return $this->perBinding && $binding !== null ? $subject.'#'.$binding->name : $subject;
    }

    public function counter(): BadgeCounter
    {
        return $this->counter;
    }
}
