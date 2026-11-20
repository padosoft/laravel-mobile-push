<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

enum DestinationKind: string
{
    case ApnsDeviceToken = 'apns_device_token';
    case ApnsVoipToken = 'apns_voip_token';
    case ApnsLiveActivityToken = 'apns_live_activity_token';
    case ApnsPushToStartToken = 'apns_push_to_start_token';
    case ApnsBroadcastChannel = 'apns_broadcast_channel';
    case FcmToken = 'fcm_token';
    case FcmFid = 'fcm_fid';
    case FcmTopic = 'fcm_topic';
    case FcmCondition = 'fcm_condition';

    public function provider(): Provider
    {
        return str_starts_with($this->value, 'apns_') ? Provider::Apns : Provider::Fcm;
    }

    /**
     * True when the destination is one installation. Topics, conditions and broadcast channels reach an
     * unknown audience: no per-person cap, receipt, fallback or invalidation applies to them.
     */
    public function isIndividual(): bool
    {
        return ! in_array($this, [self::ApnsBroadcastChannel, self::FcmTopic, self::FcmCondition], true);
    }
}
