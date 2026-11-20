<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;

/**
 * `LiveActivity::broadcast($channel)`: publications to every activity subscribed to a broadcast channel (iOS 18+).
 * An accepted publication is ONE publication, not N deliveries.
 */
final readonly class ChannelHandle
{
    public function __construct(
        private ActivityManager $manager,
        public BroadcastChannel $channel,
        private Priority $defaultPriority,
    ) {}

    /** @param  array<array-key, mixed>  $state */
    public function update(array $state): PendingTransition
    {
        return new PendingTransition($this->manager, $this->channel, LiveActivityEvent::Update, $state, $this->defaultPriority);
    }

    /** @param  array<array-key, mixed>|null  $finalState */
    public function end(?array $finalState = null): PendingTransition
    {
        return new PendingTransition($this->manager, $this->channel, LiveActivityEvent::End, $finalState, Priority::High);
    }

    /**
     * A broadcast can never start a Live Activity: a remote start goes to a push-to-start token.
     *
     * @throws UnsupportedCapabilityException always, before the network
     */
    public function start(): never
    {
        throw new UnsupportedCapabilityException('A broadcast channel can never start a Live Activity: use LiveActivity::start()->to($owner), which sends to push-to-start tokens. Started activities can join the channel with inputPushChannel().');
    }

    public function flush(): TransitionResult
    {
        return $this->manager->flush($this->channel);
    }
}
