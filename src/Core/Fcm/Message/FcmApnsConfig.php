<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/**
 * Discovery `ApnsConfig` (the `apns` key of an FCM message): the APNs part of a message sent THROUGH FCM.
 * Not the direct APNs driver. Only what is explicitly set is recorded.
 */
final class FcmApnsConfig
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $payload = [];

    private ?string $liveActivityToken = null;

    private ?string $image = null;

    private ?string $analyticsLabel = null;

    public static function make(): self
    {
        return new self;
    }

    /** HTTP request header sent to APNs, e.g. "apns-priority" => "5". Values are strings. */
    public function header(string $name, string|int $value): static
    {
        $this->headers[FcmFields::nonEmpty($name, 'apns header name')] = (string) $value;

        return $this;
    }

    /** @param array<string, string|int> $headers */
    public function headers(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, $value);
        }

        return $this;
    }

    /**
     * APNs payload including the `aps` dictionary and custom keys (a free-form JSON object in the schema).
     * Merged recursively with what was set before.
     *
     * @param  array<string, mixed>  $payload
     */
    public function payload(array $payload): static
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($this->payload, $payload);
        $this->payload = $merged;

        return $this;
    }

    /** Live Activity push token or push-to-start token (ApnsConfig.live_activity_token). */
    public function liveActivityToken(#[\SensitiveParameter] string $token): static
    {
        if ($token === '') {
            throw new InvalidMessageException('live_activity_token must not be empty.');
        }
        $this->liveActivityToken = $token;

        return $this;
    }

    /** apns.fcm_options.image */
    public function image(string $url): static
    {
        $this->image = FcmFields::nonEmpty($url, 'apns image');

        return $this;
    }

    /** apns.fcm_options.analytics_label */
    public function analyticsLabel(string $label): static
    {
        $this->analyticsLabel = FcmFields::nonEmpty($label, 'analytics_label');

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        $this->headers !== [] && $out['headers'] = $this->headers;
        $this->payload !== [] && $out['payload'] = $this->payload;
        $this->liveActivityToken !== null && $out['live_activity_token'] = $this->liveActivityToken;

        $options = [];
        $this->image !== null && $options['image'] = $this->image;
        $this->analyticsLabel !== null && $options['analytics_label'] = $this->analyticsLabel;
        $options !== [] && $out['fcm_options'] = $options;

        return $out;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['apns' => $this->liveActivityToken !== null ? '[contains live activity token]' : '[apns config]'];
    }
}
