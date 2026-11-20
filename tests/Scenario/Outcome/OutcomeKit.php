<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Outcome;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ClassifyContext;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPlan;
use Padosoft\MobilePush\Core\Retry\RetryPlanner;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/**
 * Builders shared by the outcome/retry tests (fake values only: no real token anywhere).
 */
final class OutcomeKit
{
    public const APNS_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public const APNS_ID = 'e2b4f1a0-0000-4000-8000-000000000001';

    public const FCM_TOKEN ='fake-fcm-registration-token:APA91bFAKE_value_for_tests_only';

    public static function apnsContext(?Destination $destination = null): ClassifyContext
    {
        return new ClassifyContext(
            $destination ?? ApnsDeviceToken::of(self::APNS_TOKEN),
            new BindingRef('default', 'ios', Provider::Apns, 'com.example.app', Environment::Production),
        );
    }

    public static function fcmContext(?Destination $destination = null, bool $validateOnly = false): ClassifyContext
    {
        return new ClassifyContext(
            $destination ?? FcmToken::of(self::FCM_TOKEN),
            new BindingRef('default', 'android', Provider::Fcm, 'example-project', Environment::Production),
            $validateOnly,
        );
    }

    public static function liveActivity(): ApnsLiveActivityToken
    {
        return ApnsLiveActivityToken::of(str_repeat('0f', 32));
    }

    public static function channel(): ApnsBroadcastChannel
    {
        return ApnsBroadcastChannel::of('dHN0LTI1Ni1ieXRlLWNoYW5uZWwtaWQ=');
    }

    public static function fcmTopic(): FcmTopic
    {
        return FcmTopic::of('news');
    }

    public static function fcmCondition(): FcmCondition
    {
        return FcmCondition::of("'news' in topics && 'sport' in topics");
    }

    public static function apnsRequest(string $id = 'r1'): Request
    {
        return new Request($id, 'POST', 'https://api.push.apple.com/3/device/'.self::APNS_TOKEN, ['apns-topic' => 'com.example.app'], '{"aps":{"alert":"hi"}}');
    }

    public static function fcmRequest(string $id = 'r1'): Request
    {
        return new Request($id, 'POST', 'https://fcm.googleapis.com/v1/projects/example-project/messages:send', [], '{"message":{"token":"'.self::FCM_TOKEN.'"}}');
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    public static function apns(int $status, ?string $reason = null, array $headers = [], array $extra = [], string $id = 'r1'): Exchange
    {
        $body = $reason === null && $extra === [] ? '' : (string) json_encode(array_filter(['reason' => $reason], static fn ($v) => $v !== null) + $extra);

        return Exchange::responded(self::apnsRequest($id), new Response($status, $headers + ['apns-id' => self::APNS_ID], $body));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function fcm(int $status, ?string $errorCode = null, ?string $rpcStatus = null, array $headers = [], string $message = 'Requested entity was not found.', string $id = 'r1'): Exchange
    {
        if ($status >= 200 && $status < 300) {
            return Exchange::responded(self::fcmRequest($id), new Response($status, $headers, '{"name":"projects/example-project/messages/0:1700000000000000%abc"}'));
        }

        $error = ['code' => $status, 'message' => $message];
        if ($rpcStatus !== null) {
            $error['status'] = $rpcStatus;
        }
        if ($errorCode !== null) {
            $error['details'] = [
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => []],
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode],
            ];
        }

        return Exchange::responded(self::fcmRequest($id), new Response($status, $headers, (string) json_encode(['error' => $error])));
    }

    public static function notSent(?Request $request = null): Exchange
    {
        return Exchange::failed($request ?? self::apnsRequest(), new TransportFailure(TransportEvidence::NotSent, 7, 'Failed to connect to api.push.apple.com port 443: Connection refused', false));
    }

    public static function unknown(?Request $request = null): Exchange
    {
        return Exchange::failed($request ?? self::apnsRequest(), new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream 1 was not closed cleanly: INTERNAL_ERROR'));
    }

    /**
     * Drive one destination the way the sender does: expiry check, one attempt on the transport, classification,
     * planning, wait (by moving the fake clock) until a terminal plan. Returns every plan in order.
     *
     * @return list<RetryPlan>
     */
    public static function drive(
        FakeTransport $transport,
        OutcomeClassifier $classifier,
        ClassifyContext $context,
        RetryPlanner $planner,
        FakeClock $clock,
        Request $request,
        bool $replaceable = false,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $budget = RetryBudget::start($planner->policy(), $clock->now(), $expiresAt);
        $plans = [];
        $lastCause = null;

        for ($guard = 0; $guard < 100; $guard++) {
            $stop = $planner->beforeAttempt($budget, $clock->now(), $lastCause);
            if ($stop !== null) {
                $plans[] = $stop;

                return $plans;
            }

            foreach ($transport->send([$request], new ConnectionProfile('test-pool')) as $exchange) {
                $plan = $planner->plan($classifier->classify($exchange, $context), $budget, $replaceable, $clock->now());
                $plans[] = $plan;
                $budget = $plan->budget;
                $lastCause = $plan->cause();

                if ($plan->isTerminal()) {
                    return $plans;
                }
                $clock->set($plan->nextAttemptAt ?? $clock->now());
            }
        }

        throw new \LogicException('The retry loop did not terminate.');
    }

    /**
     * @param  list<RetryPlan>  $plans
     * @return list<Decision>
     */
    public static function decisions(array $plans): array
    {
        return array_values(array_filter(array_map(static fn (RetryPlan $p): ?Decision => $p->decision, $plans)));
    }
}
