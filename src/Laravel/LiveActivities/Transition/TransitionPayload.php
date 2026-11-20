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

    /** @return array<string, mixed>|null */
    public static function alertArray(?Alert $alert): ?array
    {
        if ($alert === null || $alert->isEmpty()) {
            return null;
        }

        $fields = [
            'title' => $alert->title, 'subtitle' => $alert->subtitle, 'body' => $alert->body, 'launchImage' => $alert->launchImage,
            'titleLocKey' => $alert->titleLocKey, 'titleLocArgs' => $alert->titleLocArgs,
            'subtitleLocKey' => $alert->subtitleLocKey, 'subtitleLocArgs' => $alert->subtitleLocArgs,
            'locKey' => $alert->locKey, 'locArgs' => $alert->locArgs,
        ];

        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
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
            $message->alert(self::alertFrom($this->alert));

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

    /** @param  array<string, mixed>  $fields */
    private static function alertFrom(array $fields): Alert
    {
        $string = static fn (string $name): ?string => is_string($fields[$name] ?? null) ? $fields[$name] : null;
        $list = static function (string $name) use ($fields): ?array {
            $value = $fields[$name] ?? null;
            if (! is_array($value)) {
                return null;
            }

            return array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        };

        return new Alert(
            $string('title'), $string('subtitle'), $string('body'), $string('launchImage'),
            $string('titleLocKey'), $list('titleLocArgs'), $string('subtitleLocKey'), $list('subtitleLocArgs'),
            $string('locKey'), $list('locArgs'),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
