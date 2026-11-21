<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\Frequency\StoreFailurePolicy;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\InterleavingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\PolicyKit;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\SilentDeadGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\ThrowingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

const TABLE = [
    'default' => ['per_hour' => 5, 'per_day' => 20],
    'marketing' => ['per_day' => 3],
    'security' => 'unlimited',
];

function promo(): PushMessage
{
    return PushMessage::make('Sale', 'Today only')->policy('marketing');
}

it('spends one unit for one person with three devices and sends to all three', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u1'), PolicyKit::device(3, 'u1')];

    [$transport, $results] = $kit->deliver($gate, promo(), $devices, 'promo-1');

    expect($transport->sentCount())->toBe(3)
        ->and(PolicyKit::statuses($results))->toBe(['accepted', 'accepted', 'accepted']);

    // The unit is spent once: the next effect of that user is capped on all three devices, not "two left".
    [$second, $capped] = $kit->deliver($gate, promo(), $devices, 'promo-2');
    expect($second->sentCount())->toBe(0)
        ->and(PolicyKit::statuses($capped))->toBe(['skipped', 'skipped', 'skipped']);
});

it('skips the fourth promo of the day on every device of the user, but not an order update', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(TABLE + ['orders' => 'unlimited']);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u1')];

    foreach (['p1', 'p2', 'p3'] as $effect) {
        [$transport] = $kit->deliver($gate, promo(), $devices, $effect);
        expect($transport->sentCount())->toBe(2);
        $kit->clock->advance(600);
    }

    [$transport, $results] = $kit->deliver($gate, promo(), $devices, 'p4');
    expect($transport->sentCount())->toBe(0)
        ->and(PolicyKit::statuses($results))->toBe(['skipped', 'skipped'])
        ->and($results[0]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
        ->and($results[1]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
        ->and($results[0]->reason->hint)->toContain('marketing')->toContain('3 per day')
        ->and($results[0]->nextAttemptAt)->toBeNull();

    [$orders] = $kit->deliver($gate, PushMessage::make('Shipped')->policy('orders'), $devices, 'order-1');
    expect($orders->sentCount())->toBe(2);
});

it('gives a retry of the same effect the same decision and consumes nothing', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 2]]);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u1')];

    $kit->deliver($gate, promo(), $devices, 'p1');
    $kit->deliver($gate, promo(), $devices, 'p2');
    [$third, $cappedFirst] = $kit->deliver($gate, promo(), $devices, 'p3');
    expect($third->sentCount())->toBe(0);

    // A continuation of the allowed effect p1 is still allowed, and does not use up p3's place or anyone else's.
    [$again] = $kit->deliver($gate, promo(), $devices, 'p1');
    expect($again->sentCount())->toBe(2);

    // The capped effect stays capped on retry, even once the window has room again for a NEW effect.
    $kit->clock->advance(3600 * 24 + 60);
    [$fresh] = $kit->deliver($gate, promo(), $devices, 'p5');
    expect($fresh->sentCount())->toBe(2);
    [$retry, $stillCapped] = $kit->deliver($gate, promo(), $devices, 'p3');
    expect($retry->sentCount())->toBe(0)
        ->and(PolicyKit::statuses($stillCapped))->toBe(['skipped', 'skipped'])
        ->and($stillCapped[0]->reason->hint)->toBe($cappedFirst[0]->reason->hint);

    // And p1 is still "the effect that was allowed", not a new one that would be capped by p2 and p5.
    [$retryAllowed] = $kit->deliver($gate, promo(), $devices, 'p1');
    expect($retryAllowed->sentCount())->toBe(2);
});

it('lets a security alert through an exhausted bucket with bypassFrequencyCap', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $devices = [PolicyKit::device(1, 'u1')];
    $kit->deliver($gate, promo(), $devices, 'p1');

    [$blocked] = $kit->deliver($gate, promo(), $devices, 'p2');
    [$bypassed] = $kit->deliver($gate, promo()->bypassFrequencyCap(), $devices, 'p3');

    expect($blocked->sentCount())->toBe(0)->and($bypassed->sentCount())->toBe(1);

    // The bypass spent nothing: p4 is still capped by p1, not by p3.
    $kit->clock->advance(3600 * 24 - 10);
    [$stillCapped] = $kit->deliver($gate, promo(), $devices, 'p4');
    expect($stillCapped->sentCount())->toBe(0);
});

