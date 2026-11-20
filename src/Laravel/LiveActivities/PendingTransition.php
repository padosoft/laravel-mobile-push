<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionPayload;

/**
 * One update or end of an activity, or one publication on a channel: configure, then `send()` now or take a
 * `ticket()` for a queued job (ActivityManager::replay()).
 */
final class PendingTransition
{
    private ?\DateTimeImmutable $staleAt = null;

    private ?\DateTimeImmutable $dismissAt = null;

    private ?float $relevanceScore = null;

    private ?Alert $alert = null;

    private ?string $sound = null;

    /**
     * @param  array<array-key, mixed>|null  $state
     */
    public function __construct(
        private readonly ActivityManager $manager,
        private readonly PushActivity|BroadcastChannel $row,
        private readonly LiveActivityEvent $event,
        private readonly ?array $state,
        private Priority $priority,
    ) {}

    public function staleAt(\DateTimeInterface $at): self
    {
        $this->staleAt = \DateTimeImmutable::createFromInterface($at);

        return $this;
    }

    /** End only: remove the ended activity at this instant; a past instant removes it immediately. */
    public function dismissAt(\DateTimeInterface $at): self
    {
        if ($this->event !== LiveActivityEvent::End) {
            throw new InvalidMessageException('dismissAt() only applies to a Live Activity end.');
        }
        $this->dismissAt = \DateTimeImmutable::createFromInterface($at);

        return $this;
    }

    public function relevanceScore(?float $score): self
    {
        $this->relevanceScore = $score;

        return $this;
    }

    public function alert(Alert|string $title, ?string $body = null, ?string $subtitle = null): self
    {
        $this->alert = $title instanceof Alert ? $title : Alert::make($title, $body, $subtitle);

        return $this;
    }

    public function sound(?string $sound = 'default'): self
    {
        $this->sound = $sound;

        return $this;
    }

    /** Priority 5: delivered at the device's discretion, does not spend the high-priority allowance. */
    public function lowPriority(): self
    {
        $this->priority = Priority::Normal;

        return $this;
    }

    /** Priority 10 (subject to the server's hourly limiter: beyond it the update goes out at priority 5). */
    public function highPriority(): self
    {
        $this->priority = Priority::High;

        return $this;
    }

    public function payload(): TransitionPayload
    {
        return new TransitionPayload(
            $this->event,
            $this->state,
            $this->priority,
            $this->staleAt?->getTimestamp(),
            $this->dismissAt?->getTimestamp(),
            $this->relevanceScore,
            TransitionPayload::alertArray($this->alert),
            $this->alert === null ? null : $this->sound,
        );
    }

    /** Apply it now: number it, then send it (or coalesce / skip it) under serialization. */
    public function send(): TransitionResult
    {
        return $this->manager->sendTransition($this->row, $this->payload());
    }

    /** Number it now and return what a queued job needs to apply it later with ActivityManager::replay(). */
    public function ticket(): ActivityTicket
    {
        return $this->manager->dispatchTicket($this->row, $this->payload());
    }
}
