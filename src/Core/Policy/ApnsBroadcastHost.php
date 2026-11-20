<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/**
 * Which documented host publishes broadcast notifications.
 *
 * Apple's documentation is inconsistent (verified 2026-11-20 on
 * developer.apple.com/tutorials/data/documentation/usernotifications/sending-broadcast-push-notification-requests-to-apns.json):
 * the connection section lists "Development/Sandbox Environment: api.sandbox.push.apple.com:443" and
 * "Production Environment: api.push.apple.com:443", while both sample requests use
 * ":path = 4/broadcasts/apps/com.example.MyApp" and "host = api-broadcast.sandbox.push.apple.com".
 * Which one is operational is an integration-test question (PLAN section 13), so it is a choice, not a URL.
 */
enum ApnsBroadcastHost: string
{
    case Standard = 'standard';
    case ApiBroadcast = 'api-broadcast';
}
