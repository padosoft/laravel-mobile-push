<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Apns\Outcome\ApnsReasonCatalog;
use Padosoft\MobilePush\Core\Outcome\CredentialAction as Cred;
use Padosoft\MobilePush\Core\Outcome\DestinationAction as Dest;
use Padosoft\MobilePush\Core\Outcome\ReasonCode as Code;
use Padosoft\MobilePush\Core\Outcome\SendStatus as S;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Outcome\OutcomeKit as K;

/*
 * The table is the specification: status, reason → (SendStatus, ReasonCode, retryable, destination action,
 * credential action, throttle, minimum delay). Written by hand from Apple's tables, not derived from the catalog.
 */
dataset('apns reasons', [
    // reason => [http, status, code, retryable, dest, cred, throttle, minDelay]
    'BadCollapseId' => [400, 'BadCollapseId', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'BadDeviceToken' => [400, 'BadDeviceToken', S::Rejected, Code::DestinationRejected, false, Dest::Quarantine, Cred::None, false, null],
    'BadExpirationDate' => [400, 'BadExpirationDate', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'BadMessageId' => [400, 'BadMessageId', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'BadPriority' => [400, 'BadPriority', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'BadTopic' => [400, 'BadTopic', S::Rejected, Code::TopicInvalid, false, Dest::None, Cred::None, false, null],
    'DeviceTokenNotForTopic' => [400, 'DeviceTokenNotForTopic', S::Rejected, Code::DestinationMismatch, false, Dest::Quarantine, Cred::None, false, null],
    'DuplicateHeaders' => [400, 'DuplicateHeaders', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'IdleTimeout' => [400, 'IdleTimeout', S::Failed, Code::TransportNotSent, true, Dest::None, Cred::None, false, null],
    'InvalidPushType' => [400, 'InvalidPushType', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'MissingDeviceToken' => [400, 'MissingDeviceToken', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'MissingTopic' => [400, 'MissingTopic', S::Rejected, Code::TopicInvalid, false, Dest::None, Cred::None, false, null],
    'PayloadEmpty' => [400, 'PayloadEmpty', S::Rejected, Code::PayloadInvalid, false, Dest::None, Cred::None, false, null],
    'TopicDisallowed' => [400, 'TopicDisallowed', S::Rejected, Code::TopicInvalid, false, Dest::None, Cred::None, false, null],
    'FeatureNotEnabled' => [400, 'FeatureNotEnabled', S::Rejected, Code::FeatureNotEnabled, false, Dest::None, Cred::None, false, null],
    'MissingChannelId' => [400, 'MissingChannelId', S::Rejected, Code::ChannelUnknown, false, Dest::None, Cred::None, false, null],
    'BadChannelId' => [400, 'BadChannelId', S::Rejected, Code::ChannelUnknown, false, Dest::None, Cred::None, false, null],
    'ChannelNotRegistered' => [400, 'ChannelNotRegistered', S::Rejected, Code::ChannelUnknown, false, Dest::None, Cred::None, false, null],
    'BadRequestParams' => [400, 'BadRequestParams', S::Rejected, Code::PayloadInvalid, false, Dest::None, Cred::None, false, null],
    'BadRequestPayload' => [400, 'BadRequestPayload', S::Rejected, Code::PayloadInvalid, false, Dest::None, Cred::None, false, null],
    'MissingPushType' => [400, 'MissingPushType', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'CannotCreateChannelConfig' => [400, 'CannotCreateChannelConfig', S::Rejected, Code::ChannelLimitReached, false, Dest::None, Cred::None, false, null],
    'BadCertificate' => [403, 'BadCertificate', S::Failed, Code::AuthCertificateInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'BadCertificateEnvironment' => [403, 'BadCertificateEnvironment', S::Failed, Code::AuthCertificateInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'ExpiredProviderToken' => [403, 'ExpiredProviderToken', S::Failed, Code::AuthTokenExpired, true, Dest::None, Cred::RefreshToken, false, null],
    'Forbidden' => [403, 'Forbidden', S::Failed, Code::AuthForbidden, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'InvalidProviderToken' => [403, 'InvalidProviderToken', S::Failed, Code::AuthTokenInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'MissingProviderToken' => [403, 'MissingProviderToken', S::Failed, Code::AuthTokenInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'UnrelatedKeyIdInToken' => [403, 'UnrelatedKeyIdInToken', S::Failed, Code::AuthTokenInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'BadEnvironmentKeyIdInToken' => [403, 'BadEnvironmentKeyIdInToken', S::Failed, Code::AuthTokenInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'BadEnvironmentKeyInToken (variant)' => [403, 'BadEnvironmentKeyInToken', S::Failed, Code::AuthTokenInvalid, false, Dest::None, Cred::ReportAuthFailure, false, null],
    'TopicMismatch' => [403, 'TopicMismatch', S::Rejected, Code::TopicInvalid, false, Dest::None, Cred::None, false, null],
    'BadPath' => [404, 'BadPath', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'MethodNotAllowed' => [405, 'MethodNotAllowed', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None, false, null],
    'ExpiredToken' => [410, 'ExpiredToken', S::Rejected, Code::DestinationUnregistered, false, Dest::Invalidate, Cred::None, false, null],
    'Unregistered' => [410, 'Unregistered', S::Rejected, Code::DestinationUnregistered, false, Dest::Invalidate, Cred::None, false, null],
    'PayloadTooLarge' => [413, 'PayloadTooLarge', S::Rejected, Code::PayloadTooLarge, false, Dest::None, Cred::None, false, null],
    'TooManyProviderTokenUpdates' => [429, 'TooManyProviderTokenUpdates', S::Failed, Code::AuthTokenThrottled, true, Dest::None, Cred::SlowTokenRefresh, false, null],
    'TooManyRequests' => [429, 'TooManyRequests', S::Failed, Code::DestinationRateLimited, true, Dest::None, Cred::None, false, 60],
    'InternalServerError' => [500, 'InternalServerError', S::Failed, Code::ProviderInternal, true, Dest::None, Cred::None, false, 900],
    'ServiceUnavailable' => [503, 'ServiceUnavailable', S::Failed, Code::ProviderUnavailable, true, Dest::None, Cred::None, true, 900],
    'Service Unavailable (broadcast spelling)' => [503, 'Service Unavailable', S::Failed, Code::ProviderUnavailable, true, Dest::None, Cred::None, true, 900],
    'Shutdown' => [503, 'Shutdown', S::Failed, Code::ProviderShutdown, true, Dest::None, Cred::None, false, 900],
]);

it('classifies every documented APNs reason', function (int $http, string $reason, S $status, Code $code, bool $retryable, Dest $dest, Cred $cred, bool $throttle, ?int $minDelay): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns($http, $reason, extra: $http === 410 ? ['timestamp' => 1763632800123] : []), K::apnsContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBe($reason)
        ->and($decision->reason->hint)->toBeString()->not->toBeEmpty()
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->destinationAction)->toBe($dest)
        ->and($decision->credentialAction)->toBe($cred)
        ->and($decision->throttleProvider)->toBe($throttle)
        ->and($decision->minDelaySeconds)->toBe($minDelay)
        ->and($decision->httpStatus)->toBe($http)
        ->and($decision->providerMessageId)->toBe(K::APNS_ID);
})->with('apns reasons');

it('covers every catalog entry in the table above', function (): void {
    $tested = ['BadCollapseId', 'BadDeviceToken', 'BadExpirationDate', 'BadMessageId', 'BadPriority', 'BadTopic', 'DeviceTokenNotForTopic', 'DuplicateHeaders', 'IdleTimeout', 'InvalidPushType', 'MissingDeviceToken', 'MissingTopic', 'PayloadEmpty', 'TopicDisallowed', 'FeatureNotEnabled', 'MissingChannelId', 'BadChannelId', 'ChannelNotRegistered', 'BadRequestParams', 'BadRequestPayload', 'MissingPushType', 'CannotCreateChannelConfig', 'BadCertificate', 'BadCertificateEnvironment', 'ExpiredProviderToken', 'Forbidden', 'InvalidProviderToken', 'MissingProviderToken', 'UnrelatedKeyIdInToken', 'BadEnvironmentKeyIdInToken', 'BadEnvironmentKeyInToken', 'TopicMismatch', 'BadPath', 'MethodNotAllowed', 'ExpiredToken', 'Unregistered', 'PayloadTooLarge', 'TooManyProviderTokenUpdates', 'TooManyRequests', 'InternalServerError', 'ServiceUnavailable', 'Service Unavailable', 'Shutdown'];

    expect(array_keys(ApnsReasonCatalog::all()))->toEqualCanonicalizing($tested);
});

it('accepts a 200 and keeps apns-id and apns-unique-id', function (): void {
    $exchange = Exchange::responded(K::apnsRequest(), new Response(200, ['apns-id' => K::APNS_ID, 'apns-unique-id' => 'a8f3c2e1-1111-4222-8333-444455556666']));

    $decision = (new ApnsOutcomeClassifier)->classify($exchange, K::apnsContext());

    expect($decision->status)->toBe(S::Accepted)
        ->and($decision->reason->code)->toBe(Code::None)
        ->and($decision->providerMessageId)->toBe(K::APNS_ID)
        ->and($decision->meta)->toBe(['apns-unique-id' => 'a8f3c2e1-1111-4222-8333-444455556666'])
        ->and($decision->retryable)->toBeFalse();
});

it('accepts broadcast (apns-request-id) and channel management 201/204 answers', function (int $status): void {
    $exchange = Exchange::responded(K::apnsRequest(), new Response($status, ['apns-request-id' => 'b0000000-0000-4000-8000-000000000002']));

    $decision = (new ApnsOutcomeClassifier)->classify($exchange, K::apnsContext(K::channel()));

    expect($decision->status)->toBe(S::Accepted)
        ->and($decision->providerMessageId)->toBe('b0000000-0000-4000-8000-000000000002')
        ->and($decision->meta)->toBe([]);
})->with([200, 201, 204]);

it('reads the 410 timestamp as Unix milliseconds, exactly', function (mixed $timestamp, ?int $expected): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns(410, 'Unregistered', extra: ['timestamp' => $timestamp]), K::apnsContext());

    expect($decision->destinationAction)->toBe(Dest::Invalidate)
        ->and($decision->invalidatedAtMs)->toBe($expected);
})->with([
    'int ms' => [1763632800123, 1763632800123],
    'numeric string' => ['1763632800123', 1763632800123],
    'float' => [1763632800123.0, 1763632800123],
    'missing' => [null, null],
    'garbage' => ['yesterday', null],
    'negative' => [-5, null],
]);

it('never invalidates on Unregistered without the 410 status', function (): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns(400, 'Unregistered', extra: ['timestamp' => 1763632800123]), K::apnsContext());

    expect($decision->status)->toBe(S::Rejected)
        ->and($decision->destinationAction)->toBe(Dest::None)
        ->and($decision->invalidatedAtMs)->toBeNull();
});

it('suppresses destination actions for a broadcast channel', function (int $http, string $reason): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns($http, $reason, extra: ['timestamp' => 1763632800123]), K::apnsContext(K::channel()));

    expect($decision->status)->toBe(S::Rejected)
        ->and($decision->destinationAction)->toBe(Dest::None)
        ->and($decision->invalidatedAtMs)->toBeNull();
})->with([[410, 'Unregistered'], [410, 'ExpiredToken'], [400, 'BadDeviceToken'], [400, 'DeviceTokenNotForTopic']]);

it('keeps destination actions for every individual APNs destination kind', function (): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns(410, 'Unregistered', extra: ['timestamp' => 1]), K::apnsContext(K::liveActivity()));

    expect($decision->destinationAction)->toBe(Dest::Invalidate)->and($decision->invalidatedAtMs)->toBe(1);
});

it('makes the 5xx and device rate-limit delays injected settings', function (): void {
    $classifier = new ApnsOutcomeClassifier(serverErrorRetryDelaySeconds: 30, deviceRateLimitDelaySeconds: 5);

    expect($classifier->classify(K::apns(503, 'ServiceUnavailable'), K::apnsContext())->minDelaySeconds)->toBe(30)
        ->and($classifier->classify(K::apns(500, 'InternalServerError'), K::apnsContext())->minDelaySeconds)->toBe(30)
        ->and($classifier->classify(K::apns(429, 'TooManyRequests'), K::apnsContext())->minDelaySeconds)->toBe(5);
});

it('keeps an unknown reason string and decides by HTTP class', function (int $http, S $status, bool $retryable): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns($http, 'BrandNewReason2027'), K::apnsContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe(Code::Unknown)
        ->and($decision->reason->rawReason)->toBe('BrandNewReason2027')
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->destinationAction)->toBe(Dest::None)
        ->and($decision->credentialAction)->toBe(Cred::None);
})->with([
    '400' => [400, S::Rejected, false],
    '403' => [403, S::Rejected, false],
    '410' => [410, S::Rejected, false],
    '429 stays retryable' => [429, S::Failed, true],
    '500' => [500, S::Failed, true],
    '502' => [502, S::Failed, true],
]);

it('classifies by status alone when the body is missing or unparseable', function (int $http, string $body, S $status, Code $code, bool $retryable, Dest $dest, Cred $cred): void {
    $exchange = Exchange::responded(K::apnsRequest(), new Response($http, [], $body));
    $decision = (new ApnsOutcomeClassifier)->classify($exchange, K::apnsContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBeNull()
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->destinationAction)->toBe($dest)
        ->and($decision->credentialAction)->toBe($cred);
})->with([
    '400 empty' => [400, '', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None],
    '400 html' => [400, '<html>bad</html>', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None],
    '400 reason not a string' => [400, '{"reason":["x"]}', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None],
    '403 truncated json' => [403, '{"reason":"Expi', S::Failed, Code::AuthForbidden, false, Dest::None, Cred::ReportAuthFailure],
    '404' => [404, '', S::Rejected, Code::RequestInvalid, false, Dest::None, Cred::None],
    '410 bare does not invalidate' => [410, '', S::Rejected, Code::DestinationUnregistered, false, Dest::None, Cred::None],
    '413' => [413, '', S::Rejected, Code::PayloadTooLarge, false, Dest::None, Cred::None],
    '429' => [429, 'null', S::Failed, Code::DestinationRateLimited, true, Dest::None, Cred::None],
    '500' => [500, '', S::Failed, Code::ProviderInternal, true, Dest::None, Cred::None],
    '503' => [503, '[]', S::Failed, Code::ProviderUnavailable, true, Dest::None, Cred::None],
    '418 undocumented 4xx' => [418, '', S::Rejected, Code::Unknown, false, Dest::None, Cred::None],
    '302 not a provider answer' => [302, '', S::OutcomeUnknown, Code::ResponseUnparseable, true, Dest::None, Cred::None],
    '100' => [100, '', S::OutcomeUnknown, Code::ResponseUnparseable, true, Dest::None, Cred::None],
]);

it('maps transport evidence: NotSent is a retryable failure, Unknown is an unknown outcome without actions', function (): void {
    $classifier = new ApnsOutcomeClassifier;
    $notSent = $classifier->classify(K::notSent(), K::apnsContext());
    $unknown = $classifier->classify(K::unknown(), K::apnsContext());

    expect($notSent->status)->toBe(S::Failed)
        ->and($notSent->reason->code)->toBe(Code::TransportNotSent)
        ->and($notSent->retryable)->toBeTrue()
        ->and($notSent->minDelaySeconds)->toBeNull()
        ->and($notSent->meta)->toBe(['transport_errno' => '7'])
        ->and($unknown->status)->toBe(S::OutcomeUnknown)
        ->and($unknown->reason->code)->toBe(Code::TransportUnknown)
        ->and($unknown->retryable)->toBeTrue()
        ->and($unknown->destinationAction)->toBe(Dest::None)
        ->and($unknown->credentialAction)->toBe(Cred::None)
        ->and($unknown->throttleProvider)->toBeFalse()
        ->and($unknown->status->allowsFallback())->toBeFalse();
});

it('never copies the transport error message (it may contain the URL with the device token)', function (): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::unknown(), K::apnsContext());

    expect(serialize($decision))->not->toContain('INTERNAL_ERROR')->not->toContain(K::APNS_TOKEN);
});

it('records for every catalog reason the HTTP status Apple documents (shown by mobile-push:explain)', function (int $http, string $reason): void {
    expect(ApnsReasonCatalog::find($reason)?->documentedStatus)->toBe($http);
})->with('apns reasons');

it('decides a status without reason at the edges of the 4xx and 5xx classes', function (int $http, S $status, Code $code, bool $retryable, bool $throttle, ?int $minDelay): void {
    $decision = (new ApnsOutcomeClassifier)->classify(Exchange::responded(K::apnsRequest(), new Response($http)), K::apnsContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBeNull()
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->throttleProvider)->toBe($throttle)
        ->and($decision->minDelaySeconds)->toBe($minDelay)
        ->and($decision->httpStatus)->toBe($http);
})->with([
    '403 is a credential refusal' => [403, S::Failed, Code::AuthForbidden, false, false, null],
    '405 is a malformed request' => [405, S::Rejected, Code::RequestInvalid, false, false, null],
    '406 is not in the documented request errors' => [406, S::Rejected, Code::Unknown, false, false, null],
    '429 waits the device delay' => [429, S::Failed, Code::DestinationRateLimited, true, false, 60],
    '500 waits the 5xx delay' => [500, S::Failed, Code::ProviderInternal, true, false, 900],
    '503 throttles APNs and waits the 5xx delay' => [503, S::Failed, Code::ProviderUnavailable, true, true, 900],
    '502 is an undocumented 5xx' => [502, S::Failed, Code::Unknown, true, false, 900],
    '599 (last 5xx) is an undocumented 5xx' => [599, S::Failed, Code::Unknown, true, false, 900],
    '600 is not a provider answer' => [600, S::OutcomeUnknown, Code::ResponseUnparseable, true, false, null],
    '399 is not a provider answer' => [399, S::OutcomeUnknown, Code::ResponseUnparseable, true, false, null],
]);

it('decides an unknown reason by HTTP class, at the edges 399/400, 499/500 and 599/600', function (int $http, S $status, Code $code, bool $retryable, ?int $minDelay): void {
    $decision = (new ApnsOutcomeClassifier)->classify(K::apns($http, 'BrandNewReason2027'), K::apnsContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBe('BrandNewReason2027')
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->minDelaySeconds)->toBe($minDelay)
        ->and($decision->throttleProvider)->toBeFalse();
})->with([
    '302' => [302, S::OutcomeUnknown, Code::ResponseUnparseable, true, null],
    '399' => [399, S::OutcomeUnknown, Code::ResponseUnparseable, true, null],
    '400' => [400, S::Rejected, Code::Unknown, false, null],
    '429 waits the device delay' => [429, S::Failed, Code::Unknown, true, 60],
    '499' => [499, S::Rejected, Code::Unknown, false, null],
    '500' => [500, S::Failed, Code::Unknown, true, 900],
    '599' => [599, S::Failed, Code::Unknown, true, 900],
    '600' => [600, S::OutcomeUnknown, Code::ResponseUnparseable, true, null],
]);

it('reads a 410 timestamp written as a JSON float or string only when it is a positive millisecond count', function (string $timestamp, ?int $expected): void {
    // Raw JSON on purpose: json_encode() writes 1.0 as 1, so a PHP float would reach the classifier as an int.
    $body = '{"reason":"Unregistered","timestamp":'.$timestamp.'}';
    $decision = (new ApnsOutcomeClassifier)->classify(Exchange::responded(K::apnsRequest(), new Response(410, ['apns-id' => K::APNS_ID], $body)), K::apnsContext());

    expect($decision->status)->toBe(S::Rejected)
        ->and($decision->destinationAction)->toBe(Dest::Invalidate)
        ->and($decision->invalidatedAtMs)->toBe($expected);
})->with([
    'float with a fraction' => ['1763632800123.5', 1763632800123],
    'float 1.0 (smallest positive whole ms)' => ['1.0', 1],
    'float 0.0' => ['0.0', null],
    'float below one millisecond is no timestamp, like 0' => ['0.5', null],
    'negative float above -1' => ['-0.5', null],
    'float at the 9.2e18 cap' => ['9.2e18', null],
    'float just below the cap' => ['9.1e18', 9100000000000000000],
    'string "1" (smallest positive)' => ['"1"', 1],
    'string "0"' => ['"0"', null],
    'string with a fraction' => ['"1.5"', null],
]);
