<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Channels;

use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/**
 * Maps a channel-management error answer to a Reason.
 *
 * UNVERIFIED: Apple's channel-management page (read 2026-11-20) only says "On failure, the response body contains a
 * JSON dictionary describing the error" and links to the generic error table, which does not list channel reasons.
 * `FeatureNotEnabled` and `CannotCreateChannelConfig` come from the task brief; the "channel id problem" rule is a
 * deliberately broad pattern on any reason that names a channel. Unmatched reasons become ReasonCode::Unknown with the raw string kept.
 *
 * @internal
 */
final class ChannelReasonMapper
{
    public static function reason(int $status, ?string $raw): Reason
    {
        $hint = match (true) {
            $raw === 'CannotCreateChannelConfig' => 'The channel limit (10,000 per environment) was reached: delete unused channels.',
            $raw === 'FeatureNotEnabled' => 'Broadcast channels are not enabled for this app at Apple.',
            default => null,
        };

        return new Reason(self::code($status, $raw), $raw, $hint);
    }

    private static function code(int $status, ?string $raw): ReasonCode
    {
        $raw ??= '';

        return match (true) {
            $raw === 'FeatureNotEnabled' => ReasonCode::FeatureNotEnabled,
            $raw === 'CannotCreateChannelConfig' => ReasonCode::QuotaExceeded,
            $raw === 'ExpiredProviderToken' => ReasonCode::AuthTokenExpired,
            $raw === 'InvalidProviderToken', $raw === 'MissingProviderToken' => ReasonCode::AuthTokenInvalid,
            $raw === 'TooManyProviderTokenUpdates' => ReasonCode::AuthTokenThrottled,
            str_contains($raw, 'Certificate') => ReasonCode::AuthCertificateInvalid,
            $raw === 'Forbidden' => ReasonCode::AuthForbidden,
            preg_match('/Channel/i', $raw) === 1 => ReasonCode::ChannelUnknown,
            $raw === 'TooManyRequests', $status === 429 => ReasonCode::QuotaExceeded,
            $raw === 'ServiceUnavailable', $status === 503 => ReasonCode::ProviderUnavailable,
            $raw === 'Shutdown' => ReasonCode::ProviderShutdown,
            $status >= 500 => ReasonCode::ProviderInternal,
            $status === 404 => ReasonCode::ChannelUnknown,
            $status === 401, $status === 403 => ReasonCode::AuthForbidden,
            $status === 400, $status === 405, $status === 413 => ReasonCode::RequestInvalid,
            default => ReasonCode::Unknown,
        };
    }
}
