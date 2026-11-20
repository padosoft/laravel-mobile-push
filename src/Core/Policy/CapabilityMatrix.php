<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

use Padosoft\MobilePush\Core\Identity\Platform;

/**
 * Which unified capability each platform supports, and what the app must implement when the push alone is not enough.
 *
 * Built from knowledge of the APNs and FCM HTTP v1 payload models. Cells that were NOT re-verified against the
 * current docs when this table was written (marked "(unverified)" in the notes): android `badge`
 * (AndroidNotification.notification_count), android `group`, android `time_sensitive`, ios `deep_link` conventions,
 * android `broadcast` (FCM topics as the closest equivalent of an APNs broadcast channel).
 */
final readonly class CapabilityMatrix
{
    public const CAPABILITIES = [
        'title', 'subtitle', 'body', 'image', 'deep_link', 'actions', 'badge', 'sound', 'critical', 'time_sensitive',
        'silent', 'group', 'collapse', 'mutable_content', 'expiry', 'data', 'live_activity', 'broadcast',
    ];

    /** @var array<string, array{CapabilityCell, CapabilityCell}> capability => [ios, android] */
    private array $cells;

    public function __construct()
    {
        $n = static fn (?string $note = null): CapabilityCell => new CapabilityCell(Support::Native, $note);
        $a = static fn (string $note): CapabilityCell => new CapabilityCell(Support::RequiresAppCode, $note);
        $u = static fn (?string $note = null): CapabilityCell => new CapabilityCell(Support::Unavailable, $note);

        $this->cells = [
            'title' => [$n('aps.alert.title'), $n('notification.title')],
            'subtitle' => [$n('aps.alert.subtitle'), $u('no FCM field')],
            'body' => [$n('aps.alert.body'), $n('notification.body')],
            'image' => [$a('Notification Service Extension downloads and attaches the image (mutable-content)'), $n('notification.image')],
            'deep_link' => [$a('handle the custom payload key when the notification is opened (unverified convention)'), $a('intent filter / tap handler reads the data key')],
            'actions' => [$a('register the UNNotificationCategory the payload names'), $a('FCM has no action buttons: build them in a data-message handler')],
            'badge' => [$n('aps.badge (the app may still need to clear it)'), $n('notification.notification_count (unverified)')],
            'sound' => [$n('aps.sound'), $n('notification.sound (resource name or "default")')],
            'critical' => [$n('interruption-level critical; needs Apple\'s critical-alerts entitlement'), $u('use a high-importance notification channel in the app')],
            'time_sensitive' => [$n('interruption-level time-sensitive; needs the entitlement'), $a('use a high-importance notification channel (unverified)')],
            'silent' => [$n('content-available=1, push type background'), $n('data-only message')],
            'group' => [$n('aps.thread-id'), $a('group key set when the app builds the notification (unverified)')],
            'collapse' => [$n('apns-collapse-id header'), $n('android.collapse_key')],
            'mutable_content' => [$n('aps.mutable-content=1'), $u('no equivalent')],
            'expiry' => [$n('apns-expiration header'), $n('android.ttl')],
            'data' => [$n('custom top-level keys, counted in the 4 KB payload'), $n('data map, string values only')],
            'live_activity' => [$a('declare ActivityAttributes and the widget UI; the push starts/updates/ends it'), $u()],
            'broadcast' => [$a('app subscribes to the channel with ActivityKit (iOS 18+)'), $n('topics (closest equivalent, unverified)')],
        ];
    }

    public function support(string $capability, Platform $platform): CapabilityCell
    {
        $row = $this->cells[$capability] ?? throw new \InvalidArgumentException('Unknown capability "'.$capability.'".');

        return $platform === Platform::Ios ? $row[0] : $row[1];
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return array_keys($this->cells);
    }

    /** @return list<string> capabilities the platform cannot provide at all */
    public function unavailableOn(Platform $platform): array
    {
        return array_values(array_filter(
            $this->capabilities(),
            fn (string $c): bool => $this->support($c, $platform)->support === Support::Unavailable,
        ));
    }

    public function toMarkdown(): string
    {
        $cell = static function (CapabilityCell $c): string {
            $label = match ($c->support) {
                Support::Native => 'native',
                Support::RequiresAppCode => 'requires app code',
                Support::Unavailable => 'unavailable',
            };

            return $label.($c->note !== null ? ' — '.str_replace('|', '\\|', $c->note) : '');
        };

        $lines = ['| Capability | iOS (APNs) | Android (FCM) |', '|---|---|---|'];

        foreach ($this->capabilities() as $capability) {
            $lines[] = '| `'.$capability.'` | '.$cell($this->support($capability, Platform::Ios)).' | '.$cell($this->support($capability, Platform::Android)).' |';
        }

        return implode("\n", $lines)."\n";
    }
}
