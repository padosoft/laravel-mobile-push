<?php

declare(strict_types=1);

/*
 * One contender process for MetricsCoordinationTest (started with proc_open, never by Pest itself).
 *
 * It boots a Testbench application on the shared cache store named in its argument, waits for the common start
 * instant, then either records `count` send results on the container's MetricsRecorder (mode "record"), all with
 * the same labels, or reads the counters back (mode "read"). It prints one JSON line.
 *
 * Argument: JSON {mode, store, count?, appKey, startAt, now, ...the store arguments of contender-app.php}
 */

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/contender-app.php';

/** @var array<string, mixed> $args */
$args = json_decode($argv[1] ?? '{}', true, 8, JSON_THROW_ON_ERROR);

$app = contenderApp($args);
$app->instance(ClockInterface::class, new FakeClock((string) $args['now']));
// The recorder is built while the application boots, before contenderApp() names the shared store: build it again.
$app->forgetInstance(MetricsRecorder::class);
$recorder = $app->make(MetricsRecorder::class);
$binding = new BindingRef('default', 'ios', Provider::Apns, 'com.example.app', Environment::Production);

while (microtime(true) < $args['startAt']) {
    usleep(500);
}

if ($args['mode'] === 'record') {
    $accepted = new SendResult(ApnsDeviceToken::of(sprintf('%064x', 7)), SendStatus::Accepted, Reason::of(ReasonCode::None), 1);
    for ($i = 0; $i < (int) $args['count']; $i++) {
        $recorder->record($binding, $accepted, TrafficClass::Bulk);
    }
    echo json_encode(['recorded' => (int) $args['count']]), "\n";

    return;
}

$results = 0;
$series = 0;
foreach ($recorder->snapshot() as $sample) {
    if ($sample->name === MetricsRecorder::RESULTS) {
        $series++;
        $results += (int) $sample->value;
    }
}
$window = 0;
foreach ($recorder->errorRates() as $rate) {
    $window += $rate->sample;
}
echo json_encode(['results' => $results, 'series' => $series, 'window' => $window]), "\n";
