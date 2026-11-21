<?php

declare(strict_types=1);

use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Notifications\FallbackPolicy;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * Long-lived runtimes (Octane): whatever a request registers on the shared manager it can remove again.
 */
it('restores the default scope when the scope resolver is cleared, by null or by forgetScopeResolver()', function () {
    W::install(['scope' => 'main']);
    $manager = app(PushManager::class);
    expect($manager->currentScope())->toBe('main');

    $manager->scopeUsing(static fn (): string => 'tenant-a');
    expect($manager->currentScope())->toBe('tenant-a');
    $manager->scopeUsing(null);
    expect($manager->currentScope())->toBe('main');

    MobilePush::scopeUsing(static fn (): string => 'tenant-b');
    expect(MobilePush::currentScope())->toBe('tenant-b');
    MobilePush::forgetScopeResolver();
    expect(MobilePush::currentScope())->toBe('main');
});

it('stops resolving connections through a connection resolver that was forgotten', function () {
    W::install(['connections' => []]);
    $manager = app(PushManager::class);
    $manager->resolveConnectionUsing(static fn (string $name, string $scope): ?array => $name === 'ios' ? W::apns() : null);
    expect($manager->providerOf('default', 'ios')->value)->toBe('apns');

    $manager->forgetConnectionResolver();

    expect(fn () => $manager->providerOf('default', 'ios'))->toThrow(UnknownConnectionException::class);
});

it('removes a fallback check by its callable, counting what it removed, and flushes the rest', function () {
    $policy = new FallbackPolicy;
    $notification = new class extends Notification {};
    $deny = static fn (): bool => false;
    $other = static fn (): bool => true;
    $policy->allowUsing($deny);
    $policy->allowUsing($other);
    $policy->allowUsing($deny);

    expect($policy->allows(new stdClass, $notification, 'mail'))->toBeFalse()
        ->and($policy->count())->toBe(3)
        ->and($policy->forget($deny))->toBe(2)
        ->and($policy->forget($deny))->toBe(0)
        ->and($policy->count())->toBe(1)
        ->and($policy->allows(new stdClass, $notification, 'mail'))->toBeTrue();

    $policy->allowUsing($deny);
    $policy->flush();

    expect($policy->count())->toBe(0)->and($policy->allows(new stdClass, $notification, 'mail'))->toBeTrue();
});

it('matches an array or string callable by value when forgetting a fallback check', function () {
    $policy = new FallbackPolicy;
    $policy->allowUsing('is_object');
    $policy->allowUsing([new ArrayObject, 'count']);

    expect($policy->forget('is_object'))->toBe(1)->and($policy->count())->toBe(1);
});
