<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Priority;

final readonly class TransitionCommand
{
    public function __construct(
        /** Update or End (a start never goes through the rules: it creates its row). */
        public LiveActivityEvent $event,
        /** The ticket number handed out when the transition was dispatched. */
        public int $seq,
        public Priority $priority,
        /** True when the row's own pending transition is being flushed (coalesced, or a retry after an unknown outcome). */
        public bool $fromPending = false,
    ) {}
}
