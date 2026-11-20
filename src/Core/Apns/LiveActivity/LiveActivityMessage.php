<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\LiveActivity;

use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Message\Sound;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Message\Message;

/**
 * A Live Activity start / update / end. A plain serializable object.
 *
 * Destinations: `start` goes to an ApnsPushToStartToken only; `update` and `end` go to an
 * ApnsLiveActivityToken or an ApnsBroadcastChannel. A broadcast can never start an activity.
 * `timestamp` is not a property: the compiler takes it from CompileContext::$now.
 */
final class LiveActivityMessage implements Message
{
    public ?Alert $alert = null;

    public ?Sound $sound = null;

    public ?\DateTimeImmutable $staleAt = null;

    public ?\DateTimeImmutable $dismissAt = null;

    public ?float $relevanceScore = null;

    public Priority $priority = Priority::High;

    /** iOS 18+: ask the device for an update token after a push-to-start (`input-push-token: 1`). */
    public bool $inputPushToken = false;

    /** iOS 18+: subscribe the started activity to a broadcast channel (`input-push-channel`). */
    public ?string $inputPushChannel = null;

    /**
     * The broadcast channel stores no messages: `apns-expiration` must then be 0. The channel policy is not
     * known to this library, so the caller declares it.
     */
    public bool $channelStoresNoMessages = false;

    /** Opt-in: shorten the alert's title/subtitle/body. `content-state` and `attributes` are never touched. */
    public bool $truncate = false;

    /**
     * @param  array<array-key, mixed>  $attributes  static data, sent once with the start
     * @param  array<array-key, mixed>  $contentState  must match the Swift ContentState; may be empty (encoded as `{}`)
     */
    private function __construct(
        public readonly LiveActivityEvent $event,
        public readonly ?LiveActivityType $type,
        public readonly array $attributes,
        public readonly ?array $contentState,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>  $contentState
     */
    public static function start(LiveActivityType $type, array $attributes, array $contentState, Alert $alert): self
    {
        $message = new self(LiveActivityEvent::Start, $type, $attributes, $contentState);
        $message->alert = $alert;

        return $message;
    }

    /**
     * @param  array<array-key, mixed>  $contentState
     * @param  LiveActivityType|null  $type  only used to record the schema version in the compiled meta
     */
    public static function update(array $contentState, ?LiveActivityType $type = null): self
    {
        return new self(LiveActivityEvent::Update, $type, [], $contentState);
    }

    /**
     * @param  array<array-key, mixed>|null  $finalContentState  Apple recommends the final state so the ended activity shows the latest data
     * @param  LiveActivityType|null  $type  only used to record the schema version in the compiled meta
     */
    public static function end(?array $finalContentState = null, ?LiveActivityType $type = null): self
    {
        return new self(LiveActivityEvent::End, $type, [], $finalContentState);
    }

    public function alert(?Alert $alert): static
    {
        $this->alert = $alert;

        return $this;
    }

    public function sound(Sound|string|null $sound = 'default'): static
    {
        $this->sound = is_string($sound) ? Sound::named($sound) : $sound;

        return $this;
    }

    /** The system marks the activity stale from this instant (UNIX seconds on the wire). */
    public function staleAt(\DateTimeInterface $at): static
    {
        $this->staleAt = \DateTimeImmutable::createFromInterface($at);

        return $this;
    }

    /** Remove an ended activity at this instant; a past instant dismisses it immediately. */
    public function dismissAt(\DateTimeInterface $at): static
    {
        $this->dismissAt = \DateTimeImmutable::createFromInterface($at);

        return $this;
    }

    /** Any number; higher shows first in the Dynamic Island and on the Lock Screen. */
    public function relevanceScore(?float $score): static
    {
        $this->relevanceScore = $score;

        return $this;
    }

    public function priority(Priority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function inputPushToken(bool $request = true): static
    {
        $this->inputPushToken = $request;

        return $this;
    }

    public function inputPushChannel(?string $channelId): static
    {
        $this->inputPushChannel = $channelId;

        return $this;
    }

    public function channelStoresNoMessages(bool $noStorage = true): static
    {
        $this->channelStoresNoMessages = $noStorage;

        return $this;
    }

    public function truncate(bool $truncate = true): static
    {
        $this->truncate = $truncate;

        return $this;
    }
}
