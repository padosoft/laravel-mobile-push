<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\InterleavingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\PolicyKit;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\ThrowingGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/** What the integration step does: resolve once per owner and effect, then send that message to the owner's devices. */
function sendToOwner(PolicyKit $kit, BadgeResolver $resolver, PushMessage $message, string $owner, ?string $effect, array $devices): array
{
    $resolved = $resolver->resolve($message, 'default', Subject::owner('App\\Models\\User', $owner), $effect);
    $transport = new FakeTransport;
    $driver = $kit->send->apnsDriver();
    SendKit::all($kit->send->sender($transport)->send($driver, $resolved, $devices, SendKit::live(trafficClass: TrafficClass::Transactional, effectKey: $effect)));

    return array_map(static function (Request $r): ?int {
        $payload = json_decode($r->body, true);

        return is_array($payload) && isset($payload['aps']['badge']) ? (int) $payload['aps']['badge'] : null;
    }, $transport->requests());
}

function badgeOrder(): PushMessage
{
    return PushMessage::make('New order', 'You have a new order')->incrementBadge();
}

it('shows 1, 2, 3 for three unread orders, the same on both devices, and survives retries and a reset', function () {
    $kit = new PolicyKit;
    $counter = new BadgeCounter($kit->store, $kit->clock);
    $resolver = new BadgeResolver($counter);
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u1')];
    $subject = Subject::owner('App\\Models\\User', 'u1');

    expect(sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-1', $devices))->toBe([1, 1])
        ->and(sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-2', $devices))->toBe([2, 2])
        // a continuation / retry of the second effect says 2 again and counts nothing
        ->and(sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-2', $devices))->toBe([2, 2])
        ->and(sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-3', $devices))->toBe([3, 3])
        ->and($counter->current('default', $subject))->toBe(3);

    // the app was opened
    $counter->reset('default', $subject);
    expect($counter->current('default', $subject))->toBe(0)
        ->and(sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-4', $devices))->toBe([1, 1]);
});

it('resolves per device with the same result when the caller does not group by owner', function () {
    $kit = new PolicyKit;
    $resolver = new BadgeResolver(new BadgeCounter($kit->store, $kit->clock));

    $one = sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-1', [PolicyKit::device(1, 'u1')]);
    $two = sendToOwner($kit, $resolver, badgeOrder(), 'u1', 'order-1', [PolicyKit::device(2, 'u1')]);

    expect($one)->toBe([1])->and($two)->toBe([1]);
});

it('sends an explicit badge as given and leaves the counter alone', function () {
    $kit = new PolicyKit;
    $counter = new BadgeCounter($kit->store, $kit->clock);
    $resolver = new BadgeResolver($counter);
    $devices = [PolicyKit::device(1, 'u1')];
    $subject = Subject::owner('App\\Models\\User', 'u1');

    expect(sendToOwner($kit, $resolver, badgeOrder()->badge(7), 'u1', 'order-1', $devices))->toBe([7])
        ->and($counter->current('default', $subject))->toBe(0)
        ->and(sendToOwner($kit, $resolver, PushMessage::make('Hi')->badge(0), 'u1', 'order-2', $devices))->toBe([0])
        ->and(sendToOwner($kit, $resolver, PushMessage::make('No badge asked'), 'u1', 'order-3', $devices))->toBe([null])
        ->and($counter->current('default', $subject))->toBe(0);
});

it('does not touch the original message', function () {
    $kit = new PolicyKit;
    $resolver = new BadgeResolver(new BadgeCounter($kit->store, $kit->clock));
    $original = badgeOrder();

    $resolved = $resolver->resolve($original, 'default', 'o:x', 'e1');

    expect($resolved)->not->toBe($original)
        ->and($resolved->badge)->toBe(1)
        ->and($original->badge)->toBeNull()
        ->and($original->incrementBadge)->toBeTrue();
});

it('keeps one counter per owner, per scope and, when asked, per binding', function () {
    $kit = new PolicyKit;
    $counter = new BadgeCounter($kit->store, $kit->clock);
    $plain = new BadgeResolver($counter);
    $perBinding = new BadgeResolver($counter, perBinding: true);
    $ios = SendKit::apnsBinding('ios');
    $other = SendKit::apnsBinding('ios-beta');

    expect($plain->resolve(badgeOrder(), 'a', 'o:u', 'e1')->badge)->toBe(1)
        ->and($plain->resolve(badgeOrder(), 'b', 'o:u', 'e1')->badge)->toBe(1)       // another tenant
        ->and($plain->resolve(badgeOrder(), 'a', 'o:other', 'e1')->badge)->toBe(1)   // another owner
        ->and($plain->resolve(badgeOrder(), 'a', 'o:u', 'e2', $ios)->badge)->toBe(2) // bindings share it by default
        ->and($perBinding->resolve(badgeOrder(), 'c', 'o:u', 'e1', $ios)->badge)->toBe(1)
        ->and($perBinding->resolve(badgeOrder(), 'c', 'o:u', 'e2', $other)->badge)->toBe(1)
        ->and($perBinding->resolve(badgeOrder(), 'c', 'o:u', 'e3', $ios)->badge)->toBe(2);
});

it('loses no update when two workers increment at once', function () {
    $kit = new PolicyKit;
    $shared = new InterleavingGuardStore($kit->store);
    $counter = new BadgeCounter($shared, $kit->clock);
    $resolver = new BadgeResolver($counter);

    $workerB = null;
    $shared->beforeNextCompareAndSet = function () use ($resolver, &$workerB): void {
        $workerB = $resolver->resolve(badgeOrder(), 'default', 'o:u1', 'effect-B')->badge;
    };
    $workerA = $resolver->resolve(badgeOrder(), 'default', 'o:u1', 'effect-A')->badge;

    expect($workerB)->toBe(1)->and($workerA)->toBe(2)->and($counter->current('default', 'o:u1'))->toBe(2);
});

it('gives the same number to two workers racing on the same effect', function () {
    $kit = new PolicyKit;
    $shared = new InterleavingGuardStore($kit->store);
    $counter = new BadgeCounter($shared, $kit->clock);
    $resolver = new BadgeResolver($counter);

    $other = null;
    $shared->beforeNextCompareAndSet = function () use ($resolver, &$other): void {
        $other = $resolver->resolve(badgeOrder(), 'default', 'o:u1', 'same')->badge;
    };
    $mine = $resolver->resolve(badgeOrder(), 'default', 'o:u1', 'same')->badge;

    expect([$mine, $other])->toBe([1, 1])->and($counter->current('default', 'o:u1'))->toBe(1);
});

it('sends the message untouched when the counter store is down', function () {
    $kit = new PolicyKit;
    $resolver = new BadgeResolver(new BadgeCounter(new ThrowingGuardStore, $kit->clock));
    $message = badgeOrder();

    expect($resolver->resolve($message, 'default', 'o:u1', 'e1'))->toBe($message);
});

it('does not count again when the resolved message goes through the resolver a second time', function () {
    $kit = new PolicyKit;
    $counter = new BadgeCounter($kit->store, $kit->clock);
    $resolver = new BadgeResolver($counter);

    $resolved = $resolver->resolve(badgeOrder(), 'default', 'o:u1', 'e1');
    $again = $resolver->resolve($resolved, 'default', 'o:u1', 'e2');   // e.g. a continuation handed the resolved message

    expect($resolved->incrementBadge)->toBeFalse()
        ->and($again)->toBe($resolved)
        ->and($again->badge)->toBe(1)
        ->and($counter->current('default', 'o:u1'))->toBe(1);
});

it('keeps per-binding counters apart for different owners on the same connection', function () {
    $kit = new PolicyKit;
    $resolver = new BadgeResolver(new BadgeCounter($kit->store, $kit->clock), perBinding: true);
    $ios = SendKit::apnsBinding('ios');

    expect($resolver->resolve(badgeOrder(), 'default', 'o:alice', 'e1', $ios)->badge)->toBe(1)
        ->and($resolver->resolve(badgeOrder(), 'default', 'o:bob', 'e2', $ios)->badge)->toBe(1);
});

it('never merges the per-binding counters of two owner/connection pairs', function (string $ownerA, string $bindingA, string $ownerB, string $bindingB) {
    $kit = new PolicyKit;
    $resolver = new BadgeResolver(new BadgeCounter($kit->store, $kit->clock), perBinding: true);

    expect($resolver->resolve(badgeOrder(), 'default', $ownerA, 'e1', SendKit::apnsBinding($bindingA))->badge)->toBe(1)
        ->and($resolver->resolve(badgeOrder(), 'default', $ownerB, 'e2', SendKit::apnsBinding($bindingB))->badge)->toBe(1)
        ->and($resolver->subjectKey($ownerA, SendKit::apnsBinding($bindingA)))->not->toBe($resolver->subjectKey($ownerB, SendKit::apnsBinding($bindingB)));
})->with([
    'owner|binding boundary' => ['o:u', 'ios', 'o:ui', 'os'],
    'binding before owner' => ['o:u', 'ab', 'bo:u', 'a'],
]);

it('leaves the counter alone when the badge feature is off', function () {
    $kit = new PolicyKit;
    $counter = new BadgeCounter($kit->store, $kit->clock);
    $message = badgeOrder();

    expect((new BadgeResolver($counter, enabled: false))->resolve($message, 'default', 'o:u1', 'e1'))->toBe($message)
        ->and($counter->current('default', 'o:u1'))->toBe(0);
});
