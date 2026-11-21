<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Message;

use Padosoft\MobilePush\Core\Apns\InterruptionLevel;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Apns\PushType;
use Padosoft\MobilePush\Core\Message\Message;

/**
 * Native APNs message: every `aps` key Apple documents for remote notifications, plus the request-level
 * options (push type, priority, collapse id, explicit topic).
 *
 * A plain serializable object. Every property is nullable and null means "not set": that is what makes
 * {@see self::overlay()} work, because an overlay records only what the caller explicitly sets and the
 * compiler lays it on top of a base compiled from the unified PushMessage.
 *
 * Expiry is deliberately absent: it comes from CompileContext::$expiresAt so retries never extend it.
 */
final class ApnsMessage implements Message
{
    public ?PushType $pushType = null;

    public ?Priority $priority = null;

    public ?string $collapseId = null;

    /** Explicit `apns-topic`, overriding the bundle id + push type derivation (mandatory for `mdm`). */
    public ?string $topic = null;

    public ?Alert $alert = null;

    public ?int $badge = null;

    public ?Sound $sound = null;

    public ?string $threadId = null;

    public ?string $category = null;

    public ?bool $contentAvailable = null;

    public ?bool $mutableContent = null;

    public ?string $targetContentId = null;

    public ?InterruptionLevel $interruptionLevel = null;

    public ?float $relevanceScore = null;

    public ?string $filterCriteria = null;

    /** `content-changed: true`, used by the `widgets` and `controls` push types. */
    public ?bool $contentChanged = null;

    /** @var array<array-key, mixed> custom keys, siblings of `aps` */
    public array $custom = [];

    /** Opt-in: shorten alert title/subtitle/body to fit the payload limit instead of rejecting. */
    public bool $truncate = false;

    public static function alert(?string $title = null, ?string $body = null, ?string $subtitle = null): self
    {
        $message = new self;
        $message->pushType = PushType::Alert;
        if ($title !== null || $body !== null || $subtitle !== null) {
            $message->alert = Alert::make($title, $body, $subtitle);
        }

        return $message;
    }

    /** Silent background push: `content-available: 1`, priority 5, no alert/sound/badge. */
    public static function background(): self
    {
        $message = new self;
        $message->pushType = PushType::Background;
        $message->priority = Priority::Normal;
        $message->contentAvailable = true;

        return $message;
    }

    /** @param  array<array-key, mixed>  $data  custom keys, typically the call information for PushKit */
    public static function voip(array $data = []): self
    {
        return self::ofType(PushType::Voip)->data($data);
    }

    public static function widgetsReload(): self
    {
        $message = self::ofType(PushType::Widgets);
        $message->contentChanged = true;

        return $message;
    }

    public static function controlsReload(): self
    {
        $message = self::ofType(PushType::Controls);
        $message->contentChanged = true;

        return $message;
    }

    public static function complication(): self
    {
        return self::ofType(PushType::Complication);
    }

    public static function fileProvider(): self
    {
        return self::ofType(PushType::FileProvider);
    }

    public static function location(): self
    {
        return self::ofType(PushType::Location);
    }

    /** @param  string  $topic  the UID from the subject of the MDM push certificate */
    public static function mdm(string $topic): self
    {
        $message = self::ofType(PushType::Mdm);
        $message->topic = $topic;

        return $message;
    }

    public static function pushToTalk(): self
    {
        return self::ofType(PushType::PushToTalk);
    }

    public static function accessory(): self
    {
        return self::ofType(PushType::Accessory);
    }

    /**
     * An empty message that records only what is explicitly set afterwards. Used by PushMessage::apns().
     */
    public static function overlay(): static
    {
        return new self;
    }

    public function pushType(?PushType $type): static
    {
        $this->pushType = $type;

        return $this;
    }

    public function priority(?Priority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function collapseId(?string $id): static
    {
        $this->collapseId = $id;

        return $this;
    }

    public function topic(?string $topic): static
    {
        $this->topic = $topic;

        return $this;
    }

    public function withAlert(?Alert $alert): static
    {
        $this->alert = $alert;

        return $this;
    }

    public function title(?string $title): static
    {
        $this->alert = ($this->alert ?? new Alert)->withTitle($title);

        return $this;
    }

    public function subtitle(?string $subtitle): static
    {
        $this->alert = ($this->alert ?? new Alert)->withSubtitle($subtitle);

        return $this;
    }

    public function body(?string $body): static
    {
        $this->alert = ($this->alert ?? new Alert)->withBody($body);

        return $this;
    }

    public function badge(?int $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    public function sound(Sound|string|null $sound = 'default'): static
    {
        $this->sound = is_string($sound) ? Sound::named($sound) : $sound;

        return $this;
    }

    /**
     * Critical alert: the critical sound dictionary AND `interruption-level: critical`, which Apple requires together.
     */
    public function critical(string $soundName = 'default', float $volume = 1.0): static
    {
        $this->sound = Sound::critical($soundName, $volume);
        $this->interruptionLevel = InterruptionLevel::Critical;

        return $this;
    }

    public function threadId(?string $threadId): static
    {
        $this->threadId = $threadId;

        return $this;
    }

    public function category(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function contentAvailable(?bool $flag = true): static
    {
        $this->contentAvailable = $flag;

        return $this;
    }

    public function mutableContent(?bool $flag = true): static
    {
        $this->mutableContent = $flag;

        return $this;
    }

    public function targetContentId(?string $id): static
    {
        $this->targetContentId = $id;

        return $this;
    }

    public function interruptionLevel(?InterruptionLevel $level): static
    {
        $this->interruptionLevel = $level;

        return $this;
    }

    /** @param  float|null  $score  0 to 1 for notifications */
    public function relevanceScore(?float $score): static
    {
        $this->relevanceScore = $score;

        return $this;
    }

    public function filterCriteria(?string $criteria): static
    {
        $this->filterCriteria = $criteria;

        return $this;
    }

    public function contentChanged(?bool $flag = true): static
    {
        $this->contentChanged = $flag;

        return $this;
    }

    public function custom(string $key, mixed $value): static
    {
        $this->custom[$key] = $value;

        return $this;
    }

    /** @param  array<array-key, mixed>  $data */
    public function data(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->custom[$key] = $value;
        }

        return $this;
    }

    public function truncate(bool $truncate = true): static
    {
        $this->truncate = $truncate;

        return $this;
    }

    /**
     * A new message with everything explicitly set on `$overlay` laid on top of this one. Neither input is modified.
     * Alerts merge field by field and custom data merges by key.
     */
    public function overlaidWith(self $overlay): self
    {
        $merged = clone $this;

        foreach ([
            'pushType', 'priority', 'collapseId', 'topic', 'badge', 'sound', 'threadId', 'category',
            'contentAvailable', 'mutableContent', 'targetContentId', 'interruptionLevel', 'relevanceScore',
            'filterCriteria', 'contentChanged',
        ] as $property) {
            $merged->{$property} = $overlay->{$property} ?? $this->{$property};
        }

        if ($overlay->alert !== null) {
            $merged->alert = $this->alert === null ? $overlay->alert : $this->alert->mergedWith($overlay->alert);
        }

        // array_replace keeps integer-like keys ("7"); the spread operator would renumber them.
        $merged->custom = array_replace($this->custom, $overlay->custom);
        $merged->truncate = $this->truncate || $overlay->truncate;

        return $merged;
    }

    private static function ofType(PushType $type): self
    {
        $message = new self;
        $message->pushType = $type;

        return $message;
    }
}
