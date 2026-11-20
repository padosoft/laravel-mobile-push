<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/** The role a provider URL plays. Hosts are official per role; they are never free-form. */
enum EndpointRole: string
{
    case ApnsSend = 'apns_send';
    case ApnsChannelManagement = 'apns_channel_management';
    case ApnsBroadcastPublish = 'apns_broadcast_publish';
    case FcmApi = 'fcm_api';
    case GoogleTokenExchange = 'google_token_exchange';
}
