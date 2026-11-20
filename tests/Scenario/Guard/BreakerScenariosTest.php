<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\AccountKey;
use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\BreakerTransition as T;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function scenarioGuards(FakeClock $clock): Guards
{
    $store = new InMemoryGuardStore($clock);

    return new Guards(
        new RateLimiter($store, $clock, null),
        new ThrottleGate($store, $clock),
        new AuthBreaker($store, $clock, 5, 60),
        new DestinationQualityBreaker($store, $clock),
        $clock,
    );
}

function scenarioBinding(): string
{
    return (new BindingRef('default', 'main', Provider::Apns, 'com.example.app', Environment::Production))->identity();
}

function scenarioDecision(SendStatus $status, ReasonCode $code = ReasonCode::None, CredentialAction $cred = CredentialAction::None, DestinationAction $dest = DestinationAction::None, bool $throttle = false, ?int $delay = null): Decision
{
    return new Decision($status, Reason::of($code), credentialAction: $cred, destinationAction: $dest, throttleProvider: $throttle, minDelaySeconds: $delay);
}

it('the APNs key was revoked: the breaker opens and 10,000 recipients never reach the provider', function () {
    $clock = new FakeClock;
    $g = scenarioGuards($clock);
    $binding = scenarioBinding();
    $account = AccountKey::of(Provider::Apns, 'TEAM123456');
    $auth = scenarioDecision(SendStatus::Rejected, ReasonCode::AuthTokenInvalid, CredentialAction::ReportAuthFailure);

    $opened = 0;
    for ($i = 0; $i < 5; $i++) {
        expect($g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1)->allowed)->toBe(1);
        $opened += $g->record($auth, $binding, 'apns-key', $account)->auth === T::Opened ? 1 : 0;
    }
    expect($opened)->toBe(1);

    $authorised = 0;
    for ($i = 0; $i < 10_000; $i++) {
        $v = $g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1);
        $authorised += $v->allowed;
        expect($v->skipReason)->toBe(ReasonCode::CircuitOpen);
    }
    expect($authorised)->toBe(0);

    $clock->advance(60);
    $probe = $g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1000);
    expect($probe->allowed)->toBe(1)->and($probe->authProbe)->toBeTrue()
        ->and($g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1)->allowed)->toBe(0);   // exactly one probe

    $closed = $g->record(scenarioDecision(SendStatus::Accepted), $binding, 'apns-key', $account);
    expect($closed->auth)->toBe(T::Closed)
        ->and($g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1000)->allowed)->toBe(1000);
});

it('rotation with a secondary key switches exactly once even with 50 concurrent failures', function () {
    $clock = new FakeClock;
    $g = scenarioGuards($clock);
    $binding = scenarioBinding();
    $account = AccountKey::of(Provider::Apns, 'TEAM123456');
    $auth = scenarioDecision(SendStatus::Rejected, ReasonCode::AuthForbidden, CredentialAction::ReportAuthFailure);

    $failovers = 0;
    $opens = 0;
    for ($i = 0; $i < 50; $i++) {   // all 50 were sent with generation 0 before anyone reacted
        $t = $g->record($auth, $binding, 'apns-key', $account, credentialGeneration: 0, hasSecondary: true)->auth;
        $failovers += $t === T::FailedOver ? 1 : 0;
        $opens += in_array($t, [T::Opened, T::Reopened], true) ? 1 : 0;
    }

    expect($failovers)->toBe(1)->and($opens)->toBe(0);
    $next = $g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 100);
    expect($next->allowed)->toBe(100)->and($next->credentialGeneration)->toBe(1);

    // A secondary that works keeps the breaker closed; one that fails like the first opens it.
    for ($i = 0; $i < 5; $i++) {
        $t = $g->record($auth, $binding, 'apns-key', $account, credentialGeneration: 1, hasSecondary: true)->auth;
    }
    expect($t)->toBe(T::Opened);
});