it('counts a rolling window: capped after 23 hours, free again after 25', function (int $hours, int $expectedRequests) {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $devices = [PolicyKit::device(1, 'u1')];
    $kit->deliver($gate, promo(), $devices, 'first');

    $kit->clock->advance($hours * 3600);
    [$transport] = $kit->deliver($gate, promo(), $devices, 'next');

    expect($transport->sentCount())->toBe($expectedRequests);
})->with([
    'one hour later' => [1, 0],
    '23 hours later' => [23, 0],
    '25 hours later' => [25, 1],
]);

it('combines the per-hour and the per-day limit', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_hour' => 2, 'per_day' => 3]]);
    $devices = [PolicyKit::device(1, 'u1')];

    $sent = [];
    $plan = [
        ['a', 0], ['b', 600],   // two inside the hour
        ['c', 600],             // third inside the hour: capped by the hourly limit
        ['d', 3600],            // 70 minutes after the first: the hour has room, the day still has one unit
        ['e', 3600],            // the day is now full
    ];
    foreach ($plan as [$effect, $wait]) {
        $kit->clock->advance($wait);
        [$transport, $results] = $kit->deliver($gate, promo(), $devices, $effect);
        $sent[$effect] = [$transport->sentCount(), $results[0]->reason?->hint];
    }

    expect(array_map(static fn (array $s) => $s[0], $sent))->toBe(['a' => 1, 'b' => 1, 'c' => 0, 'd' => 1, 'e' => 0])
        ->and($sent['c'][1])->toContain('2 per hour')
        ->and($sent['e'][1])->toContain('3 per day');
});

it('does not let two workers both pass a limit of one for one user', function () {
    $kit = new PolicyKit;
    $shared = new InterleavingGuardStore($kit->store);
    $gate = $kit->gate(['marketing' => ['per_day' => 1]], store: $shared);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u1')];

    $secondWorker = [];
    $shared->beforeNextCompareAndSet = function () use ($kit, $gate, $devices, &$secondWorker): void {
        // Worker B runs its whole send for a different effect while worker A sits between read and write.
        $secondWorker = $kit->deliver($gate, promo(), $devices, 'effect-B');
    };
    [$workerA, $resultsA] = $kit->deliver($gate, promo(), $devices, 'effect-A');
    [$workerB] = $secondWorker;

    expect($workerB->sentCount() + $workerA->sentCount())->toBe(2)   // one person, two devices, ONE effect passed
        ->and([$workerA->sentCount(), $workerB->sentCount()])->toBe([0, 2])
        ->and(PolicyKit::statuses($resultsA))->toBe(['skipped', 'skipped']);
});

it('does not consume twice when two workers decide the same effect at once', function () {
    $kit = new PolicyKit;
    $shared = new InterleavingGuardStore($kit->store);
    $gate = $kit->gate(['marketing' => ['per_day' => 2]], store: $shared);
    $devices = [PolicyKit::device(1, 'u1')];

    $shared->beforeNextCompareAndSet = function () use ($kit, $gate, $devices): void {
        $kit->deliver($gate, promo(), $devices, 'same');
    };
    [$transport] = $kit->deliver($gate, promo(), $devices, 'same');
    expect($transport->sentCount())->toBe(1);

    // One unit was spent, not two: a second different effect still has room.
    [$other] = $kit->deliver($gate, promo(), $devices, 'other');
    expect($other->sentCount())->toBe(1);
});

it('never caps a topic, while a capped user is skipped in the same send', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $phone = fn (): FcmToken => SendKit::fcmToken(1)->withRef(new DestinationRef('dev-1', 1, 'App\\Models\\User', 'u1'));
    $kit->deliver($gate, promo(), [$phone()], 'p1', fcm: true);

    for ($i = 2; $i <= 4; $i++) {
        [$transport, $results] = $kit->deliver($gate, promo(), [$phone(), FcmTopic::of('news')], 'p'.$i, fcm: true);
        expect(PolicyKit::statuses($results))->toBe(['skipped', 'accepted'])
            ->and($results[0]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
            ->and($transport->sentCount())->toBe(1);
    }
});

it('does not even look at broadcast channels and conditions', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['default' => ['per_hour' => 1]], StoreFailurePolicy::Deny, store: new ThrowingGuardStore);
    $context = new GateContext(SendKit::apnsBinding(), promo(), TrafficClass::Bulk, $kit->clock->now(), 'e1');
    $items = [new SendItem(ApnsBroadcastChannel::of('dHN0LWNoYW5uZWw=')), new SendItem(FcmTopic::of('news')), new SendItem(FcmCondition::of("'a' in topics"))];

    expect($gate->check($context, $items))->toBe([]);
});

