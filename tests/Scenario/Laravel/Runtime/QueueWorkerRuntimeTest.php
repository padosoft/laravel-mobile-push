<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime\RuntimeWorld;
use Padosoft\MobilePush\Tests\Scenario\Runtime\RuntimeProbe;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Story: a real `queue:work` loop on the database queue works through a few thousand light-profile SendPushJobs, one
 * destination each, all for the same APNs connection. The package's own transport (real CurlTransport, real pool
 * keys) must open ONE connection for the whole run and reuse it job after job; the worker's memory must not grow
 * with the number of jobs. Counted by the mock server.
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

/**
 * Queue $jobs one-destination jobs and run a real worker until the queue is empty.
 *
 * @return array{processed: int, memWarm: int, memEnd: int, fdWarm: ?int, fdEnd: ?int}
 */
function workThroughJobs(int $jobs, int $warmUp): array
{
    $tokens = (static function () use ($jobs): Generator {
        for ($i = 0; $i < $jobs; $i++) {
            yield RuntimeWorld::token($i);
        }
    })();
    MobilePush::connection('ios')->toTokens(Platform::Ios, $tokens)->queue(PushMessage::make('Order', 'Shipped'));
    expect(DB::table('jobs')->count())->toBe($jobs);

    $probe = ['processed' => 0, 'memWarm' => 0, 'fdWarm' => null];
    Event::listen(JobProcessed::class, static function () use (&$probe, $warmUp): void {
        $probe['processed']++;
        if ($probe['processed'] === $warmUp) {
            $probe['memWarm'] = RuntimeProbe::memory();
            $probe['fdWarm'] = RuntimeProbe::openDescriptors();
        }
    });

    // --memory: the test process is larger than the worker's 128 MB default (see lesson.md).
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'push', '--stop-when-empty' => true, '--memory' => 4096]);

    return $probe + ['memEnd' => RuntimeProbe::memory(), 'fdEnd' => RuntimeProbe::openDescriptors()];
}

it('a real queue:work run sends 2,000 SendPushJobs over ONE connection, reused job after job, with flat memory', function () use (&$server): void {
    $world = RuntimeWorld::install($server, [
        'connections' => ['ios' => RuntimeWorld::apns($server)],
        'delivery.profile' => 'light',
        'queue.chunk' => 1,
    ]);
    RuntimeWorld::databaseQueue();

    $run = workThroughJobs(2000, warmUp: 200);

    $stats = $server->stats();
    expect($run['processed'])->toBe(2000)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($stats['received'])->toBe(2000)
        ->and($stats['processed'])->toBe(2000)
        ->and($stats['connections'])->toBe(1)          // the pool survived every job of the worker
        ->and($stats['openConnections'])->toBe(1)
        ->and($world->transport->registry()->count())->toBe(1)
        ->and(app(PushManager::class)->cachedConnections())->toBe(1)
        ->and($run['memEnd'] - $run['memWarm'])->toBeLessThan(1024 * 1024);
    if ($run['fdWarm'] !== null) {
        expect($run['fdEnd'])->toBe($run['fdWarm']);
    }
});

it('soak: a worker processing 10,000 jobs keeps one connection and flat memory and descriptors', function () use (&$server): void {
    $world = RuntimeWorld::install($server, [
        'connections' => ['ios' => RuntimeWorld::apns($server)],
        'delivery.profile' => 'light',
        'queue.chunk' => 1,
    ]);
    RuntimeWorld::databaseQueue();

    $run = workThroughJobs(10_000, warmUp: 1000);

    $stats = $server->stats();
    expect($run['processed'])->toBe(10_000)
        ->and($stats['received'])->toBe(10_000)
        ->and($stats['connections'])->toBe(1)
        ->and($world->transport->registry()->count())->toBe(1)
        ->and($run['memEnd'] - $run['memWarm'])->toBeLessThan(1024 * 1024);
    if ($run['fdWarm'] !== null) {
        expect($run['fdEnd'])->toBe($run['fdWarm']);
    }
})->group('slow');
