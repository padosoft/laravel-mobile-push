<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Outcome;

use Padosoft\MobilePush\Core\Outcome\CredentialAction as Cred;
use Padosoft\MobilePush\Core\Outcome\DestinationAction as Dest;
use Padosoft\MobilePush\Core\Outcome\ReasonCode as Code;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Every FCM HTTP v1 error the package knows, with its meaning and the reaction to it.
 * Single source for FcmOutcomeClassifier and for the `mobile-push:explain` command.
 *
 * Two tables, read in this order:
 *  1. errorCodes(): `error.details[]` entries of type `type.googleapis.com/google.firebase.fcm.v1.FcmError`.
 *  2. statuses(): `error.status` (google.rpc.Code), used ONLY when there is no FcmError detail.
 *
 * Sources: the FCM error code list is not in the Discovery document (rev. 20261112); the codes and the detail
 * type were checked against firebase-admin-go (messaging/messaging.go, handleFCMError) and firebase-admin-node
 * (getErrorCode). firebase.google.com/docs/cloud-messaging/error-codes was not reachable: the HTTP status
 * each code usually comes with and the retry guidance are NOT verified against Google's page.
 */
final class FcmReasonCatalog
{
    /** @var array<string, FcmReason>|null */
    private static ?array $errorCodes = null;

    /** @var array<string, FcmReason>|null */
    private static ?array $statuses = null;

    public static function findErrorCode(string $raw): ?FcmReason
    {
        return self::errorCodes()[$raw] ?? null;
    }

    public static function findStatus(string $raw): ?FcmReason
    {
        return self::statuses()[$raw] ?? null;
    }

    /** @return array<string, FcmReason> */
    public static function errorCodes(): array
    {
        return self::$errorCodes ??= self::index([
            FcmReason::rejected('INVALID_ARGUMENT', 400, Code::PayloadInvalid, 'FCM refused the request as invalid (usually the payload: field values, data keys, sizes, TTL): fix the message. The registration is not touched.'),
            FcmReason::rejected('UNREGISTERED', 404, Code::DestinationUnregistered, 'The registration token or FID is no longer valid (app uninstalled, token rotated): the registration is invalidated.', Dest::Invalidate),
            FcmReason::rejected('SENDER_ID_MISMATCH', 403, Code::DestinationMismatch, 'The token belongs to another Firebase project (sender id): the registration is quarantined; check the project bound to this token.', Dest::Quarantine),
            FcmReason::failed('QUOTA_EXCEEDED', 429, Code::QuotaExceeded, 'The FCM sending quota was exceeded (project, device or topic): retried after at least one minute; sends to this project are throttled.', retryable: true, throttle: true, delay: FcmDelay::Throttled),
            FcmReason::failed('UNAVAILABLE', 503, Code::ProviderUnavailable, 'FCM is temporarily unavailable: retried with backoff honouring Retry-After; sends to this project are throttled.', retryable: true, throttle: true, delay: FcmDelay::Throttled),
            FcmReason::failed('INTERNAL', 500, Code::ProviderInternal, 'FCM internal error: retried with backoff.', retryable: true, delay: FcmDelay::RetryAfter),
            FcmReason::rejected('THIRD_PARTY_AUTH_ERROR', 401, Code::AuthThirdParty, 'The APNs certificate/key or Web Push credential configured in the Firebase project was refused by Apple or the browser vendor: fix it in the Firebase console (not the package credential).'),
            FcmReason::rejected('APNS_AUTH_ERROR', 401, Code::AuthThirdParty, 'Legacy name of THIRD_PARTY_AUTH_ERROR: the APNs credential configured in the Firebase project was refused; fix it in the Firebase console.'),
        ]);
    }

    /** @return array<string, FcmReason> */
    public static function statuses(): array
    {
        return self::$statuses ??= self::index([
            FcmReason::rejected('INVALID_ARGUMENT', 400, Code::PayloadInvalid, 'FCM refused the request as invalid: fix the message. The registration is not touched.'),
            FcmReason::failed('UNAUTHENTICATED', 401, Code::AuthTokenExpired, 'The OAuth access token was refused (expired or revoked): it is refreshed once and the message retried.', retryable: true, credential: Cred::RefreshToken),
            FcmReason::failed('PERMISSION_DENIED', 403, Code::AuthForbidden, 'The service account may not send for this Firebase project: grant it the "Firebase Cloud Messaging API" permission or use the right project id.', credential: Cred::ReportAuthFailure),
            FcmReason::rejected('NOT_FOUND', 404, Code::DestinationUnregistered, 'FCM answered NOT_FOUND without an FCM error detail: it may be the destination or the project id; the registration is kept (it is invalidated only on an explicit UNREGISTERED).'),
            FcmReason::failed('RESOURCE_EXHAUSTED', 429, Code::QuotaExceeded, 'FCM quota exhausted: retried after at least one minute; sends to this project are throttled.', retryable: true, throttle: true, delay: FcmDelay::Throttled),
            FcmReason::failed('UNAVAILABLE', 503, Code::ProviderUnavailable, 'FCM is temporarily unavailable: retried with backoff honouring Retry-After; sends to this project are throttled.', retryable: true, throttle: true, delay: FcmDelay::Throttled),
            FcmReason::failed('INTERNAL', 500, Code::ProviderInternal, 'FCM internal error: retried with backoff.', retryable: true, delay: FcmDelay::RetryAfter),
            FcmReason::failed('DEADLINE_EXCEEDED', 504, Code::ProviderUnavailable, 'FCM timed out while processing: the message may or may not have been accepted; retried only if replaceable.', retryable: true, delay: FcmDelay::RetryAfter, outcome: SendStatus::OutcomeUnknown),
        ]);
    }

    /**
     * @param  list<FcmReason>  $list
     * @return array<string, FcmReason>
     */
    private static function index(array $list): array
    {
        $entries = [];
        foreach ($list as $entry) {
            $entries[$entry->raw] = $entry;
        }

        return $entries;
    }
}
