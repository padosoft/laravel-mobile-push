<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Outcome;

use Padosoft\MobilePush\Core\Outcome\CredentialAction as Cred;
use Padosoft\MobilePush\Core\Outcome\DestinationAction as Dest;
use Padosoft\MobilePush\Core\Outcome\ReasonCode as Code;

/**
 * Every APNs `reason` string the package knows, with its meaning and the reaction to it.
 * Single source for ApnsOutcomeClassifier and for the `mobile-push:explain` command.
 *
 * Sources (fetched 2026-11-20, JSON form of the developer.apple.com pages):
 *  - documentation/usernotifications/handling-notification-responses-from-apns (device pushes)
 *  - documentation/usernotifications/handling-error-responses-from-apns (broadcast and channel management)
 * Not in those tables, accepted anyway: "BadEnvironmentKeyInToken" (variant reported in the field of the
 * documented "BadEnvironmentKeyIdInToken") and "Service Unavailable" (spelled with a space in the broadcast table).
 */
final class ApnsReasonCatalog
{
    /** @var array<string, ApnsReason>|null */
    private static ?array $entries = null;

    public static function find(string $raw): ?ApnsReason
    {
        return self::all()[$raw] ?? null;
    }

    /** @return array<string, ApnsReason> raw reason => entry */
    public static function all(): array
    {
        return self::$entries ??= self::build();
    }