it('a burst of throttled token renewals neither opens the breaker nor fails over', function () {
    $g = scenarioGuards(new FakeClock);
    $binding = scenarioBinding();
    $account = AccountKey::of(Provider::Apns, 'TEAM123456');

    foreach ([CredentialAction::SlowTokenRefresh, CredentialAction::RefreshToken] as $action) {
        for ($i = 0; $i < 100; $i++) {
            $d = scenarioDecision(SendStatus::Failed, ReasonCode::AuthTokenThrottled, $action);
            expect($g->record($d, $binding, 'apns-key', $account, 0, true)->auth)->toBe(T::None);
        }
    }

    $v = $g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 500);
    expect($v->allowed)->toBe(500)->and($v->credentialGeneration)->toBe(0);
});

it('staging database with production tokens opens the destination breaker; a small sample does not', function () {
    $clock = new FakeClock;
    $g = scenarioGuards($clock);
    $binding = scenarioBinding();
    $account = AccountKey::of(Provider::Apns, 'TEAM123456');
    $bad = scenarioDecision(SendStatus::Rejected, ReasonCode::DestinationRejected, dest: DestinationAction::Quarantine);
    $good = scenarioDecision(SendStatus::Accepted);

    $other = (new BindingRef('default', 'small', Provider::Apns, 'com.example.small', Environment::Production))->identity();
    for ($i = 0; $i < 20; $i++) {
        $g->record($i < 15 ? $bad : $good, $other, 'k2', $account);
    }
    expect($g->before($other, 'k2', $account, TrafficClass::Bulk, 100)->allowed)->toBe(100);

    $opened = 0;
    for ($i = 0; $i < 500; $i++) {
        $opened += $g->record($i < 400 ? $bad : $good, $binding, 'apns-key', $account)->destination === T::Opened ? 1 : 0;
    }
    // Interleaving matters not: 400 bad then 100 good is above 0.5 at the 200th outcome already.
    expect($opened)->toBe(1);

    $blocked = $g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1000);
    expect($blocked->allowed)->toBe(0)->and($blocked->skipReason)->toBe(ReasonCode::CircuitOpen)
        ->and($g->before($binding, 'apns-key', $account, TrafficClass::Transactional, 5)->allowed)->toBe(5);

    $clock->advance(300);
    $probe = $g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1000);
    expect($probe->allowed)->toBe(20)->and($probe->transitions)->toContain(T::HalfOpened);
    for ($i = 0; $i < 20; $i++) {
        $last = $g->record($good, $binding, 'apns-key', $account)->destination;
    }
    expect($last)->toBe(T::Closed)
        ->and($g->before($binding, 'apns-key', $account, TrafficClass::Bulk, 1000)->allowed)->toBe(1000);
});

it('FCM says slow down: the account is paused for at least 60 seconds and never less', function () {
    $clock = new FakeClock;
    $g = scenarioGuards($clock);
    $binding = scenarioBinding();
    $project = AccountKey::of(Provider::Fcm, 'shop-prod');
    $other = AccountKey::of(Provider::Fcm, 'other-project');
    $start = $clock->now();

    $quota = scenarioDecision(SendStatus::Failed, ReasonCode::QuotaExceeded, throttle: true, delay: 5);
    $first = $g->record($quota, $binding, 'fcm-sa', $project);
    expect($first->throttledUntil?->getTimestamp())->toBe($start->getTimestamp() + 60);

    $clock->advance(10);
    $longer = scenarioDecision(SendStatus::Failed, ReasonCode::QuotaExceeded, throttle: true, delay: 120);
    $g->record($longer, $binding, 'fcm-sa', $project);
    $shorter = $g->record(scenarioDecision(SendStatus::Failed, ReasonCode::QuotaExceeded, throttle: true, delay: 1), $binding, 'fcm-sa', $project);

    expect($shorter->throttledUntil?->getTimestamp())->toBe($start->getTimestamp() + 10 + 120);
    $v = $g->before($binding, 'fcm-sa', $project, TrafficClass::Transactional, 10);
    expect($v->allowed)->toBe(0)->and($v->skipReason)->toBe(ReasonCode::QuotaExceeded)
        ->and($v->retryAt?->getTimestamp())->toBe($start->getTimestamp() + 130)
        ->and($g->before($binding, 'fcm-sa', $other, TrafficClass::Transactional, 10)->allowed)->toBe(10);

    $clock->advance(120);
    expect($g->before($binding, 'fcm-sa', $project, TrafficClass::Transactional, 10)->allowed)->toBe(10);
});