it('keeps two tenants with the same user id independent', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $devices = [PolicyKit::device(1, '42')];

    [$tenantA] = $kit->deliver($gate, promo(), $devices, 'p1', scope: 'tenant-a');
    [$tenantB] = $kit->deliver($gate, promo(), $devices, 'p1', scope: 'tenant-b');
    [$tenantAAgain] = $kit->deliver($gate, promo(), $devices, 'p2', scope: 'tenant-a');
    [$tenantBAgain] = $kit->deliver($gate, promo(), $devices, 'p2', scope: 'tenant-b');

    expect([$tenantA->sentCount(), $tenantB->sentCount(), $tenantAAgain->sentCount(), $tenantBAgain->sentCount()])->toBe([1, 1, 0, 0]);
});

it('treats a destination without an owner as its own subject, and different owner types as different people', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);

    $ownerless = [PolicyKit::device(1, null), PolicyKit::device(2, null)];
    [$first] = $kit->deliver($gate, promo(), $ownerless, 'p1');
    [$second] = $kit->deliver($gate, promo(), $ownerless, 'p2');
    // two ownerless devices are two subjects: each spends its own unit
    expect($first->sentCount())->toBe(2)->and($second->sentCount())->toBe(0);

    $user = [PolicyKit::device(3, '7')];
    $team = [PolicyKit::device(4, '7', 'App\\Models\\Team')];
    [$a] = $kit->deliver($gate, promo(), $user, 'p3');
    [$b] = $kit->deliver($gate, promo(), $team, 'p3');
    expect([$a->sentCount(), $b->sentCount()])->toBe([1, 1]);
});

it('lets messages without a policy use the default bucket, or none when configured so', function () {
    $kit = new PolicyKit;
    $devices = [PolicyKit::device(1, 'u1')];
    $plain = PushMessage::make('Hello');

    $capped = $kit->gate(['default' => ['per_hour' => 1]]);
    $kit->deliver($capped, $plain, $devices, 'e1');
    [$second] = $kit->deliver($capped, $plain, $devices, 'e2');

    $kit2 = new PolicyKit;
    $open = $kit2->gate(['default' => ['per_hour' => 1]], noPolicy: null);
    $kit2->deliver($open, $plain, $devices, 'e1');
    [$secondOpen] = $kit2->deliver($open, $plain, $devices, 'e2');

    expect($second->sentCount())->toBe(0)->and($secondOpen->sentCount())->toBe(1);
});

it('lets everything through when the cache is down and the policy is allow, and never throws', function (object $store) {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]], StoreFailurePolicy::Allow, store: $store);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u2')];

    [$transport, $results] = $kit->deliver($gate, promo(), $devices, 'p1');

    expect($transport->sentCount())->toBe(2)->and(PolicyKit::statuses($results))->toBe(['accepted', 'accepted']);
})->with([
    'throwing store' => [fn () => new ThrowingGuardStore],
    'silently dead store' => [fn () => new SilentDeadGuardStore],
]);

it('skips every individual destination when the cache is down and the policy is deny, topics still go', function (object $store) {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]], StoreFailurePolicy::Deny, store: $store);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u2')];

    [$transport, $results] = $kit->deliver($gate, promo(), $devices, 'p1');

    expect(PolicyKit::statuses($results))->toBe(['skipped', 'skipped'])
        ->and($results[0]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
        ->and($results[0]->reason->hint)->toContain('unavailable')
        ->and($results[1]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
        ->and($transport->sentCount())->toBe(0);
})->with([
    'throwing store' => [fn () => new ThrowingGuardStore],
    'silently dead store' => [fn () => new SilentDeadGuardStore],
]);

it('counts per call when there is no effect key, and says so by consuming again', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 2]]);
    $devices = [PolicyKit::device(1, 'u1')];

    foreach ([1, 2, 3] as $_) {
        [$transport] = $kit->deliver($gate, promo(), $devices, null);
        $counts[] = $transport->sentCount();
    }

    expect($counts)->toBe([1, 1, 0]);
});

it('stores neither the raw owner id nor the raw effect key', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $kit->deliver($gate, promo(), [PolicyKit::device(1, 'alice@example.com')], 'campaign-secret-key');

    $reflection = new ReflectionProperty($kit->store, 'data');
    $dump = serialize($reflection->getValue($kit->store));

    expect($dump)->not->toContain('alice')->and($dump)->not->toContain('campaign-secret-key')->and($dump)->not->toContain(SendKit::apnsToken(1)->value());
});
