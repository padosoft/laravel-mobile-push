<?php

declare(strict_types=1);

/*
 * Reproducible benchmark harness for padosoft/laravel-mobile-push. See benchmarks/README.md.
 *
 *   php benchmarks/run.php --scenario=transport-warm --count=20000 --window=100 --repeat=3
 *   php benchmarks/run.php --scenario=window-sweep --json --out=benchmarks/results/window-sweep.json
 *
 * Everything runs against a local HTTP/2 mock server (tests/Support/H2Mock). Nothing here talks to a real provider.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/lib/Harness.php';
require __DIR__.'/lib/Kit.php';
require __DIR__.'/lib/Scenarios.php';
require __DIR__.'/lib/Compare.php';
require __DIR__.'/lib/Table.php';

use Padosoft\MobilePush\Benchmarks\Harness;
use Padosoft\MobilePush\Benchmarks\Scenarios;
use Padosoft\MobilePush\Benchmarks\Table;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (! str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unexpected argument '{$arg}'\n");
        exit(2);
    }
    $arg = substr($arg, 2);
    $pos = strpos($arg, '=');
    $opts[$pos === false ? $arg : substr($arg, 0, $pos)] = $pos === false ? true : substr($arg, $pos + 1);
}

if (isset($opts['help']) || ! isset($opts['scenario'])) {
    fwrite(STDERR, <<<'TXT'
    Usage: php benchmarks/run.php --scenario=NAME[,NAME...|all] [options]

    Scenarios: transport-warm, transport-cold, pipeline-apns, pipeline-fcm, pipeline-mixed-outcomes,
               window-sweep, connections-sweep, large-batch-memory, many-tenants, compare-pushok, behaviour-pushok

    Options (each scenario has its own defaults, see benchmarks/README.md):
      --count=N              requests/destinations per run
      --window=N             requests multiplexed in flight (ConnectionProfile::$window)
      --connections=N        ConnectionProfile::$maxConnections
      --payload-bytes=N      approximate request body size
      --server-delay-ms=N    artificial delay the mock adds to every response
      --repeat=N             measured runs per case (default 3; large-batch-memory 1)
      --peer-limit=N         SETTINGS_MAX_CONCURRENT_STREAMS the mock advertises (connections-sweep)
      --cold                 pipeline-apns/pipeline-fcm: new pool per run instead of a warm one
      --million              large-batch-memory: also run 1,000,000 destinations
      --tenants=N            many-tenants: number of pool keys (default 200)
      --per-tenant=N         many-tenants: requests sent per pool key (default 5)
      --json                 print the result as JSON on stdout (tables go to stderr)
      --out=FILE             also write the JSON to FILE

    TXT);
    exit(isset($opts['help']) ? 0 : 2);
}

if (! MockServer::available()) {
    fwrite(STDERR, MockServer::unavailableReason()."\n");
    exit(1);
}

$names = $opts['scenario'] === 'all' ? array_values(array_diff(Scenarios::NAMES, ['behaviour-pushok'])) : explode(',', (string) $opts['scenario']);
$asJson = isset($opts['json']);
$say = static function (string $text) use ($asJson): void {
    fwrite($asJson ? STDERR : STDOUT, $text);
};

$report = ['env' => Harness::env(), 'command' => 'php benchmarks/run.php '.implode(' ', array_slice($argv, 1)), 'scenarios' => []];
$say(Table::env($report['env']));

foreach ($names as $name) {
    $say("\n== {$name} ==\n");
    try {
        $result = Scenarios::run($name, $opts);
    } catch (Throwable $e) {
        fwrite(STDERR, "Scenario {$name} failed: ".$e::class.': '.$e->getMessage()."\n");
        Scenarios::stopServer();
        exit(1);
    }
    $report['scenarios'][] = $result;
    $say(Table::scenario($result));
}
Scenarios::stopServer();

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (isset($opts['out']) && is_string($opts['out'])) {
    file_put_contents($opts['out'], $json."\n");
}
if ($asJson) {
    echo $json."\n";
}
