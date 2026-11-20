<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

/**
 * Discovery `AndroidConfigV2` (the `android_v2` key). Mutually exclusive with `android`.
 *
 * Differences from AndroidConfig, all taken from the Discovery document: no `priority`; the user-facing
 * notification lives in `remote_notification` (`mutable_content`, `notification`, `use_as_v1_data_message`);
 * `background_sync` carries a non-urgent, battery-friendly message. `AndroidBackgroundSyncMessage` has no
 * properties in revision 20261112: it is an empty object.
 */
final class AndroidConfigV2
{
    private ?string $collapseKey = null;

    private ?string $ttl = null;

    private ?string $restrictedPackageName = null;

    /** @var array<string, string> */
    private array $data = [];

    private ?AndroidNotification $notification = null;

    private ?bool $mutableContent = null;

    private ?bool $useAsV1DataMessage = null;

    private bool $backgroundSync = false;

    private ?string $analyticsLabel = null;

    private ?bool $directBootOk = null;

    private ?bool $bandwidthConstrainedOk = null;

    private ?bool $restrictedSatelliteOk = null;

    public static function make(): self
    {
        return new self;
    }

    public function collapseKey(string $key): static
    {
        $this->collapseKey = FcmFields::nonEmpty($key, 'collapse_key');

        return $this;
    }

    /** @param int|float|string $ttl seconds, or a duration string such as "3600s" */
    public function ttl(int|float|string $ttl): static
    {
        $this->ttl = FcmFields::duration($ttl, 'ttl');

        return $this;
    }

    public function restrictedPackageName(string $package): static
    {
        $this->restrictedPackageName = FcmFields::nonEmpty($package, 'restricted_package_name');

        return $this;
    }

    /** @param array<string, scalar|\Stringable|null> $data */
    public function data(array $data): static
    {
        $this->data = array_replace($this->data, FcmFields::dataMap($data, 'android_v2.data'));

        return $this;
    }

    /**
     * User-facing notification (remote_notification.notification).
     *
     * @param  AndroidNotification|callable(AndroidNotification): mixed  $notification
     */
    public function notification(AndroidNotification|callable $notification): static
    {
        if (! $notification instanceof AndroidNotification) {
            $notification($built = $this->notification ?? new AndroidNotification);
            $notification = $built;
        }
        $this->notification = $notification;

        return $this;
    }

    /** remote_notification.mutable_content: the app may update the content after receipt. */
    public function mutableContent(bool $mutable = true): static
    {
        $this->mutableContent = $mutable;

        return $this;
    }

    /** remote_notification.use_as_v1_data_message: deliver to legacy V1 clients as a data message. */
    public function useAsV1DataMessage(bool $use = true): static
    {
        $this->useAsV1DataMessage = $use;

        return $this;
    }

    /** Non-urgent background sync message (`background_sync: {}`). */
    public function backgroundSync(bool $enabled = true): static
    {
        $this->backgroundSync = $enabled;

        return $this;
    }

    public function analyticsLabel(string $label): static
    {
        $this->analyticsLabel = FcmFields::nonEmpty($label, 'analytics_label');

        return $this;
    }

    public function directBootOk(bool $ok = true): static
    {
        $this->directBootOk = $ok;

        return $this;
    }

    public function bandwidthConstrainedOk(bool $ok = true): static
    {
        $this->bandwidthConstrainedOk = $ok;

        return $this;
    }

    public function restrictedSatelliteOk(bool $ok = true): static
    {
        $this->restrictedSatelliteOk = $ok;

        return $this;
    }

    /** @return array<string, mixed> `background_sync` is an empty stdClass so it encodes as `{}` */
    public function toArray(): array
    {
        $out = [];
        $this->collapseKey !== null && $out['collapse_key'] = $this->collapseKey;
        $this->ttl !== null && $out['ttl'] = $this->ttl;
        $this->restrictedPackageName !== null && $out['restricted_package_name'] = $this->restrictedPackageName;
        $this->data !== [] && $out['data'] = $this->data;

        $remote = [];
        $this->mutableContent !== null && $remote['mutable_content'] = $this->mutableContent;
        if ($this->notification !== null && ! $this->notification->isEmpty()) {
            $remote['notification'] = $this->notification->toArray(true);
        }
        $this->useAsV1DataMessage !== null && $remote['use_as_v1_data_message'] = $this->useAsV1DataMessage;
        $remote !== [] && $out['remote_notification'] = $remote;

        $this->backgroundSync && $out['background_sync'] = new \stdClass;
        $this->analyticsLabel !== null && $out['fcm_options'] = ['analytics_label' => $this->analyticsLabel];
        $this->directBootOk !== null && $out['direct_boot_ok'] = $this->directBootOk;
        $this->bandwidthConstrainedOk !== null && $out['bandwidth_constrained_ok'] = $this->bandwidthConstrainedOk;
        $this->restrictedSatelliteOk !== null && $out['restricted_satellite_ok'] = $this->restrictedSatelliteOk;

        return $out;
    }
}
