<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message;

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;

/**
 * The unified, deliberately small message. Each driver's MessageCompiler translates it; anything
 * platform specific goes through the apns()/fcm() overlays, which are applied on top of the compiled base.
 *
 * Plain serializable object: overlay callbacks run immediately and are never stored.
 */
final class PushMessage implements Message
{
    public ?string $title = null;

    public ?string $subtitle = null;

    public ?string $body = null;

    /** @var array<string, scalar|null> custom key/values (stringified for FCM data) */
    public array $data = [];

    public ?int $badge = null;

    /** Ask the server-side badge counter to increment for the owner (decided once per effect). */
    public bool $incrementBadge = false;

    public ?string $sound = null;

    public ?string $image = null;

    public ?string $deepLink = null;

    /** App-side action set (iOS category, Android click_action). Not a sending policy. */
    public ?string $actionCategory = null;

    /** Sending policy bucket for consent and frequency caps (e.g. "marketing"). Not sent to the device. */
    public ?string $policy = null;

    /** Visual grouping (iOS thread-id, Android tag/group). */
    public ?string $group = null;

    /** Replace a pending/displayed notification with the same key (APNs collapse id, FCM collapse_key). */
    public ?string $collapseKey = null;

    public MessagePriority $priority = MessagePriority::High;

    public ?\DateTimeImmutable $expiresAt = null;

    public ?int $ttlSeconds = null;

    public bool $silent = false;

    public bool $critical = false;

    public bool $timeSensitive = false;

    public bool $mutableContent = false;

    /** Safe to send twice: an unknown outcome may be retried (see docs/contracts/outcomes.md). */
    public bool $replaceable = false;

    public bool $bypassFrequencyCap = false;

    /** Opt-in: shorten title/subtitle/body to fit the limit instead of rejecting. */
    public bool $truncate = false;

    /** Drop unsupported capabilities with a warning instead of failing validation. */
    public bool $lenient = false;

    public ?ApnsMessage $apnsOverlay = null;

    public ?FcmMessage $fcmOverlay = null;

    public static function make(?string $title = null, ?string $body = null): self
    {
        $message = new self;
        $message->title = $title;
        $message->body = $body;

        return $message;
    }

    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function subtitle(?string $subtitle): static
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function body(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** @param array<string, scalar|null> $data */
    public function data(array $data): static
    {
        // array_replace keeps integer-like keys ("7"); the spread operator would renumber them.
        $this->data = array_replace($this->data, $data);

        return $this;
    }

    public function badge(?int $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    public function incrementBadge(bool $increment = true): static
    {
        $this->incrementBadge = $increment;

        return $this;
    }

    public function sound(?string $sound = 'default'): static
    {
        $this->sound = $sound;

        return $this;
    }

    public function image(?string $url): static
    {
        $this->image = $url;

        return $this;
    }

    public function deepLink(?string $url): static
    {
        $this->deepLink = $url;

        return $this;
    }

    public function actionCategory(?string $category): static
    {
        $this->actionCategory = $category;

        return $this;
    }

    public function policy(?string $policy): static
    {
        $this->policy = $policy;

        return $this;
    }

    public function group(?string $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function collapse(?string $key): static
    {
        $this->collapseKey = $key;

        return $this;
    }

    public function priority(MessagePriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /** Absolute logical expiry: after it the message is not sent, and providers stop storing it. */
    public function expiresAt(\DateTimeInterface $at): static
    {
        $this->expiresAt = \DateTimeImmutable::createFromInterface($at);
        $this->ttlSeconds = null;

        return $this;
    }

    /** Relative expiry, pinned to an absolute instant when the effect is created (0 = now or never). */
    public function ttl(int $seconds): static
    {
        $this->ttlSeconds = max(0, $seconds);
        $this->expiresAt = null;

        return $this;
    }

    public function silent(bool $silent = true): static
    {
        $this->silent = $silent;

        return $this;
    }

    public function critical(bool $critical = true): static
    {
        $this->critical = $critical;

        return $this;
    }

    public function timeSensitive(bool $timeSensitive = true): static
    {
        $this->timeSensitive = $timeSensitive;

        return $this;
    }

    public function mutableContent(bool $mutable = true): static
    {
        $this->mutableContent = $mutable;

        return $this;
    }

    public function replaceable(bool $replaceable = true): static
    {
        $this->replaceable = $replaceable;

        return $this;
    }

    public function bypassFrequencyCap(bool $bypass = true): static
    {
        $this->bypassFrequencyCap = $bypass;

        return $this;
    }

    public function truncate(bool $truncate = true): static
    {
        $this->truncate = $truncate;

        return $this;
    }

    public function lenient(bool $lenient = true): static
    {
        $this->lenient = $lenient;

        return $this;
    }

    /**
     * Customize the APNs side. The callback runs now, against a serializable overlay.
     *
     * @param  callable(ApnsMessage): mixed  $customize
     */
    public function apns(callable $customize): static
    {
        $customize($this->apnsOverlay ??= ApnsMessage::overlay());

        return $this;
    }

    /**
     * Customize the FCM side. The callback runs now, against a serializable overlay.
     *
     * @param  callable(FcmMessage): mixed  $customize
     */
    public function fcm(callable $customize): static
    {
        $customize($this->fcmOverlay ??= FcmMessage::overlay());

        return $this;
    }

    /** The absolute expiry for an effect created at `$createdAt`. */
    public function resolveExpiry(\DateTimeImmutable $createdAt): ?\DateTimeImmutable
    {
        if ($this->expiresAt !== null) {
            return $this->expiresAt;
        }

        return $this->ttlSeconds === null ? null : $createdAt->modify("+{$this->ttlSeconds} seconds");
    }
}
