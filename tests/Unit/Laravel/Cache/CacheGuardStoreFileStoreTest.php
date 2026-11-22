<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Padosoft\MobilePush\Laravel\Cache\CacheGuardStore;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CacheKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\CollectingLogger;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Cache\RecordingStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;

class_exists(CacheKit::class);

/*
 * On Laravel's `file` store a reader can delete an entry at the instant another process creates it (the file exists
 * empty before it is locked and filled, and an empty file reads as expired). The guard store therefore takes the
 * key's lock for reads and plain writes on that store, and only there. The race itself is run with real processes in
 * tests/Scenario/Laravel/Runtime/FileStoreCreationRaceTest.php; here the rule is pinned.
 */

/** A FileStore in a private directory that records the locks it is asked for and can hand out one that fails. */
function guardFileStore(): FileStore
{
    $dir = sys_get_temp_dir().'/mp-guard-file-'.bin2hex(random_bytes(5));

    return new class(new Filesystem, $dir) extends FileStore
    {
        /** @var list<array{0: string, 1: int}> */
        public array $locks = [];

        public bool $failRelease = false;

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->locks[] = [$name, $seconds];
            $real = parent::lock($name, $seconds, $owner);
            if (! $this->failRelease) {
                return $real;
            }

            return new class($real) extends Lock
            {
                public function __construct(private readonly Illuminate\Contracts\Cache\Lock $real)
                {
                    parent::__construct('failing', 1);
                }

                public function acquire()
                {
                    return $this->real->get();
                }

                public function release()
                {
                    throw new RuntimeException('disk error while releasing');
                }

                public function forceRelease(): void {}

                protected function getCurrentOwner()
                {
                    return $this->owner;
                }
            };
        }
    };
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/mp-guard-file-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('takes the lock of the key for a read and for a plain write on the file store, the same lock as compare-and-set, and releases it', function (): void {
    $backend = guardFileStore();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));
    $name = 'mobile-push:lock:guard:'.hash('sha256', 'k');

    $store->put('k', 'v', 60);
    expect($backend->locks)->toBe([[$name, 5]]);

    expect($store->get('k'))->toBe('v')
        ->and($backend->locks)->toBe([[$name, 5], [$name, 5]]);

    expect($store->compareAndSet('k', 'v', 'w', 60))->toBeTrue()
        ->and($backend->locks)->toBe([[$name, 5], [$name, 5], [$name, 5]])
        ->and($store->get('k'))->toBe('w');

    // Released every time: somebody else can take the lock at once.
    $other = $backend->lock($name, 5);
    expect($other->get())->toBeTrue();
    $other->release();
});

it('takes no lock for reads and plain writes on a store that is not the file store', function (): void {
    $backend = new RecordingStore;
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));

    $store->put('k', 'v', 60);
    expect($store->get('k'))->toBe('v')
        ->and($backend->lockCalls)->toBe([]);
});

it('still reads and writes on the file store when somebody else holds the lock of the key', function (): void {
    $backend = guardFileStore();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health(), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));
    $store->put('k', 'v', 60);
    $held = $backend->lock('mobile-push:lock:guard:'.hash('sha256', 'k'), 30);
    expect($held->get())->toBeTrue();

    // About 100 ms of waiting each, then the operation goes ahead without the lock rather than stall the send.
    expect($store->get('k'))->toBe('v');
    $store->put('k', 'w', 60);
    $held->release();

    expect($store->get('k'))->toBe('w')
        ->and($store->available())->toBeTrue();
});

it('reports a release that fails after a read or a write on the file store and keeps the result', function (): void {
    $logger = new CollectingLogger;
    $backend = guardFileStore();
    $store = new CacheGuardStore(new Repository($backend), CacheKit::health($logger), 'mobile-push', new FakeClock('2026-11-20T10:00:00Z'));
    $store->put('k', 'v', 60);
    $backend->failRelease = true;

    expect($store->get('k'))->toBe('v')
        ->and($store->available())->toBeTrue()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['operation'] ?? null)->toBe('guard.release');
});
