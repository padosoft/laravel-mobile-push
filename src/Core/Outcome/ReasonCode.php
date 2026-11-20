<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

/**
 * Provider-neutral reason category. The provider's own string lives in Reason::$rawReason.
 */
enum ReasonCode: string
{
    case None = 'none';

    // Destination
    case DestinationUnregistered = 'destination_unregistered';   // APNs 410 Unregistered/ExpiredToken, FCM UNREGISTERED
    case DestinationRejected = 'destination_rejected';           // APNs BadDeviceToken (token or environment wrong)
    case DestinationMismatch = 'destination_mismatch';           // APNs DeviceTokenNotForTopic, FCM SENDER_ID_MISMATCH
    case DestinationRateLimited = 'destination_rate_limited';    // APNs TooManyRequests (per device)
    case ChannelUnknown = 'channel_unknown';                     // APNs broadcast channel not registered / bad id

    // Request and payload
    case PayloadInvalid = 'payload_invalid';
    case PayloadTooLarge = 'payload_too_large';
    case RequestInvalid = 'request_invalid';                     // bad header value, bad path, method not allowed
    case TopicInvalid = 'topic_invalid';                         // APNs BadTopic/MissingTopic/TopicDisallowed
    case FeatureNotEnabled = 'feature_not_enabled';

    // Credentials
    case AuthTokenExpired = 'auth_token_expired';
    case AuthTokenInvalid = 'auth_token_invalid';
    case AuthTokenThrottled = 'auth_token_throttled';            // APNs TooManyProviderTokenUpdates
    case AuthCertificateInvalid = 'auth_certificate_invalid';
    case AuthForbidden = 'auth_forbidden';
    case AuthThirdParty = 'auth_third_party';                    // FCM THIRD_PARTY_AUTH_ERROR

    // Provider availability
    case QuotaExceeded = 'quota_exceeded';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderInternal = 'provider_internal';
    case ProviderShutdown = 'provider_shutdown';

    // Transport
    case TransportNotSent = 'transport_not_sent';
    case TransportUnknown = 'transport_unknown';
    case ResponseUnparseable = 'response_unparseable';

    // Local decisions
    case Expired = 'expired';
    case Simulated = 'simulated';
    case Validated = 'validated';
    case PolicyIneligible = 'policy_ineligible';
    case PolicyFrequencyCapped = 'policy_frequency_capped';
    case PolicySafeMode = 'policy_safe_mode';
    case CircuitOpen = 'circuit_open';
    case BindingMismatch = 'binding_mismatch';
    case Cancelled = 'cancelled';
    case RetryBudgetExhausted = 'retry_budget_exhausted';

    /** A provider answer this version does not know: inspect Reason::$rawReason. */
    case Unknown = 'unknown';
}
