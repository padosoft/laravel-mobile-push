<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Priority;

/**
 * What one update or end carries, in a plain array form: it is stored as a row's pending transition and travels
 * in a queued ticket. The timestamp is NOT part of it: it is the current second when the request is built.
 */
final readonly class TransitionPayload
{
    /**
     * @param  array<array-key, mixed>|null  $state  content-state (null only for an end without a final state)
     * @param  array<string, mixed>|null  $alert  Alert constructor arguments by name
     */
    public function __construct(
        public LiveActivityEvent $event,
        public ?array $state,
        public Priority $priority = Priority::High,
        public ?int $staleAt = null,
        public ?int $dismissAt = null,
        public ?float $relevanceScore = null,
        public ?array $alert = null,
        public ?string $sound = null,
    ) {}

    public function withPriority(Priority $priority): self
    {
        return new self($this->event, $this->state, $priority, $this->staleAt, $this->dismissAt, $this->relevanceScore, $this->alert, $this->sound);
    }

    public static function alertArray(?Alert $alert): ?array
    {
        if ($alert === null || $alert->isEmpty()) {
            return null;
        }

        return array_filter(get_object_vars($alert), static fn (mixed $value): bool => $value !== null);
    }

    /** The message for this transition; `$noStorageChannel` forces apns-expiration 0 on a broadcast. */
    public function toMessage(?LiveActivityType $type = null, bool $noStorageChannel = false): LiveActivityMessage
    {
        $message = $this->event === LiveActivityEvent::End
            ? LiveActivityMessage::end($this->state, $type)
            : LiveActivityMessage::update($this->state ?? [], $type);

        $message->priority($this->priority)->relevanceScore($this->relevanceScore);

        if ($this->staleAt !== null) {
            $message->staleAt((new \DateTimeImmutable)->setTimestamp($this->staleAt));
        }
        if ($this->dismissAt !== null) {
            $message->dismissAt((new \DateTimeImmutable)->setTimestamp($this->dismissAt));
        }
        if ($this->alert !== null) {
            /** @var array<string, mixed> $arguments */
            $arguments = $this->alert;
            $message->alert(new Alert(...$arguments));

            if ($this->sound !== null) {
                $message->sound($this->sound);
            }
        }
        if ($noStorageChannel) {
            $message->channelStoresNoMessages();
        }

        return $message;
    }

    /** @return array{event: string, state: array<array-key, mixed>|null, priority: int, stale_at: int|null, dismiss_at: int|null, relevance_score: float|null, alert: array<string, mixed>|null, sound: string|null} */
    public function toArray(): array
    {
        return [
            'event' => $this->event->value,
            'state' => $this->state,
            'priority' => $this->priority->value,
            'stale_at' => $this->staleAt,
            'dismiss_at' => $this->dismissAt,
            'relevance_score' => $this->relevanceScore,
            'alert' => $this->alert,
            'sound' => $this->sound,
        ];
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $state = $data['state'] ?? null;
        $alert = $data['alert'] ?? null;
        $priority = $data['priority'] ?? Priority::High->value;
        $relevance = $data['relevance_score'] ?? null;

        /** @var array<array-key, mixed>|null $state */
        $state = is_array($state) ? $state : null;
        /** @var array<string, mixed>|null $alert */
        $alert = is_array($alert) ? $alert : null;

        return new self(
            LiveActivityEvent::from(is_string($data['event'] ?? null) ? $data['event'] : ''),
            $state,
            Priority::from(is_int($priority) ? $priority : Priority::High->value),
            self::intOrNull($data['stale_at'] ?? null),
            self::intOrNull($data['dismiss_at'] ?? null),
            is_int($relevance) || is_float($relevance) ? (float) $relevance : null,
            $alert,
            is_string($data['sound'] ?? null) ? $data['sound'] : null,
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
