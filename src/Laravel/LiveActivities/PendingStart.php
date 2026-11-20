<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/**
 * `LiveActivity::start(Type::class, attributes: [...], state: [...])->alert('Title', 'Body')->to($user)->send()`.
 *
 * Goes to every active push-to-start token (iOS 17.2+) of the owners for this type, in one scope + binding.
 * A start always needs an alert. It is not replaceable: an unknown outcome is not retried.
 */
final class PendingStart
{
    public ?Alert $alert = null;

    public ?string $sound = null;

    public ?\DateTimeImmutable $staleAt = null;

    public ?float $relevanceScore = null;

    public bool $inputPushToken = false;

    public ?string $inputPushChannel = null;

    public ?string $connection = null;

    public ?string $scope = null;

    /** @var list<Model> */
    public array $owners = [];

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  array<array-key, mixed>  $state
     */
    public function __construct(
        private readonly ActivityManager $manager,
        public readonly LiveActivityType $type,
        public readonly array $attributes,
        public readonly array $state,
        public Priority $priority = Priority::High,
    ) {}

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

    public function staleAt(\DateTimeInterface $at): self
    {
        $this->staleAt = \DateTimeImmutable::createFromInterface($at);

        return $this;
    }

    public function relevanceScore(?float $score): self
    {
        $this->relevanceScore = $score;

        return $this;
    }

    /** iOS 18+: ask the device to report an update token for the started activity (`input-push-token: 1`). */
    public function inputPushToken(bool $request = true): self
    {
        $this->inputPushToken = $request;

        return $this;
    }

    /** iOS 18+: subscribe the started activity to a broadcast channel (`input-push-channel`). */
    public function inputPushChannel(BroadcastChannel|string|null $channel): self
    {
        $this->inputPushChannel = $channel instanceof BroadcastChannel ? $channel->channel_id : $channel;

        return $this;
    }

    public function lowPriority(): self
    {
        $this->priority = Priority::Normal;

        return $this;
    }

    /** The push connection (binding name); default mobile-push.live_activities.binding. */
    public function connection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function scope(string $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    /** @param  Model|iterable<Model>  $owners */
    public function to(Model|iterable $owners): self
    {
        foreach ($owners instanceof Model ? [$owners] : $owners as $owner) {
            $this->owners[] = $owner;
        }

        return $this;
    }

    public function send(): StartResult
    {
        if ($this->alert === null) {
            throw new InvalidMessageException('A Live Activity start must include an alert, so the person is told about the new activity: call alert().');
        }

        return $this->manager->sendStart($this);
    }

    /** @internal */
    public function message(): LiveActivityMessage
    {
        $message = LiveActivityMessage::start($this->type, $this->attributes, $this->state, $this->alert ?? new Alert)
            ->priority($this->priority)
            ->relevanceScore($this->relevanceScore)
            ->inputPushToken($this->inputPushToken)
            ->inputPushChannel($this->inputPushChannel);

        if ($this->sound !== null) {
            $message->sound($this->sound);
        }
        if ($this->staleAt !== null) {
            $message->staleAt($this->staleAt);
        }

        return $message;
    }
}
