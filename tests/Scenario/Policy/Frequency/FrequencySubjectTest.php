<?php

declare(strict_types=1);

require_once __DIR__.'/PolicyKit.php';

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCapGate;
use Padosoft\MobilePush\Core\Policy\Frequency\StoreFailurePolicy;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\BusyGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\PolicyKit;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\ThrowingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\UnindexableDestination;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Who counts as one person for the cap, and which edges of a send the gate must get right: the oracle is what the
 * transport received and the public SendResults, or, for destinations the Sender cannot carry, the gate's denials.
 */

function marketingPush(string $policy = 'marketing'): PushMessage
{
    return PushMessage::make('Sale', 'Today only')->policy($policy);
}

/** @param  list<Destination>  $destinations @return array<int, \Padosoft\MobilePush\Core\Driver\GateDenial> */
function checkGate(FrequencyCapGate $gate, array $destinations, ?string $effectKey, ?Message $message = null): array
{
    $context = new GateContext(SendKit::apnsBinding(), $message ?? marketingPush(), TrafficClass::Bulk, new DateTimeImmutable('2026-11-20T10:00:00Z'), $effectKey);

    return $gate->check($context, array_map(static fn (Destination $d): SendItem => new SendItem($d), $destinations));
}

function deviceWithRef(int $i, ?string $ownerType, ?string $ownerId): Destination
{
    return SendKit::apnsToken($i)->withRef(new DestinationRef('dev-'.$i, 1, $ownerType, $ownerId));
}

it('counts the phones of one user as one person even when each effect reaches a different phone', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);

    [$first] = $kit->deliver($gate, marketingPush(), [PolicyKit::device(1, 'u1')], 'p1');
    [$second, $results] = $kit->deliver($gate, marketingPush(), [PolicyKit::device(2, 'u1')], 'p2');

    expect($first->sentCount())->toBe(1)
        ->and($second->sentCount())->toBe(0)
        ->and(PolicyKit::statuses($results))->toBe(['skipped']);
});

it('counts two devices without an owner as two people', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);

    [$first] = $kit->deliver($gate, marketingPush(), [PolicyKit::device(1, null)], 'p1');
    [$second] = $kit->deliver($gate, marketingPush(), [PolicyKit::device(2, null)], 'p2');
    [$third] = $kit->deliver($gate, marketingPush(), [PolicyKit::device(1, null)], 'p3');

    expect([$first->sentCount(), $second->sentCount(), $third->sentCount()])->toBe([1, 1, 0]);
});

it('treats a device whose owner is half known as its own subject', function (?string $ownerType, ?string $ownerId) {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);

    [$first] = $kit->deliver($gate, marketingPush(), [deviceWithRef(1, $ownerType, $ownerId)], 'p1');
    [$sameDevice] = $kit->deliver($gate, marketingPush(), [deviceWithRef(1, $ownerType, $ownerId)], 'p2');
    [$otherDevice] = $kit->deliver($gate, marketingPush(), [deviceWithRef(2, $ownerType, $ownerId)], 'p3');

    expect([$first->sentCount(), $sameDevice->sentCount(), $otherDevice->sentCount()])->toBe([1, 0, 1]);
})->with([
    'type without id' => ['App\\Models\\User', null],
    'id without type' => [null, '7'],
]);

it('tells owners apart by type and id, however the strings line up', function (array $a, array $b) {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);

    [$first] = $kit->deliver($gate, marketingPush(), [deviceWithRef(1, $a[0], $a[1])], 'p1');
    [$second] = $kit->deliver($gate, marketingPush(), [deviceWithRef(2, $b[0], $b[1])], 'p2');

    expect([$first->sentCount(), $second->sentCount()])->toBe([1, 1])
        ->and(Subject::owner($a[0], $a[1]))->not->toBe(Subject::owner($b[0], $b[1]));
})->with([
    'same id, another type of the same length' => [['App\\Models\\User', '7'], ['App\\Models\\Team', '7']],
    'same type, another id' => [['App\\Models\\User', '7'], ['App\\Models\\User', '8']],
    'type|id boundary' => [['App\\User:1', '2'], ['App\\User', '1:2']],
]);

it('caps a person listed after a topic in the same send', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    $phone = SendKit::fcmToken(1)->withRef(new DestinationRef('dev-1', 1, 'App\\Models\\User', 'u1'));
    $kit->deliver($gate, marketingPush(), [$phone], 'p1', fcm: true);

    [$transport, $results] = $kit->deliver($gate, marketingPush(), [FcmTopic::of('news'), $phone], 'p2', fcm: true);

    expect(PolicyKit::statuses($results))->toBe(['accepted', 'skipped'])
        ->and($transport->sentCount())->toBe(1);
});

