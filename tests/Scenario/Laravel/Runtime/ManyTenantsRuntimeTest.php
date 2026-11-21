<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime\RuntimeWorld;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: one worker of a multi-tenant application serves two hundred tenants, each registered at runtime with its
 * own APNs key (so its own binding, driver and pool key), and then serves them all again. The manager keeps at most
 * `connection_cache.size` drivers and closes the pools of the ones it evicts; the transport registry caps the pools
 * of the process. The number of connections open at the same time (counted by the server) never exceeds the smaller
 * of the two, and memory does not grow when the same tenants come back.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start(['recordLimit' => 10]);
    }
});

afterAll(function () use (&$server): void {
    $server?->stop();
});

beforeEach(function () use (&$server): void {
    if ($server === null) {
        $this->markTestSkipped(MockServer::unavailableReason());
    }
    $server->reset();
});

/** @return array{maxOpen: int, accepted: int, maxCached: int} */
function serveRuntimeTenants(MockServer $server, int $tenants, int $bound): array
{
    $maxOpen = 0;
    $accepted = 0;
    $maxCached = 0;
    for ($t = 0; $t < $tenants; $t++) {
        $scope = 'tenant-'.$t;
        MobilePush::register('ios', RuntimeWorld::apns($server, sprintf('K%09d', $t), sprintf('T%09d', $t)), $scope);
        $report = MobilePush::scope($scope)->connection('ios')->toToken(Platform::Ios, RuntimeWorld::token($t))->send(PushMessage::make('Hi', 'Tenant '.$t));
        foreach ($report->results() as $result) {
            $accepted += $result->status === SendStatus::Accepted ? 1 : 0;
        }
        $maxCached = max($maxCached, app(PushManager::class)->cachedConnections());
        $stats = $server->waitFor(static fn (array $s): bool => $s['openConnections'] <= $bound, 2000);
        $maxOpen = max($maxOpen, (int) $stats['openConnections']);
    }

    return ['maxOpen' => $maxOpen, 'accepted' => $accepted, 'maxCached' => $maxCached];
}

it('200 runtime connections: open connections never exceed the bound, evicted drivers close their pools, memory is flat on revisit', function (int $cacheSize, int $registryLimit, int $bound) use (&$server): void {
    $registry = new CurlPoolRegistry($registryLimit);
    RuntimeWorld::install($server, ['connection_cache.size' => $cacheSize], $registry);

    $first = serveRuntimeTenants($server, 200, $bound);
    $mem = RuntimeProbe::memory();
    $fd = RuntimeProbe::openDescriptors();
    $rssWarm = RuntimeProbe::rss();
    $second = serveRuntimeTenants($server, 200, $bound);
    $memGrowth = RuntimeProbe::memory() - $mem;
    $fdEnd = RuntimeProbe::openDescriptors();
    $rssEnd = RuntimeProbe::rss();

    $stats = RuntimeProbe::settle($server, 'openConnections', $bound);
    expect($first['accepted'] + $second['accepted'])->toBe(400)
        ->and($first['maxOpen'])->toBe($bound)
        ->and($second['maxOpen'])->toBe($bound)
        ->and(max($first['maxCached'], $second['maxCached']))->toBe($cacheSize)
        ->and($registry->count())->toBeLessThanOrEqual($bound)
        ->and($stats['received'])->toBe(400)
        ->and($stats['openConnections'])->toBe($bound)
        ->and($memGrowth)->toBeLessThan(512 * 1024);
    if ($fd !== null) {
        expect($fdEnd)->toBe($fd);
    }

    MobilePush::flush(); // drop every cached driver and close every pool
    expect(RuntimeProbe::settle($server, 'openConnections', 0)['openConnections'])->toBe(0);

    RuntimeProbe::assertRssGrowthBelow($rssWarm, $rssEnd, 4 * 1024 * 1024, 'on revisit');
    RuntimeProbe::assertRssGrowthBelow($rssWarm, RuntimeProbe::rss(), 4 * 1024 * 1024, 'after the drain');
})->with([
    'manager cache smaller than the registry (16 < 32): the manager bounds the connections' => [16, 32, 16],
    'default manager cache (64) above the registry limit (32): the registry bounds them' => [64, 32, 32],
]);

/*
 * Note on RSS (Linux, PHP 8.3, VmRSS, three runs each, growth over the second pass of 200 tenants and after the
 * drain): 716-732 KiB for the 16/32 case and 936-960 KiB for the 64/32 case. One revisit is not an allocator
 * plateau (see the soak in tests/Scenario/Runtime/ManyTenantsTest.php), hence the 4 MiB bound: it still catches a
 * leak of a pool or a driver per tenant (~12 KiB each), which would add several MiB per pass.
 */
