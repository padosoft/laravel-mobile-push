<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Priority;

/** `LiveActivity::for($activity)`: the transitions of one activity, through its own update token. */
final readonly class ActivityHandle
{
    public function __construct(
        private ActivityManager $manager,
        public PushActivity $activity,
        private Priority $defaultPriority,
    ) {}

    /** @param  array<array-key, mixed>  $state  the full new ContentState */
    public function update(array $state): PendingTransition
    {
        return new PendingTransition($this->manager, $this->activity, LiveActivityEvent::Update, $state, $this->defaultPriority);
    }

    /**
     * End the activity. Terminal: nothing is sent to this activity afterwards, an older queued update included.
     *
     * @param  array<array-key, mixed>|null  $finalState  Apple recommends the final state, so the ended activity shows the latest data
     */
    public function end(?array $finalState = null): PendingTransition
    {
        return new PendingTransition($this->manager, $this->activity, LiveActivityEvent::End, $finalState, Priority::High);
    }

    /** Send the pending transition (coalesced or awaiting a retry) once its second has come. */
    public function flush(): TransitionResult
    {
        return $this->manager->flush($this->activity);
    }
}