    /** @return array<string, ApnsReason> */
    private static function build(): array
    {
        $r = ApnsReason::rejected(...);
        $f = ApnsReason::failed(...);
        $tokenInvalid = 'The provider token (JWT) was refused: check the key id, team id and that the .p8 key matches them and the environment; the credential breaker decides any failover.';

        $list = [
            // 400 — request and payload
            $r('BadCollapseId', 400, Code::RequestInvalid, 'apns-collapse-id is longer than 64 bytes: shorten the collapse id.'),
            $r('BadDeviceToken', 400, Code::DestinationRejected, 'APNs refused the device token: it is malformed or belongs to the other environment (sandbox vs production). The registration is quarantined, not deleted; check the environment the app declared.', Dest::Quarantine),
            $r('BadExpirationDate', 400, Code::RequestInvalid, 'apns-expiration is not a valid UNIX time (channels with "no message stored" accept only 0): fix the expiry.'),
            $r('BadMessageId', 400, Code::RequestInvalid, 'apns-id is not a canonical lowercase UUID: let the package generate it.'),
            $r('BadPriority', 400, Code::RequestInvalid, 'apns-priority must be 10, 5 or 1 and allowed for the push type: fix the priority.'),
            $r('BadTopic', 400, Code::TopicInvalid, 'apns-topic is invalid: use the bundle id with the suffix the push type requires (.voip, .push-type.liveactivity, ...).'),
            $r('DeviceTokenNotForTopic', 400, Code::DestinationMismatch, 'The device token belongs to another app (topic): the registration is quarantined; check the bundle id bound to this token.', Dest::Quarantine),
            $r('DuplicateHeaders', 400, Code::RequestInvalid, 'A header was sent twice: report this as a package bug.'),
            $f('IdleTimeout', 400, Code::TransportNotSent, 'APNs closed the idle connection without processing the request: it is resent on a fresh connection.', retryable: true),
            $r('InvalidPushType', 400, Code::RequestInvalid, 'apns-push-type is not valid for this request (broadcast accepts only LiveActivity): fix the push type.'),
            $r('MissingDeviceToken', 400, Code::RequestInvalid, 'The request path has no device token: report this as a package bug.'),
            $r('MissingTopic', 400, Code::TopicInvalid, 'apns-topic is required with a multi-topic certificate: configure the bundle id on the connection.'),
            $r('PayloadEmpty', 400, Code::PayloadInvalid, 'The payload is empty: add at least an aps dictionary.'),
            $r('TopicDisallowed', 400, Code::TopicInvalid, 'Pushing to this topic is not allowed (for broadcast, use the bare bundle id without a push-type suffix): check the topic and app capabilities.'),
            // 400 — broadcast and channel management
            $r('FeatureNotEnabled', 400, Code::FeatureNotEnabled, 'Broadcast push is not enabled for this bundle id: enable the capability in the Apple developer account.'),
            $r('MissingChannelId', 400, Code::ChannelUnknown, 'apns-channel-id is missing: pass the channel id returned by APNs at creation.'),
            $r('BadChannelId', 400, Code::ChannelUnknown, 'apns-channel-id is not valid base64 or is too long: use the exact id returned by APNs.'),
            $r('ChannelNotRegistered', 400, Code::ChannelUnknown, 'The broadcast channel does not exist (deleted, or other environment): resync channels and create it again if needed.'),
            $r('BadRequestParams', 400, Code::PayloadInvalid, 'The JSON body contains an attribute APNs does not recognise: remove it.'),
            $r('BadRequestPayload', 400, Code::PayloadInvalid, 'The JSON body could not be parsed by APNs: report this as a package bug.'),
            $r('MissingPushType', 400, Code::RequestInvalid, 'apns-push-type is missing: broadcast requests require LiveActivity.'),
            $r('CannotCreateChannelConfig', 400, Code::ChannelLimitReached, 'The app reached the maximum number of broadcast channels: delete channels no longer used before creating new ones.'),
            // 403 — credentials
            $f('BadCertificate', 403, Code::AuthCertificateInvalid, 'The client certificate is invalid: renew or replace it.', credential: Cred::ReportAuthFailure),
            $f('BadCertificateEnvironment', 403, Code::AuthCertificateInvalid, 'The client certificate is for the other environment: use the matching certificate or endpoint.', credential: Cred::ReportAuthFailure),
            $f('ExpiredProviderToken', 403, Code::AuthTokenExpired, 'The provider token is stale (older than one hour, or the server clock is wrong): it is refreshed once and the push retried; check the clock if it repeats.', retryable: true, credential: Cred::RefreshToken),
            $f('Forbidden', 403, Code::AuthForbidden, 'APNs does not allow this action for this credential: check the key or certificate capabilities.', credential: Cred::ReportAuthFailure),
            $f('InvalidProviderToken', 403, Code::AuthTokenInvalid, $tokenInvalid, credential: Cred::ReportAuthFailure),
            $f('MissingProviderToken', 403, Code::AuthTokenInvalid, 'No provider token nor client certificate was presented: configure the connection credential.', credential: Cred::ReportAuthFailure),
            $f('UnrelatedKeyIdInToken', 403, Code::AuthTokenInvalid, 'The token key id differs from the one first used on this connection: tokens of different keys must use separate connections (pool key).', credential: Cred::ReportAuthFailure),
            $f('BadEnvironmentKeyIdInToken', 403, Code::AuthTokenInvalid, 'The signing key is restricted to the other environment: use a key enabled for this environment.', credential: Cred::ReportAuthFailure),
            $f('BadEnvironmentKeyInToken', 403, Code::AuthTokenInvalid, 'The signing key is restricted to the other environment: use a key enabled for this environment.', credential: Cred::ReportAuthFailure),
            $r('TopicMismatch', 403, Code::TopicInvalid, 'The topic is not among the bundle ids of the token or certificate: use a credential of the same team/app.'),
            // 404, 405
            $r('BadPath', 404, Code::RequestInvalid, 'The request path is invalid: report this as a package bug.'),
            $r('MethodNotAllowed', 405, Code::RequestInvalid, 'The HTTP method is not allowed for this path: report this as a package bug.'),
            // 410
            $r('ExpiredToken', 410, Code::DestinationUnregistered, 'The device token has expired: the registration is invalidated; the app registers a new token at next launch.', Dest::Invalidate),
            $r('Unregistered', 410, Code::DestinationUnregistered, 'The device token is no longer active for this topic (app uninstalled or notifications reset): the registration is invalidated.', Dest::Invalidate),
            // 413
            $r('PayloadTooLarge', 413, Code::PayloadTooLarge, 'The payload exceeds the APNs limit (4 KB, 5 KB for VoIP): shorten it.'),
            // 429
            $f('TooManyProviderTokenUpdates', 429, Code::AuthTokenThrottled, 'The provider token was renewed too often (Apple: at most once every 20 minutes): token renewals slow down; this is never a reason to switch credential.', retryable: true, credential: Cred::SlowTokenRefresh),
            $f('TooManyRequests', 429, Code::DestinationRateLimited, 'Too many consecutive pushes to the same device token: retried later; send less often to this device.', retryable: true, delay: ApnsDelay::DeviceRateLimit),
            // 5xx
            $f('InternalServerError', 500, Code::ProviderInternal, 'APNs internal error: retried after the delay Apple recommends for 5xx (15 minutes).', retryable: true, delay: ApnsDelay::ServerError),
            $f('ServiceUnavailable', 503, Code::ProviderUnavailable, 'APNs is unavailable: retried after the delay Apple recommends for 5xx (15 minutes); sends to APNs are throttled meanwhile.', retryable: true, throttle: true, delay: ApnsDelay::ServerError),
            $f('Service Unavailable', 503, Code::ProviderUnavailable, 'APNs is unavailable: retried after the delay Apple recommends for 5xx (15 minutes); sends to APNs are throttled meanwhile.', retryable: true, throttle: true, delay: ApnsDelay::ServerError),
            $f('Shutdown', 503, Code::ProviderShutdown, 'The APNs server is shutting down: retried after the delay Apple recommends for 5xx (15 minutes) on a new connection.', retryable: true, delay: ApnsDelay::ServerError),
        ];

        $entries = [];
        foreach ($list as $entry) {
            $entries[$entry->raw] = $entry;
        }

        return $entries;
    }
}
