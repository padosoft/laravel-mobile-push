<?php

declare(strict_types=1);

/*
 * One contender process for PolicyCoordinationTest (started with proc_open, never by Pest itself).
 *
 * It boots a Testbench application on the shared store named in its argument (see contender-app.php), freezes the
 * clock, waits for the common start instant, then drives the REAL objects the container builds from config:
 *   - mode "frequency": the FrequencyCapGate from PolicyGates, one check per attempt, each with its own effect key;
 *   - mode "badge-distinct" / "badge-same": the BadgeCounter, one increment per attempt;
 *   - mode "read": a final look at the stored state (a probe of the cap with a fresh effect key, the badge value).
 * It prints one JSON line. Nothing is hashed or printed that identifies a device: tokens are synthetic and not shown.
 *
 * Argument: JSON {mode, store, ..., appKey, startAt, now, owner, attempts, limit, onFailure, effectPrefix}
 */

use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\ReportsAvailability;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeStoreUnavailable;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;
use Padosoft\MobilePush\Laravel\Policies\PolicyGates;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/contender-app.php';

/** @var array<string, mixed> $args */
$args = json_decode($argv[1] ?? '{}', true, 8, JSON_THROW_ON_ERROR);

$app = contenderApp($args);

// Diagnostics, always on: the filesystem under the `file` cache store notes every read that came back empty, every
// write that failed and every delete asked by FileStore::getPayload() (which deletes an entry it reads as expired or
// corrupt). This is how a defect of 2026-11-22 was chased: on that store a reader could delete an entry at the
// instant it was created (see FileStoreCreationRaceTest). The guard store now locks its reads there; if a counter
// ever goes back again, the events noted here are printed with the failure.
$trace = null;
if (($args['store'] ?? null) === 'file') {
    $trace = new class extends Filesystem
    {
        /** @var list<string> */
        public array $events = [];

        public function sharedGet($path)
        {
            $contents = parent::sharedGet($path);
            if ($contents === '') {
                $this->note('read-empty', $path);
            }

            return $contents;
        }

        public function put($path, $contents, $lock = false)
        {
            $written = parent::put($path, $contents, $lock);
            if ($written === false || $written === 0) {
                $this->note('write-failed', $path);
            }

            return $written;
        }

        public function delete($paths)
        {
            $callers = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), 'function');
            if (in_array('getPayload', $callers, true)) {
                $this->note('delete-by-getPayload', is_array($paths) ? (string) reset($paths) : (string) $paths);
            }

            return parent::delete(...func_get_args());
        }

        private function note(string $what, string $path): void
        {
            $this->events[] = sprintf('%.6f pid=%d %s %s size=%s', microtime(true), getmypid(), $what, substr(basename($path), 0, 12), is_file($path) ? (string) filesize($path) : 'absent');
        }
    };
    $app->instance('files', $trace);
}
$clock = new FakeClock((string) $args['now']);
$app->instance(ClockInterface::class, $clock);
$config = $app->make('config');
$config->set('mobile-push.frequency.enabled', true);
$config->set('mobile-push.frequency.limits', ['default' => ['per_day' => $args['limit']]]);
$config->set('mobile-push.frequency.on_store_failure', $args['onFailure'] ?? 'allow');

$owner = (string) ($args['owner'] ?? 'owner-1');
$ownerType = 'App\\Models\\User';
$subject = Subject::owner($ownerType, $owner);
$binding = new BindingRef('default', 'ios', Provider::Apns, 'com.example.app', Environment::Production);
$device = ApnsDeviceToken::of(sprintf('%064x', 7))->withRef(new DestinationRef('dev-7', 1, $ownerType, $owner));
$message = PushMessage::make('Sale');

/** @return string allowed | capped | contended (deferred: the cap could not be checked) | unavailable (the gate failed closed) | other:<hint> */
$gateAttempt = static function (string $effect) use ($app, $binding, $message, $clock, $device): string {
    $gate = $app->make(PolicyGates::class)->gates()[0];
    $denials = $gate->check(new GateContext($binding, $message, TrafficClass::Bulk, $clock->now(), $effect), [new SendItem($device)]);
    if ($denials === []) {
        return 'allowed';
    }
    $denial = $denials[0];
    $hint = (string) $denial->reason->hint;
    $line = $denial->status->value.'/'.$denial->reason->code->value;
    if (str_contains($hint, 'failing closed')) {
        return 'unavailable:'.$line;
    }
    if (str_contains($hint, 'too contended')) {
        return 'contended:'.$line;
    }

    return str_starts_with($hint, 'Frequency cap') ? 'capped:'.$line : 'other:'.$line.':'.$hint;
};

while (microtime(true) < $args['startAt']) {
    usleep(500);
}

// What the store itself says afterwards: a process whose store reported itself unavailable rests (on this frozen
// clock: for good) and answers every get() with null and every compareAndSet() with false.
$health = [];
$app->make('log')->listen(static function ($e) use (&$health): void {
    $health[] = (string) ($e->context['operation'] ?? '').':'.(string) ($e->context['cause'] ?? '');
});

$out = ['mode' => $args['mode']];
if ($args['mode'] === 'frequency' || $args['mode'] === 'read') {
    $tally = [];
    $effects = $args['mode'] === 'read' ? ['probe-'.bin2hex(random_bytes(4))] : array_map(static fn (int $i): string => ($args['effectPrefix'] ?? 'p').'-'.getmypid().'-'.$i, range(1, (int) $args['attempts']));
    $exceptions = 0;
    foreach ($effects as $effect) {
        try {
            $key = $gateAttempt($effect);
        } catch (Throwable $e) {
            $exceptions++;
            $key = 'threw:'.$e::class;
        }
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }
    $out += ['tally' => $tally, 'exceptions' => $exceptions];
} elseif ($args['mode'] === 'read-badge') {
    $out += ['current' => $app->make(BadgeCounter::class)->current('default', $subject)];
} else {
    $counter = $app->make(BadgeCounter::class);
    $values = [];
    $contended = 0;
    $unexpected = [];
    for ($i = 1; $i <= (int) $args['attempts']; $i++) {
        $effect = $args['mode'] === 'badge-same' ? 'one-effect' : 'b-'.getmypid().'-'.$i;
        try {
            $values[] = $counter->increment('default', $subject, $effect);
        } catch (BadgeStoreUnavailable) {
            $contended++; // documented: the counter gave up after its bounded compare-and-set attempts, nothing was counted
        } catch (Throwable $e) {
            $unexpected[] = $e::class;
        }
    }
    $out += ['values' => $values, 'contended' => $contended, 'unexpected' => $unexpected];
}

if ($trace !== null) {
    $out['trace'] = $trace->events;
    $cacheStore = $app->make('cache')->store((string) $app->make('config')->get('mobile-push.cache.store'))->getStore();
    $out['traceActive'] = $cacheStore instanceof FileStore && $cacheStore->getFilesystem() === $trace;
}
$out['storeAvailable'] = $app->make(GuardStore::class) instanceof ReportsAvailability ? $app->make(GuardStore::class)->available() : true;
$out['health'] = $health;

echo json_encode($out), "\n";
