<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Laravel\Cache\CacheTokenStore;

it('binds the cache stores as singletons and prefixes every key', function () {
    // The keys are read out of the array store, whatever the default store of the run is.
    config(['cache.default' => 'array', 'mobile-push.cache.prefix' => 'tenant-push']);

    expect($this->app->make(TokenStore::class))->toBeInstanceOf(CacheTokenStore::class)
        ->toBe($this->app->make(TokenStore::class))
        ->and($this->app->make(GuardStore::class))->toBeInstanceOf(CacheGuardStore::class);

    $this->app->make(TokenStore::class)->compareAndSwap('name', null, new AuthToken('v-value-abc', new DateTimeImmutable, null), 60);
    $this->app->make(GuardStore::class)->put('g', '1', 60);

    $store = cache()->getStore();
    $storage = (new ReflectionProperty($store, 'storage'))->getValue($store);
    expect($storage)->not->toBeEmpty();
    foreach (array_keys($storage) as $key) {
        expect($key)->toStartWith('tenant-push:');
    }
});

it('uses the cache store named in the config', function () {
    config(['cache.default' => 'array', 'cache.stores.other' => ['driver' => 'array'], 'mobile-push.cache.store' => 'other']);

    $this->app->make(GuardStore::class)->put('g', '1', 60);

    expect(cache()->store('other')->getStore())->not->toBe(cache()->getStore());
    $other = cache()->store('other')->getStore();
    expect((new ReflectionProperty($other, 'storage'))->getValue($other))->not->toBeEmpty();
    $default = cache()->getStore();
    expect((new ReflectionProperty($default, 'storage'))->getValue($default))->toBeEmpty();
});
