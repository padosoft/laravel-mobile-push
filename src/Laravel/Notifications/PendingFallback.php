<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * What DurableFallbacks keeps between queueing a durable push and its settlement. Serialized like a queued Laravel
 * notification: Eloquent models become identifiers and are reloaded on restore. Always stored encrypted.
 *
 * @internal
 */
final class PendingFallback
{
    use SerializesModels;

    /** @param  list<string>  $effects  every ledger effect of the push */
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public string $pushEffectKey,
        public string $scope,
        public array $effects,
    ) {}
}