it('says exactly which policy and limit capped the destination', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 2]]);
    $devices = [PolicyKit::device(1, 'u1')];
    $kit->deliver($gate, marketingPush(), $devices, 'p1');
    $kit->deliver($gate, marketingPush(), $devices, 'p2');

    [, $results] = $kit->deliver($gate, marketingPush(), $devices, 'p3');

    expect($results[0]->status)->toBe(SendStatus::Skipped)
        ->and($results[0]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
        ->and($results[0]->reason->hint)->toBe('Frequency cap for policy "marketing": at most 2 per day.');
});

it('reads the policy name without the spaces around it', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);   // no default: an unknown bucket is unlimited
    $devices = [PolicyKit::device(1, 'u1')];

    [$first] = $kit->deliver($gate, marketingPush(' marketing '), $devices, 'p1');
    [$second, $results] = $kit->deliver($gate, marketingPush("marketing\n"), $devices, 'p2');

    expect([$first->sentCount(), $second->sentCount()])->toBe([1, 0])
        ->and($results[0]->reason->hint)->toBe('Frequency cap for policy "marketing": at most 1 per day.');
});

it('treats a blank policy as no policy: not capped when messages without a policy are not capped', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['default' => ['per_hour' => 1]], StoreFailurePolicy::Deny, noPolicy: null, store: new ThrowingGuardStore);

    expect(checkGate($gate, [PolicyKit::device(1, 'u1')], 'p1', marketingPush('   ')))->toBe([])
        ->and(checkGate($gate, [PolicyKit::device(1, 'u1')], 'p1', PushMessage::make('Hello')))->toBe([]);
});

it('counts a blank policy in the no-policy bucket when there is one', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['default' => ['per_hour' => 1]]);
    $devices = [PolicyKit::device(1, 'u1')];

    [$first] = $kit->deliver($gate, marketingPush('  '), $devices, 'p1');
    [$second] = $kit->deliver($gate, PushMessage::make('Plain'), $devices, 'p2');

    expect([$first->sentCount(), $second->sentCount()])->toBe([1, 0]);
});

it('caps a native provider message in the no-policy bucket', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['default' => ['per_hour' => 1]]);
    $native = ApnsMessage::alert('Native', 'No PushMessage');
    $device = [PolicyKit::device(1, 'u1')];

    expect(checkGate($gate, $device, 'n1', $native))->toBe([]);
    $denials = checkGate($gate, $device, 'n2', $native);
    expect($denials)->toHaveCount(1)
        ->and($denials[0]->reason->hint)->toBe('Frequency cap for policy "default": at most 1 per hour.');
});

it('never looks at the store for an unlimited bucket, even with fail-closed and an unindexable destination', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => 'unlimited', 'default' => ['per_hour' => 1]], StoreFailurePolicy::Deny, store: new ThrowingGuardStore);

    expect(checkGate($gate, [new UnindexableDestination, PolicyKit::device(1, 'u1')], 'p1'))->toBe([]);
});

it('fails an unindexable destination closed on its own and still caps the person next to it', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]], StoreFailurePolicy::Deny);
    checkGate($gate, [PolicyKit::device(2, 'u1')], 'p1');

    $denials = checkGate($gate, [new UnindexableDestination, PolicyKit::device(2, 'u1'), new UnindexableDestination], 'p2');

    expect(array_keys($denials))->toBe([0, 1, 2])
        ->and($denials[0]->reason->hint)->toBe('Frequency counters unavailable; failing closed.')
        ->and($denials[2]->reason->hint)->toBe('Frequency counters unavailable; failing closed.')
        ->and($denials[1]->reason->hint)->toBe('Frequency cap for policy "marketing": at most 1 per day.');
});

it('lets an unindexable destination through with fail-open, and still caps the person next to it', function () {
    $kit = new PolicyKit;
    $gate = $kit->gate(['marketing' => ['per_day' => 1]]);
    checkGate($gate, [PolicyKit::device(2, 'u1')], 'p1');

    $denials = checkGate($gate, [new UnindexableDestination, PolicyKit::device(2, 'u1')], 'p2');

    expect(array_keys($denials))->toBe([1])
        ->and($denials[1]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped);
});

it('defers only the person whose counter is contended, and still caps the next one', function () {
    $kit = new PolicyKit;
    $inner = new InMemoryGuardStore($kit->clock);
    checkGate($kit->gate(['marketing' => ['per_day' => 1]], store: $inner), [PolicyKit::device(2, 'bob')], 'earlier');
    // The first 32 writes lose: exactly the default attempts of the first person; the second person's go through.
    $gate = $kit->gate(['marketing' => ['per_day' => 1]], store: new BusyGuardStore($inner, losses: 32));

    $denials = checkGate($gate, [PolicyKit::device(1, 'alice'), PolicyKit::device(2, 'bob')], 'p1');

    expect(array_keys($denials))->toBe([0, 1])
        ->and($denials[0]->status)->toBe(SendStatus::Deferred)
        ->and($denials[0]->reason->code)->toBe(ReasonCode::QuotaExceeded)
        ->and($denials[1]->status)->toBe(SendStatus::Skipped)
        ->and($denials[1]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped);
});
