<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Channels\ChannelReasonMapper;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

it('maps channel-management answers to reason codes', function (int $status, ?string $raw, ReasonCode $expected) {
    expect(ChannelReasonMapper::reason($status, $raw)->code)->toBe($expected);
})->with([
    [403, 'FeatureNotEnabled', ReasonCode::FeatureNotEnabled],
    [400, 'CannotCreateChannelConfig', ReasonCode::ChannelLimitReached],
    [403, 'ExpiredProviderToken', ReasonCode::AuthTokenExpired],
    [403, 'InvalidProviderToken', ReasonCode::AuthTokenInvalid],
    [429, 'TooManyProviderTokenUpdates', ReasonCode::AuthTokenThrottled],
    [403, 'BadCertificate', ReasonCode::AuthCertificateInvalid],
    [400, 'BadChannelId', ReasonCode::ChannelUnknown],
    [404, null, ReasonCode::ChannelUnknown],
    [429, 'TooManyRequests', ReasonCode::QuotaExceeded],
    [503, 'ServiceUnavailable', ReasonCode::ProviderUnavailable],
    [500, null, ReasonCode::ProviderInternal],
    [405, 'MethodNotAllowed', ReasonCode::RequestInvalid],
    [418, 'Teapot', ReasonCode::Unknown],
]);

it('keeps the provider string sanitized in the reason', function () {
    expect(ChannelReasonMapper::reason(400, "Bad\x00Thing\n")->rawReason)->toBe('Bad?Thing?');
});
