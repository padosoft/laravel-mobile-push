<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Auth\TokenCacheKey;
use Padosoft\MobilePush\Core\Auth\TokenSlot;
use Padosoft\MobilePush\Tests\Support\FakeClock;

// --- TokenCacheKey ---------------------------------------------------------------------------

it('derives a stable, namespaced cache key from length-prefixed parts', function () {
    // Recomputed by hand: a change of derivation would orphan every cached token at deploy time and make
    // every worker mint at once (APNs answers that with TooManyProviderTokenUpdates).
    expect(TokenCacheKey::make('apns', 'default', 'TEAM:KEY', 'production'))
        ->toBe('mobile-push:token:'.hash('sha256', '4:apns|7:default|8:TEAM:KEY|10:production|'))
        ->and(TokenCacheKey::make())->toBe('mobile-push:token:'.hash('sha256', ''));
});

it('keeps parts that a plain join would confuse apart', function (array $a, array $b) {
    expect(TokenCacheKey::make(...$a))->not->toBe(TokenCacheKey::make(...$b));
})->with([
    'separator inside a part' => [['a|b'], ['a', 'b']],
    'colon and separator inside a part' => [['a|:b'], ['a', 'b']],
    'digits that look like a length' => [['|9abcdefghi'], ['1', 'abcdefghi']],
    'colon and digits that look like a length' => [['|:9abcdefghi'], ['2', 'abcdefghi']],
    'split point moved' => [['ab', 'c'], ['a', 'bc']],
    'empty part' => [['a', ''], ['a']],
    'environment' => [['apns', 'default', 'T:K', 'production'], ['apns', 'default', 'T:K', 'development']],
]);

it('shows no input in the key: it is a name, not a container of identifiers', function () {
    $key = TokenCacheKey::make('fcm', 'tenant-1', 'push@demo.iam.gserviceaccount.com#kid', 'https://www.googleapis.com/auth/firebase.messaging');

    expect($key)->toMatch('/^mobile-push:token:[0-9a-f]{64}$/')->not->toContain('push@demo');
});

// --- InMemoryTokenStore ----------------------------------------------------------------------

it('keeps an entry for at least 1 s even when asked for a TTL of 0', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $store->compareAndSwap('k', null, new AuthToken('a', $clock->now()), 0);

    expect($store->get('k')?->value)->toBe('a');
    $clock->advance(1);
    expect($store->get('k'))->toBeNull();
});

it('expires an entry with a TTL of 1 s exactly 1 s later', function () {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);
    $store->compareAndSwap('k', null, new AuthToken('a', $clock->now()), 1);

    expect($store->get('k')?->value)->toBe('a');
    $clock->advance(1);
    expect($store->get('k'))->toBeNull();
});

it('holds a lock for at least 1 s even when asked for 0 s, and a 1 s lock for exactly 1 s', function (int $seconds) {
    $clock = new FakeClock;
    $store = new InMemoryTokenStore($clock);

    expect($store->acquireRefreshLock('k', $seconds))->toBeTrue()
        ->and($store->acquireRefreshLock('k', $seconds))->toBeFalse();
    $clock->advance(1);
    expect($store->acquireRefreshLock('k', $seconds))->toBeTrue();
})->with(['0 s' => [0], '1 s' => [1]]);

it('grants the first lock even at the Unix epoch', function () {
    $store = new InMemoryTokenStore(new FakeClock('1970-01-01T00:00:00Z'));

    expect($store->acquireRefreshLock('k', 30))->toBeTrue()
        ->and($store->acquireRefreshLock('other', 30))->toBeTrue()
        ->and($store->acquireRefreshLock('k', 30))->toBeFalse();
});

// --- TokenSlot ---------------------------------------------------------------------------------

it('a token slot holds one token, can be emptied, and never shows or serializes it', function () {
    $clock = new FakeClock;
    $slot = new TokenSlot;
    $token = new AuthToken('secret-bearer-value', $clock->now());

    expect($slot->get())->toBeNull();
    $slot->set($token);
    expect($slot->get())->toBe($token)
        ->and($slot->__debugInfo())->toBe(['token' => '[redacted]'])
        ->and(print_r($slot, true))->not->toContain('secret-bearer-value')
        ->and(var_export($slot, true))->not->toContain('secret-bearer-value')
        ->and(fn () => serialize($slot))->toThrow(LogicException::class)
        ->and(fn () => unserialize(sprintf('O:%d:"%s":0:{}', strlen(TokenSlot::class), TokenSlot::class)))->toThrow(LogicException::class);

    $slot->set(null);
    expect($slot->get())->toBeNull();
});
