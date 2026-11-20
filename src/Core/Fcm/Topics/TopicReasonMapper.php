<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Topics;

use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Transport\Response;

/**
 * Turns a Google error answer into a Reason, reading only enum-like fields (`error.status`, `details[].errorCode`)
 * and never `error.message`, which may echo the registration token.
 *
 * @internal
 */
final class TopicReasonMapper
{
    private const ENUM = '/^[A-Z][A-Z0-9_]{0,47}$/';

    public static function reason(Response $response): Reason
    {
        [$status, $errorCode] = self::enums($response);
        $raw = $errorCode ?? $status;
        $http = $response->status;

        $code = match (true) {
            $errorCode === 'UNREGISTERED' => ReasonCode::DestinationUnregistered,
            $errorCode === 'SENDER_ID_MISMATCH' => ReasonCode::DestinationMismatch,
            $errorCode === 'THIRD_PARTY_AUTH_ERROR' => ReasonCode::AuthThirdParty,
            $errorCode === 'QUOTA_EXCEEDED', $status === 'RESOURCE_EXHAUSTED', $http === 429 => ReasonCode::QuotaExceeded,
            $status === 'UNAUTHENTICATED', $http === 401 => ReasonCode::AuthTokenInvalid,
            $status === 'PERMISSION_DENIED', $http === 403 => ReasonCode::AuthForbidden,
            $status === 'UNAVAILABLE', $http === 503 => ReasonCode::ProviderUnavailable,
            $http >= 500 => ReasonCode::ProviderInternal,
            $status === 'INVALID_ARGUMENT', $http === 400 => ReasonCode::RequestInvalid,
            default => ReasonCode::Unknown,
        };

        return new Reason($code, $raw);
    }

    /** True for the answer Google gives when the subscription already exists (HTTP 409 / ALREADY_EXISTS). */
    public static function isAlreadyExists(Response $response): bool
    {
        return $response->status === 409 || self::enums($response)[0] === 'ALREADY_EXISTS';
    }

    /** @return array{?string, ?string} error.status and the first details[].errorCode, each only if enum-shaped */
    private static function enums(Response $response): array
    {
        $json = $response->json();
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        if (! is_array($error)) {
            return [null, null];
        }

        $status = is_string($error['status'] ?? null) && preg_match(self::ENUM, $error['status']) === 1 ? $error['status'] : null;
        $errorCode = null;

        if (is_array($error['details'] ?? null)) {
            foreach ($error['details'] as $detail) {
                if (is_array($detail) && is_string($detail['errorCode'] ?? null) && preg_match(self::ENUM, $detail['errorCode']) === 1) {
                    $errorCode = $detail['errorCode'];

                    break;
                }
            }
        }

        return [$status, $errorCode];
    }
}
