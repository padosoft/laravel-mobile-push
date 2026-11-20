<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

/**
 * Values of the `apns-push-type` header. Source: "Sending notification requests to APNs",
 * section "Know when to use push types".
 */
enum PushType: string
{
    case Alert = 'alert';
    case Background = 'background';
    case LiveActivity = 'liveactivity';
    case Voip = 'voip';
    case Complication = 'complication';
    case FileProvider = 'fileprovider';
    case Location = 'location';
    case Mdm = 'mdm';
    case PushToTalk = 'pushtotalk';
    case Widgets = 'widgets';
    case Controls = 'controls';
    case Accessory = 'accessory';

    /** Suffix appended to the bundle id to form `apns-topic` ('' = the bundle id itself). */
    public function topicSuffix(): string
    {
        return match ($this) {
            self::Alert, self::Background, self::Mdm => '',
            self::LiveActivity => '.push-type.liveactivity',
            self::Voip => '.voip',
            self::Complication => '.complication',
            self::FileProvider => '.pushkit.fileprovider',
            self::Location => '.location-query',
            self::PushToTalk => '.voip-ptt',
            self::Widgets => '.push-type.widgets',
            self::Controls => '.push-type.controls',
            self::Accessory => '.push-type.accessory',
        };
    }

    /** `mdm` takes its topic from the MDM push certificate, so the bundle id cannot be used to derive it. */
    public function requiresExplicitTopic(): bool
    {
        return $this === self::Mdm;
    }

    /** Maximum payload size in bytes for a per-device request (5120 for VoIP, 4096 otherwise). */
    public function payloadLimit(): int
    {
        return $this === self::Voip ? 5120 : 4096;
    }

    /** Push types whose request must carry priority 5. */
    public function requiresLowPriority(): bool
    {
        return $this === self::Background;
    }
}
