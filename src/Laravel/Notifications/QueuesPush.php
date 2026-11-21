<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Notifications;

/**
 * Marker for a NON-queued notification whose push should be handed to the package queue (PendingSend::queue())
 * instead of being sent inline. Ignored when the notification implements ShouldQueue: Laravel's job is then the
 * single owner of queueing and the push is sent inline inside it (see PushChannel).
 *
 * Instead of the marker, a notification may define `pushQueue($notifiable): bool|array`; an array carries the
 * options `connection` (queue connection), `queue` (queue name), `delay` (seconds or DateTimeInterface) and
 * `after_commit` (bool).
 */
interface QueuesPush {}
