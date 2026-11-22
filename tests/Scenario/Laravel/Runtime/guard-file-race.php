<?php

declare(strict_types=1);

/*
 * One process of FileStoreCreationRaceTest (started with proc_open, never by Pest itself).
 *
 * Role "writer": for the given time it creates guard entries one after the other through the container's GuardStore
 * (compare-and-set from "absent"), announces each key in a small file and, a moment later, reads the entry back.
 * Role "reader": for the given time it reads the announced key as fast as it can. Both sit on the same `file` cache
 * store. The writer prints how many entries it created and how many it could not find again.
 *
 * Argument: JSON {role, seconds, announce, appKey, now, store: "file", cacheDir}
 */

use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/contender-app.php';

/** @var array<string, mixed> $args */
$args = json_decode($argv[1] ?? '{}', true, 8, JSON_THROW_ON_ERROR);

$app = contenderApp($args);
$app->instance(ClockInterface::class, new FakeClock((string) $args['now']));
$store = $app->make(GuardStore::class);
$announce = (string) $args['announce'];
$end = microtime(true) + (float) $args['seconds'];

if ($args['role'] === 'reader') {
    $reads = 0;
    while (microtime(true) < $end) {
        $current = @file_get_contents($announce);
        if (is_string($current) && $current !== '') {
            $store->get($current);
            $reads++;
        }
    }
    echo json_encode(['reads' => $reads]), "\n";

    return;
}

$created = 0;
$lost = 0;
$refused = 0;
for ($n = 0; microtime(true) < $end; $n++) {
    $key = 'entry-'.$n;
    file_put_contents($announce.'.tmp', $key);
    rename($announce.'.tmp', $announce);   // atomic: a reader sees the old key or the new one

    $stored = false;
    for ($try = 0; $try < 50 && ! $stored; $try++) {
        $stored = $store->compareAndSet($key, null, 'value-'.$n, 60);
        $refused += $stored ? 0 : 1;
    }
    if (! $stored) {
        continue;
    }
    $created++;
    usleep(300);   // a reader that decided to delete the file has done it by now
    if ($store->get($key) !== 'value-'.$n) {
        $lost++;
    }
}
echo json_encode(['created' => $created, 'lost' => $lost, 'refused' => $refused]), "\n";
