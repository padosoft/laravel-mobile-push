<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/**
 * Discovery `AndroidNotification` (used under `android.notification`) and `AndroidNotificationV2`
 * (used under `android_v2.remote_notification.notification`). The two schemas are identical except:
 * `proxy` and `bypass_proxy_notification` exist only in V1, `id` exists only in V2. toArray() enforces it.
 *
 * Only what is explicitly set is recorded, so the object doubles as an overlay.
 */
final class AndroidNotification
{
    /** @var array<string, mixed> */
    private array $fields = [];

    public static function make(): self
    {
        return new self;
    }

    public function title(string $title): static
    {
        return $this->put('title', $title);
    }

    public function body(string $body): static
    {
        return $this->put('body', $body);
    }

    public function icon(string $icon): static
    {
        return $this->put('icon', FcmFields::nonEmpty($icon, 'icon'));
    }

    /** @param string $color #rrggbb */
    public function color(string $color): static
    {
        return $this->put('color', FcmFields::color($color));
    }

    /** "default" or the filename of a sound resource bundled in the app. */
    public function sound(string $sound): static
    {
        return $this->put('sound', FcmFields::nonEmpty($sound, 'sound'));
    }

    public function tag(string $tag): static
    {
        return $this->put('tag', $tag);
    }

    public function clickAction(string $action): static
    {
        return $this->put('click_action', $action);
    }

    public function bodyLocKey(string $key): static
    {
        return $this->put('body_loc_key', $key);
    }

    /** @param array<array-key, string> $args */
    public function bodyLocArgs(array $args): static
    {
        return $this->put('body_loc_args', array_values(array_map(strval(...), $args)));
    }

    public function titleLocKey(string $key): static
    {
        return $this->put('title_loc_key', $key);
    }

    /** @param array<array-key, string> $args */
    public function titleLocArgs(array $args): static
    {
        return $this->put('title_loc_args', array_values(array_map(strval(...), $args)));
    }

    public function channelId(string $channelId): static
    {
        return $this->put('channel_id', FcmFields::nonEmpty($channelId, 'channel_id'));
    }

    public function ticker(string $ticker): static
    {
        return $this->put('ticker', $ticker);
    }

    public function sticky(bool $sticky = true): static
    {
        return $this->put('sticky', $sticky);
    }

    public function eventTime(\DateTimeInterface|string $time): static
    {
        return $this->put('event_time', FcmFields::timestamp($time, 'event_time'));
    }

    public function localOnly(bool $localOnly = true): static
    {
        return $this->put('local_only', $localOnly);
    }

    public function notificationPriority(NotificationPriority $priority): static
    {
        return $this->put('notification_priority', $priority->value);
    }

    public function defaultSound(bool $default = true): static
    {
        return $this->put('default_sound', $default);
    }

    public function defaultVibrateTimings(bool $default = true): static
    {
        return $this->put('default_vibrate_timings', $default);
    }

    /** @param array<array-key, int|float|string> $timings durations: seconds or strings such as "0.5s" */
    public function vibrateTimings(array $timings): static
    {
        return $this->put('vibrate_timings', array_values(array_map(
            static fn (int|float|string $t): string => FcmFields::duration($t, 'vibrate_timings'),
            $timings,
        )));
    }

    public function defaultLightSettings(bool $default = true): static
    {
        return $this->put('default_light_settings', $default);
    }

    public function lightSettings(LightSettings $settings): static
    {
        return $this->put('light_settings', $settings->toArray());
    }

    public function visibility(NotificationVisibility $visibility): static
    {
        return $this->put('visibility', $visibility->value);
    }

    /** Number of items the notification represents (badge on launchers that support it). */
    public function notificationCount(int $count): static
    {
        if ($count < 0) {
            throw new InvalidMessageException('notification_count must not be negative.');
        }

        return $this->put('notification_count', $count);
    }

    /** HTTPS URL of an image. Overrides `notification.image`. */
    public function image(string $url): static
    {
        return $this->put('image', FcmFields::nonEmpty($url, 'image'));
    }

    /** V1 only. */
    public function proxy(NotificationProxy $proxy): static
    {
        return $this->put('proxy', $proxy->value);
    }

    /** V1 only. */
    public function bypassProxyNotification(bool $bypass = true): static
    {
        return $this->put('bypass_proxy_notification', $bypass);
    }

    /** V2 only: with `tag` forms the unique identifier (tag, id) of the notification. */
    public function id(int $id): static
    {
        return $this->put('id', $id);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidMessageException when a field that does not exist in the requested schema version is set
     */
    public function toArray(bool $v2 = false): array
    {
        $foreign = $v2 ? ['proxy', 'bypass_proxy_notification'] : ['id'];
        foreach ($foreign as $key) {
            if (array_key_exists($key, $this->fields)) {
                throw new InvalidMessageException("android notification field \"{$key}\" does not exist in ".($v2 ? 'AndroidNotificationV2 (android_v2)' : 'AndroidNotification (android)').'.');
            }
        }

        return $this->fields;
    }

    private function put(string $key, mixed $value): static
    {
        $this->fields[$key] = $value;

        return $this;
    }
}
