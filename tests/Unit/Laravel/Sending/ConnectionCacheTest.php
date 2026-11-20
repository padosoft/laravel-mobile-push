<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Laravel\Sending\ConnectionCache;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Laravel\Sending\ResolvedConnection;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

function cachedConnection(string $scope, string $name, string $generation = '1', string $app = 'com.example.app'): ResolvedConnection
{
    $binding = new BindingRef($scope, $name, Provider::Apns, $app, Environment::Production, $generation);

    return new ResolvedConnection($binding, (new SendKit)->apnsDriver(binding: $binding), new LiveSettings, ['pool:'.$binding->fingerprint()], []);
}

it('evicts the least recently used connection and closes its pools', function () {
    $closed = [];
    $cache = new ConnectionCache(2, function (ResolvedConnection $c) use (&$closed): void {
        $closed[] = $c->binding->scope;
    });

    $cache->put('h', cachedConnection('a', 'main'));
    $cache->put('h', cachedConnection('b', 'main'));
    expect($cache->find('a', 'main', 'h'))->not->toBeNull();   // a is now the most recently used
    $cache->put('h', cachedConnection('c', 'main'));

    expect($closed)->toBe(['b'])
        ->and($cache->count())->toBe(2)
        ->and($cache->find('b', 'main', 'h'))->toBeNull()
        ->and($cache->find('a', 'main', 'h'))->not->toBeNull();
});

it('replaces (and closes) the driver of a name when its config changes, and keeps scopes apart', function () {
    $closed = [];
    $cache = new ConnectionCache(10, function (ResolvedConnection $c) use (&$closed): void {
        $closed[] = $c->binding->scope.'/'.$c->binding->generation;
    });

    $cache->put('v1', cachedConnection('a', 'main', 'g1'));
    $cache->put('v1', cachedConnection('b', 'main', 'g1'));
    $cache->put('v2', cachedConnection('a', 'main', 'g2'));

    expect($closed)->toBe(['a/g1'])
        ->and($cache->find('a', 'main', 'v1'))->toBeNull()
        ->and($cache->find('a', 'main', 'v2')?->binding->generation)->toBe('g2')
        ->and($cache->find('b', 'main', 'v1')?->binding->generation)->toBe('g1');

    $cache->flush();
    expect($cache->count())->toBe(0)->and($closed)->toHaveCount(3);
});

it('derives a short generation that changes with the credential identity only', function () {
    $a = ConnectionFactory::generation('token:TEAM123456:KEY1111111', '');
    $b = ConnectionFactory::generation('token:TEAM123456:KEY2222222', '');
    $withSecondary = ConnectionFactory::generation('token:TEAM123456:KEY1111111', 'token:TEAM123456:KEY2222222');

    expect($a)->toMatch('/^[0-9a-f]{12}$/')
        ->and($a)->toBe(ConnectionFactory::generation('token:TEAM123456:KEY1111111', ''))
        ->and($a)->not->toBe($b)
        ->and($a)->not->toBe($withSecondary);
});
