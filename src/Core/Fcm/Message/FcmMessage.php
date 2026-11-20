<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Message\Message;

/**
 * Native FCM HTTP v1 message, modelled on the Discovery document revision 20261112
 * (tests/Fixtures/fcm/discovery-v1.json).
 *
 * The target (token / fid / topic / condition) is NOT part of the message: it comes from the Destination.
 * Only what is explicitly set is recorded, so an instance created by overlay() can be merged on top of a
 * message compiled from the unified PushMessage. Plain serializable object: callbacks passed to android(),
 * androidV2() and apns() run immediately and are never stored.
 */
final class FcmMessage implements Message
{
    private ?string $title = null;

    private ?string $body = null;

    private ?string $image = null;

    /** @var array<string, string> */
    private array $data = [];

    private ?AndroidConfig $android = null;

    private ?AndroidConfigV2 $androidV2 = null;

    private ?FcmApnsConfig $apns = null;

    private ?string $analyticsLabel = null;

    private bool $truncate = false;

    public static function make(?string $title = null, ?string $body = null): self
    {
        $message = new self;
        $title !== null && $message->title($title);
        $body !== null && $message->body($body);

        return $message;
    }

    /** An empty instance that records only what is set afterwards (see PushMessage::fcm()). */
    public static function overlay(): static
    {
        return new self;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function image(string $url): static
    {
        $this->image = FcmFields::nonEmpty($url, 'notification image');

        return $this;
    }

    /**
     * Arbitrary key/value payload. Values are stringified deterministically (true/false as "true"/"false",
     * null dropped); reserved keys ("from", "message_type", "google.*", "gcm.*") are rejected.
     *
     * @param  array<string, scalar|\Stringable|null>  $data
     */
    public function data(array $data): static
    {
        $this->data = array_replace($this->data, FcmFields::dataMap($data));

        return $this;
    }

    /**
     * @param  AndroidConfig|callable(AndroidConfig): mixed  $config
     *
     * @throws InvalidMessageException when android_v2 is already set
     */
    public function android(AndroidConfig|callable $config): static
    {
        if ($this->androidV2 !== null) {
            throw new InvalidMessageException('android and android_v2 are mutually exclusive: android_v2 is already set.');
        }
        if (! $config instanceof AndroidConfig) {
            $config($built = $this->android ?? new AndroidConfig);
            $config = $built;
        }
        $this->android = $config;

        return $this;
    }

    /**
     * @param  AndroidConfigV2|callable(AndroidConfigV2): mixed  $config
     *
     * @throws InvalidMessageException when android is already set
     */
    public function androidV2(AndroidConfigV2|callable $config): static
    {
        if ($this->android !== null) {
            throw new InvalidMessageException('android and android_v2 are mutually exclusive: android is already set.');
        }
        if (! $config instanceof AndroidConfigV2) {
            $config($built = $this->androidV2 ?? new AndroidConfigV2);
            $config = $built;
        }
        $this->androidV2 = $config;

        return $this;
    }

    /** @param FcmApnsConfig|callable(FcmApnsConfig): mixed $config */
    public function apns(FcmApnsConfig|callable $config): static
    {
        if (! $config instanceof FcmApnsConfig) {
            $config($built = $this->apns ?? new FcmApnsConfig);
            $config = $built;
        }
        $this->apns = $config;

        return $this;
    }

    /** fcm_options.analytics_label (cross-platform). */
    public function analyticsLabel(string $label): static
    {
        $this->analyticsLabel = FcmFields::nonEmpty($label, 'analytics_label');

        return $this;
    }

    /** Opt in: if the payload is over the limit, shorten title/body (never data) instead of rejecting. */
    public function truncate(bool $truncate = true): static
    {
        $this->truncate = $truncate;

        return $this;
    }

    public function truncatesText(): bool
    {
        return $this->truncate;
    }

    public function hasAndroidV2(): bool
    {
        return $this->androidV2 !== null;
    }

    public function hasAndroid(): bool
    {
        return $this->android !== null;
    }

    /**
     * The message object in wire form (snake_case, as in the protobuf field names), without target.
     * Empty containers set explicitly (e.g. `android_v2` with no field) are kept as empty arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        $notification = array_filter(
            ['title' => $this->title, 'body' => $this->body, 'image' => $this->image],
            static fn (?string $value): bool => $value !== null,
        );
        $notification !== [] && $out['notification'] = $notification;
        $this->data !== [] && $out['data'] = $this->data;
        $this->android !== null && $out['android'] = $this->android->toArray();
        $this->androidV2 !== null && $out['android_v2'] = $this->androidV2->toArray();
        $this->apns !== null && $out['apns'] = $this->apns->toArray();
        $this->analyticsLabel !== null && $out['fcm_options'] = ['analytics_label' => $this->analyticsLabel];

        return $out;
    }
}
