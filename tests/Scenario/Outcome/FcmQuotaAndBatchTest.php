<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmOutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPlanner;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Outcome\OutcomeKit as K;
use Padosoft\MobilePush\Tests\Support\FakeClock;

$quota = static fn (array $headers = []): Response => new Response(429, $headers, (string) json_encode(['error' => [
    'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded for quota metric ...',
    'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'QUOTA_EXCEEDED']],
]]));

it('FCM quota exceeded: deferred at least one minute (or Retry-After), throttle signalled, then accepted', function (array $headers, int $minimumSeconds) use ($quota): void {
    $clock = new FakeClock('2026-11-20T10:00:00Z');
    $transport = (new FakeTransport)->push($quota($headers), new Response(200, [], '{"name":"projects/example-project/messages/1"}'));

    $plans = K::drive($transport, new FcmOutcomeClassifier($clock), K::fcmContext(), new RetryPlanner, $clock, K::fcmRequest());

    $waited = $plans[0]->nextAttemptAt?->getTimestamp() - (new DateTimeImmutable('2026-11-20T10:00:00Z'))->getTimestamp();

    expect($transport->requests())->toHaveCount(2)
        ->and($plans[0]->status)->toBe(SendStatus::Deferred)
        ->and($plans[0]->reason->code)->toBe(ReasonCode::QuotaExceeded)
        ->and($plans[0]->decision?->throttleProvider)->toBeTrue()
        ->and($waited)->toBeGreaterThanOrEqual($minimumSeconds)
        ->and(end($plans)->status)->toBe(SendStatus::Accepted)
        ->and(end($plans)->decision?->providerMessageId)->toBe('projects/example-project/messages/1');
})->with([
    'no Retry-After' => [[], 60],
    'Retry-After shorter than the minimum' => [['retry-after' => '10'], 60],
    'Retry-After 240 s' => [['retry-after' => '240'], 240],
    'Retry-After as HTTP-date' => [['retry-after' => 'Fri, 20 Nov 2026 10:07:00 GMT', 'date' => 'Fri, 20 Nov 2026 10:00:00 GMT'], 420],
]);

it('a mixed batch of ten exchanges yields ten independent decisions', function (): void {
    $answers = [
        'a-ok' => new Response(200, ['apns-id' => K::APNS_ID]),
        'a-410' => new Response(410, [], '{"reason":"Unregistered","timestamp":1763560800000}'),
        'a-bad' => new Response(400, [], '{"reason":"BadDeviceToken"}'),
        'a-lost' => new TransportFailure(TransportEvidence::Unknown, 92, 'stream reset'),
        'a-refused' => new TransportFailure(TransportEvidence::NotSent, 7, 'refused', false),
        'a-503' => new Response(503, [], '{"reason":"ServiceUnavailable"}'),
        'a-429' => new Response(429, [], '{"reason":"TooManyRequests"}'),
        'a-jwt' => new Response(403, [], '{"reason":"ExpiredProviderToken"}'),
        'a-new' => new Response(400, [], '{"reason":"SomethingNew"}'),
        'a-big' => new Response(413, [], '{"reason":"PayloadTooLarge"}'),
    ];
    $transport = (new FakeTransport)->respondUsing(static fn (Request $r) => $answers[$r->id]);
    $requests = array_map(static fn (string $id): Request => K::apnsRequest($id), array_keys($answers));
    $classifier = new ApnsOutcomeClassifier;
    $planner = new RetryPlanner;
    $now = new DateTimeImmutable('2026-11-20T10:00:00Z');

    $statuses = [];
    foreach ($transport->send($requests, new ConnectionProfile('test-pool')) as $id => $exchange) {
        $decision = $classifier->classify($exchange, K::apnsContext());
        $plan = $planner->plan($decision, RetryBudget::start($planner->policy(), $now), false, $now);
        $statuses[$id] = [$decision->status, $plan->status, $decision->destinationAction];
    }

    expect($transport->requests())->toHaveCount(10)
        ->and($statuses)->toBe([
            'a-ok' => [SendStatus::Accepted, SendStatus::Accepted, DestinationAction::None],
            'a-410' => [SendStatus::Rejected, SendStatus::Rejected, DestinationAction::Invalidate],
            'a-bad' => [SendStatus::Rejected, SendStatus::Rejected, DestinationAction::Quarantine],
            'a-lost' => [SendStatus::OutcomeUnknown, SendStatus::OutcomeUnknown, DestinationAction::None],
            'a-refused' => [SendStatus::Failed, SendStatus::Deferred, DestinationAction::None],
            'a-503' => [SendStatus::Failed, SendStatus::Deferred, DestinationAction::None],
            'a-429' => [SendStatus::Failed, SendStatus::Deferred, DestinationAction::None],
            'a-jwt' => [SendStatus::Failed, SendStatus::Deferred, DestinationAction::None],
            'a-new' => [SendStatus::Rejected, SendStatus::Rejected, DestinationAction::None],
            'a-big' => [SendStatus::Rejected, SendStatus::Rejected, DestinationAction::None],
        ]);
});
