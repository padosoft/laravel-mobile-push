<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function tok(string $v, FakeClock $c): AuthToken
{
    return new AuthToken($v, $c->now());
}

it('compare-and-swap installs only when the expected generation matches and returns the winner', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $a = tok('a', $clock);
    $b = tok('b', $clock);
    $c = tok('c', $clock);

    expect($store->compareAndSwap('k', null, $a, 100))->toBe($a)
        ->and($store->compareAndSwap('k', null, $b, 100))->toBe($a)             // lost: nothing expected but a is there
        ->and($store->compareAndSwap('k', $b->generation(), $c, 100))->toBe($a) // wrong generation
        ->and($store->compareAndSwap('k', $a->generation(), $b, 100))->toBe($b)
        ->and($store->get('k'))->toBe($b);
});

it('expires entries and locks by the injected clock and honours forget/release', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $store->compareAndSwap('k', null, tok('a', $clock), 60);

    expect($store->acquireRefreshLock('k', 30))->toBeTrue()
        ->and($store->acquireRefreshLock('k', 30))->toBeFalse();
    $clock->advance(29);
    expect($store->acquireRefreshLock('k', 30))->toBeFalse()->and($store->get('k'))->not->toBeNull();
    $clock->advance(1);
    expect($store->acquireRefreshLock('k', 30))->toBeTrue();
    $store->releaseRefreshLock('k');
    expect($store->acquireRefreshLock('k', 30))->toBeTrue();

    $clock->advance(30);
    expect($store->get('k'))->toBeNull();
    // an expired entry counts as "nothing stored" for compare-and-swap
    expect($store->compareAndSwap('k', null, tok('z', $clock), 60)->value)->toBe('z');
    $store->forget('k');
    expect($store->get('k'))->toBeNull();
});
