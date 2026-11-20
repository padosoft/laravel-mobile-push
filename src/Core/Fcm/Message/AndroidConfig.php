<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

/**
 * Discovery `AndroidConfig` (the `android` key). Mutually exclusive with `android_v2`.
 * Only what is explicitly set is recorded.
 */
final class AndroidConfig
{
    private ?string $collapseKey = null;

    private ?AndroidPriority $priority = null;

    private ?string $ttl = null;

    private ?string $restrictedPackageName = null;

    /** @var array<string, string> */
    private array $data = [];

    private ?AndroidNotification $notification = null;

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

    public function priority(AndroidPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /** @param int|float|string $ttl seconds, or a duration string such as "3600s" (0 = send immediately or drop) */
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

    /**
     * Overrides `message.data` on Android. Values are stringified (see FcmFields::stringifyData).
     *
     * @param  array<string, scalar|\Stringable|null>  $data
     */
    public function data(array $data): static
    {
        $this->data = array_replace($this->data, FcmFields::dataMap($data, 'android.data'));

        return $this;
    }

    /** @param AndroidNotification|callable(AndroidNotification): mixed $notification */
    public function notification(AndroidNotification|callable $notification): static
    {
        if (! $notification instanceof AndroidNotification) {
            $notification($built = $this->notification ?? new AndroidNotification);
            $notification = $built;
        }
        $this->notification = $notification;

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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        $this->collapseKey !== null && $out['collapse_key'] = $this->collapseKey;
        $this->priority !== null && $out['priority'] = $this->priority->value;
        $this->ttl !== null && $out['ttl'] = $this->ttl;
        $this->restrictedPackageName !== null && $out['restricted_package_name'] = $this->restrictedPackageName;
        $this->data !== [] && $out['data'] = $this->data;
        if ($this->notification !== null && ! $this->notification->isEmpty()) {
            $out['notification'] = $this->notification->toArray(false);
        }
        $this->analyticsLabel !== null && $out['fcm_options'] = ['analytics_label' => $this->analyticsLabel];
        $this->directBootOk !== null && $out['direct_boot_ok'] = $this->directBootOk;
        $this->bandwidthConstrainedOk !== null && $out['bandwidth_constrained_ok'] = $this->bandwidthConstrainedOk;
        $this->restrictedSatelliteOk !== null && $out['restricted_satellite_ok'] = $this->restrictedSatelliteOk;

        return $out;
    }
}
