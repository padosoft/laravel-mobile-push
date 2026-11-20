<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\Outcome\FcmOutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\CredentialAction as Cred;
use Padosoft\MobilePush\Core\Outcome\DestinationAction as Dest;
use Padosoft\MobilePush\Core\Outcome\ReasonCode as Code;
use Padosoft\MobilePush\Core\Outcome\SendStatus as S;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Outcome\OutcomeKit as K;
use Padosoft\MobilePush\Tests\Support\FakeClock;

dataset('fcm error codes', [
    // [http, errorCode, rpc status, SendStatus, code, retryable, dest, cred, throttle, minDelay]
    'INVALID_ARGUMENT' => [400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', S::Rejected, Code::PayloadInvalid, false, Dest::None, Cred::None, false, null],
    'UNREGISTERED' => [404, 'UNREGISTERED', 'NOT_FOUND', S::Rejected, Code::DestinationUnregistered, false, Dest::Invalidate, Cred::None, false, null],
    'SENDER_ID_MISMATCH' => [403, 'SENDER_ID_MISMATCH', 'PERMISSION_DENIED', S::Rejected, Code::DestinationMismatch, false, Dest::Quarantine, Cred::None, false, null],
    'QUOTA_EXCEEDED' => [429, 'QUOTA_EXCEEDED', 'RESOURCE_EXHAUSTED', S::Failed, Code::QuotaExceeded, true, Dest::None, Cred::None, true, 60],
    'UNAVAILABLE' => [503, 'UNAVAILABLE', 'UNAVAILABLE', S::Failed, Code::ProviderUnavailable, true, Dest::None, Cred::None, true, 60],
    'INTERNAL' => [500, 'INTERNAL', 'INTERNAL', S::Failed, Code::ProviderInternal, true, Dest::None, Cred::None, false, null],
    'THIRD_PARTY_AUTH_ERROR' => [401, 'THIRD_PARTY_AUTH_ERROR', 'UNAUTHENTICATED', S::Rejected, Code::AuthThirdParty, false, Dest::None, Cred::None, false, null],
    'APNS_AUTH_ERROR (legacy)' => [401, 'APNS_AUTH_ERROR', 'UNAUTHENTICATED', S::Rejected, Code::AuthThirdParty, false, Dest::None, Cred::None, false, null],
]);

it('reads the FcmError detail first, whatever error.status says', function (int $http, string $errorCode, string $rpc, S $status, Code $code, bool $retryable, Dest $dest, Cred $cred, bool $throttle, ?int $minDelay): void {
    $decision = (new FcmOutcomeClassifier(new FakeClock))->classify(K::fcm($http, $errorCode, $rpc), K::fcmContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBe($errorCode)
        ->and($decision->reason->hint)->toBeString()->not->toBeEmpty()
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->destinationAction)->toBe($dest)
        ->and($decision->credentialAction)->toBe($cred)
        ->and($decision->throttleProvider)->toBe($throttle)
        ->and($decision->minDelaySeconds)->toBe($minDelay)
        ->and($decision->httpStatus)->toBe($http);
})->with('fcm error codes');

it('falls back to error.status only without an FcmError detail', function (int $http, string $rpc, S $status, Code $code, bool $retryable, Dest $dest, Cred $cred): void {
    $decision = (new FcmOutcomeClassifier(new FakeClock))->classify(K::fcm($http, null, $rpc), K::fcmContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBe($rpc)
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->destinationAction)->toBe($dest)
        ->and($decision->credentialAction)->toBe($cred);
})->with([
    'UNAUTHENTICATED: our OAuth token' => [401, 'UNAUTHENTICATED', S::Failed, Code::AuthTokenExpired, true, Dest::None, Cred::RefreshToken],
    'PERMISSION_DENIED' => [403, 'PERMISSION_DENIED', S::Failed, Code::AuthForbidden, false, Dest::None, Cred::ReportAuthFailure],
    'INVALID_ARGUMENT never invalidates' => [400, 'INVALID_ARGUMENT', S::Rejected, Code::PayloadInvalid, false, Dest::None, Cred::None],
    'NOT_FOUND alone never invalidates' => [404, 'NOT_FOUND', S::Rejected, Code::DestinationUnregistered, false, Dest::None, Cred::None],
    'RESOURCE_EXHAUSTED' => [429, 'RESOURCE_EXHAUSTED', S::Failed, Code::QuotaExceeded, true, Dest::None, Cred::None],
    'UNAVAILABLE' => [503, 'UNAVAILABLE', S::Failed, Code::ProviderUnavailable, true, Dest::None, Cred::None],
    'INTERNAL' => [500, 'INTERNAL', S::Failed, Code::ProviderInternal, true, Dest::None, Cred::None],
    'DEADLINE_EXCEEDED may have been processed' => [504, 'DEADLINE_EXCEEDED', S::OutcomeUnknown, Code::ProviderUnavailable, true, Dest::None, Cred::None],
]);

it('accepts a 200 with the message name, or validates under validate_only', function (): void {
    $classifier = new FcmOutcomeClassifier(new FakeClock);

    $sent = $classifier->classify(K::fcm(200), K::fcmContext());
    $validated = $classifier->classify(K::fcm(200), K::fcmContext(validateOnly: true));

    expect($sent->status)->toBe(S::Accepted)
        ->and($sent->providerMessageId)->toBe('projects/example-project/messages/0:1700000000000000%abc')
        ->and($validated->status)->toBe(S::Validated)
        ->and($validated->reason->code)->toBe(Code::Validated);
});

it('honours Retry-After in delta-seconds and HTTP-date form, never below one minute for quota', function (string $errorCode, ?string $retryAfter, ?string $date, ?int $expected): void {
    $headers = array_filter(['retry-after' => $retryAfter, 'date' => $date], static fn (?string $v): bool => $v !== null);
    $classifier = new FcmOutcomeClassifier(new FakeClock('2026-11-20T10:00:00Z'));

    $decision = $classifier->classify(K::fcm($errorCode === 'QUOTA_EXCEEDED' ? 429 : ($errorCode === 'INTERNAL' ? 500 : 503), $errorCode, headers: $headers), K::fcmContext());

    expect($decision->minDelaySeconds)->toBe($expected);
})->with([
    'quota, none' => ['QUOTA_EXCEEDED', null, null, 60],
    'quota, 30 s raised to 60' => ['QUOTA_EXCEEDED', '30', null, 60],
    'quota, 120 s' => ['QUOTA_EXCEEDED', '120', null, 120],
    'quota, HTTP-date vs clock' => ['QUOTA_EXCEEDED', 'Fri, 20 Nov 2026 10:05:00 GMT', null, 300],
    'quota, HTTP-date vs Date header' => ['QUOTA_EXCEEDED', 'Fri, 20 Nov 2026 12:05:00 GMT', 'Fri, 20 Nov 2026 12:02:00 GMT', 180],
    'quota, date in the past' => ['QUOTA_EXCEEDED', 'Fri, 20 Nov 2026 09:00:00 GMT', null, 60],
    'quota, garbage' => ['QUOTA_EXCEEDED', 'soon', null, 60],
    'unavailable, 90 s' => ['UNAVAILABLE', '90', null, 90],
    'unavailable, RFC 850 date' => ['UNAVAILABLE', 'Friday, 20-Nov-26 10:02:00 GMT', null, 120],
    'unavailable, asctime date' => ['UNAVAILABLE', 'Fri Nov 20 10:03:00 2026', null, 180],
    'internal, none' => ['INTERNAL', null, null, null],
    'internal, 7 s' => ['INTERNAL', '7', null, 7],
]);

it('never copies error.message (it can echo the token) into the decision', function (): void {
    $message = 'The registration token is not a valid FCM registration token: '.K::FCM_TOKEN;
    $classifier = new FcmOutcomeClassifier(new FakeClock);

    foreach ([K::fcm(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', message: $message), K::fcm(400, null, null, message: $message), K::fcm(404, 'NEW_CODE', 'NOT_FOUND', message: $message)] as $exchange) {
        $decision = $classifier->classify($exchange, K::fcmContext());
        expect(serialize($decision))->not->toContain(K::FCM_TOKEN)->not->toContain('not a valid FCM');
    }
});

it('suppresses destination actions for topics and conditions', function (string $errorCode, int $http): void {
    $classifier = new FcmOutcomeClassifier(new FakeClock);

    foreach ([K::fcmTopic(), K::fcmCondition()] as $destination) {
        $decision = $classifier->classify(K::fcm($http, $errorCode), K::fcmContext($destination));
        expect($decision->status)->toBe(S::Rejected)->and($decision->destinationAction)->toBe(Dest::None);
    }
})->with([['UNREGISTERED', 404], ['SENDER_ID_MISMATCH', 403]]);

it('keeps an unknown error code and decides by HTTP class, ignoring a known error.status', function (int $http, S $status, bool $retryable): void {
    $decision = (new FcmOutcomeClassifier(new FakeClock))->classify(K::fcm($http, 'UNSPECIFIED_ERROR', 'INVALID_ARGUMENT'), K::fcmContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe(Code::Unknown)
        ->and($decision->reason->rawReason)->toBe('UNSPECIFIED_ERROR')
        ->and($decision->retryable)->toBe($retryable)
        ->and($decision->destinationAction)->toBe(Dest::None);
})->with([[400, S::Rejected, false], [429, S::Failed, true], [500, S::Failed, true]]);

it('classifies by HTTP status alone when the body is empty or not JSON', function (int $http, string $body, S $status, Code $code, Cred $cred): void {
    $exchange = Exchange::responded(K::fcmRequest(), new Response($http, [], $body));
    $decision = (new FcmOutcomeClassifier(new FakeClock))->classify($exchange, K::fcmContext());

    expect($decision->status)->toBe($status)
        ->and($decision->reason->code)->toBe($code)
        ->and($decision->reason->rawReason)->toBeNull()
        ->and($decision->credentialAction)->toBe($cred)
        ->and($decision->destinationAction)->toBe(Dest::None);
})->with([
    '400' => [400, '', S::Rejected, Code::PayloadInvalid, Cred::None],
    '401' => [401, '<html>', S::Failed, Code::AuthTokenExpired, Cred::RefreshToken],
    '403' => [403, '', S::Failed, Code::AuthForbidden, Cred::ReportAuthFailure],
    '404' => [404, '{"error":"x"}', S::Rejected, Code::DestinationUnregistered, Cred::None],
    '429' => [429, '', S::Failed, Code::QuotaExceeded, Cred::None],
    '500' => [500, '', S::Failed, Code::ProviderInternal, Cred::None],
    '502' => [502, '', S::Failed, Code::Unknown, Cred::None],
    '503' => [503, '', S::Failed, Code::ProviderUnavailable, Cred::None],
    '504' => [504, '', S::OutcomeUnknown, Code::ProviderUnavailable, Cred::None],
    '409' => [409, '', S::Rejected, Code::Unknown, Cred::None],
    '301' => [301, '', S::OutcomeUnknown, Code::ResponseUnparseable, Cred::None],
    'details not a list' => [400, '{"error":{"details":"x","status":7}}', S::Rejected, Code::PayloadInvalid, Cred::None],
]);

it('maps transport evidence the same way as APNs', function (): void {
    $classifier = new FcmOutcomeClassifier(new FakeClock);
    $notSent = $classifier->classify(K::notSent(K::fcmRequest()), K::fcmContext());
    $unknown = $classifier->classify(K::unknown(K::fcmRequest()), K::fcmContext());

    expect($notSent->status)->toBe(S::Failed)
        ->and($notSent->reason->code)->toBe(Code::TransportNotSent)
        ->and($notSent->retryable)->toBeTrue()
        ->and($unknown->status)->toBe(S::OutcomeUnknown)
        ->and($unknown->reason->code)->toBe(Code::TransportUnknown)
        ->and($unknown->retryable)->toBeTrue()
        ->and($unknown->destinationAction)->toBe(Dest::None)
        ->and($unknown->credentialAction)->toBe(Cred::None);
});
