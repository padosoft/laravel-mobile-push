<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/**
 * A notification was sent through other channels because the push was known not to have been accepted.
 * Carries no token, no destination and no message content.
 */
final readonly class PushFellBack
{
    /**
     * @param  list<string>  $channels  channels the fallback was dispatched to
     * @param  array<string, int>  $counts  SendStatus value => number of destinations that justified it
     */
    public function __construct(
        public string $notifiableType,
        public ?string $notifiableId,
        public string $notificationClass,
        public ?string $notificationId,
        public array $channels,
        public FallbackReason $reason,
        public array $counts,
    ) {}
}
