<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Benchmarks;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/**
 * Every scenario returns ['scenario', 'description', 'cases' => [['label', 'params', 'runs', 'summary', ...]]].
 * A "run" is one measured pass (see Harness::measure); --repeat controls how many passes a case gets.
 */
final class Scenarios
{
    public const NAMES = [
        'transport-warm', 'transport-cold', 'pipeline-apns', 'pipeline-fcm', 'pipeline-mixed-outcomes',
        'window-sweep', 'connections-sweep', 'large-batch-memory', 'many-tenants', 'compare-pushok', 'behaviour-pushok',
    ];

    private static ?MockServer $server = null;

    public static function server(): MockServer
    {
        return self::$server ??= MockServer::start();
    }

    public static function stopServer(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    /** @param array<string, mixed> $p parsed command line parameters */
    public static function run(string $name, array $p): array
    {
        return match ($name) {
            'transport-warm' => self::transport($p, true),
            'transport-cold' => self::transport($p, false),
            'pipeline-apns' => self::pipeline($p, 'apns'),
            'pipeline-fcm' => self::pipeline($p, 'fcm'),
            'pipeline-mixed-outcomes' => self::mixed($p),
            'window-sweep' => self::windowSweep($p),
            'connections-sweep' => self::connectionsSweep($p),
            'large-batch-memory' => self::largeBatch($p),
            'many-tenants' => self::manyTenants($p),
            'compare-pushok' => Compare::run($p),
            'behaviour-pushok' => Compare::behaviourOnly($p),
            default => throw new \InvalidArgumentException("Unknown scenario '{$name}'. Known: ".implode(', ', self::NAMES)),
        };
    }

    // ------------------------------------------------------------------ scenarios

    private static function transport(array $p, bool $warm): array
    {
        $o = self::opts($p, ['count' => 20000]) + ['warm' => $warm];
        $o['label'] = ($warm ? 'warm pool' : 'fresh pool each run').", window {$o['window']}, {$o['connections']} conn";

        return self::wrap($warm ? 'transport-warm' : 'transport-cold', $warm
            ? 'Raw CurlTransport, APNs-shaped requests, pool already connected (one request sent before measuring).'
            : 'Raw CurlTransport, APNs-shaped requests, a new registry/pool (new TCP+TLS connection) for every run.', [self::rawCase($o)]);
    }

    private static function pipeline(array $p, string $provider): array
    {
        $o = self::opts($p, ['count' => 10000]) + ['warm' => ! ($p['cold'] ?? false), 'provider' => $provider];
        $o['label'] = "{$provider} pipeline, ".($o['warm'] ? 'warm' : 'cold').", window {$o['window']}, {$o['connections']} conn";

        return self::wrap("pipeline-{$provider}", 'Full Sender pipeline (compile, guards, transport, classify, report) over the real transport, mock scripted like '.strtoupper($provider).' (2xx with ids).', [self::pipelineCase($o)]);
    }

    private static function mixed(array $p): array
    {
        $o = self::opts($p, ['count' => 10000]) + ['warm' => true, 'provider' => 'apns', 'mixed' => true];
        $o['label'] = "apns pipeline, mixed outcomes (95% accepted, 3% 410 Unregistered, 1% 400 BadDeviceToken, 1% 500), window {$o['window']}";

        $plain = $o;
        $plain['mixed'] = false;
        $plain['label'] = "apns pipeline, all accepted (control), window {$o['window']}";

        return self::wrap('pipeline-mixed-outcomes', 'Same pipeline with a realistic outcome mix, next to an all-accepted control with identical settings, to show the cost of classification and retry planning.', [self::pipelineCase($plain), self::pipelineCase($o)]);
    }

    private static function windowSweep(array $p): array
    {
        $cases = [];
        foreach ([10, 50, 100, 250, 500] as $w) {
            $o = self::opts($p, ['count' => 10000, 'delay' => 20, 'window' => $w]);
            $o['window'] = $w;
            $o['warm'] = true;
            $o['label'] = "window {$w}";
            $cases[] = self::rawCase($o);
        }

        return self::wrap('window-sweep', 'Raw CurlTransport, warm, one connection, fixed server delay, varying window. The mock allows 1000 concurrent streams per connection.', $cases);
    }

    private static function connectionsSweep(array $p): array
    {
        $cases = [];
        $limits = isset($p['peer-limit']) ? [(int) $p['peer-limit']] : [1000, 100];
        foreach ($limits as $limit) {
            foreach ([1, 2, 4] as $c) {
                $o = self::opts($p, ['count' => 20000, 'delay' => 20, 'window' => 500, 'connections' => $c, 'peerLimit' => $limit]);
                $o['connections'] = $c;
                $o['peerLimit'] = $limit;
                $o['warm'] = true;
                $o['label'] = "{$c} connection(s), peer stream limit {$limit}, window {$o['window']}";
                $cases[] = self::rawCase($o);
            }
        }

        return self::wrap('connections-sweep', 'Raw CurlTransport, warm, fixed server delay, 1/2/4 connections. The mock advertises SETTINGS_MAX_CONCURRENT_STREAMS = peer limit.', $cases);
    }

    private static function largeBatch(array $p): array
    {
        $counts = isset($p['count']) ? [(int) $p['count']] : [100_000];
        if (! empty($p['million']) && ! isset($p['count'])) {
            $counts[] = 1_000_000;
        }
        $cases = [];
        foreach ($counts as $n) {
            $o = self::opts($p, ['count' => $n, 'repeat' => 1]);
            $o['count'] = $n;
            $o['repeat'] = (int) ($p['repeat'] ?? 1);
            $o += ['warm' => true, 'provider' => 'apns', 'checkpointEvery' => max(1000, intdiv($n, 20)), 'sample' => max(1, intdiv($n, 20000))];
            $o['label'] = 'apns pipeline, '.number_format($n).' destinations from a generator, window '.$o['window'];
            $cases[] = self::pipelineCase($o);
        }

        return self::wrap('large-batch-memory', 'Destinations are produced lazily by a generator and the report is consumed as a cursor; memory_get_usage is sampled during the run (about every 5% of the destinations). Latency is sampled (every k-th request) to keep the recorder itself small.', $cases);
    }

    private static function manyTenants(array $p): array
    {
        $tenants = (int) ($p['tenants'] ?? 200);
        $per = (int) ($p['count'] ?? 5);
        $server = self::server();
        $cases = [];
        foreach ([32, 1000] as $limit) {
            $runs = [];
            for ($r = 0; $r < (int) ($p['repeat'] ?? 1); $r++) {
                $server->reset();
                $server->settings(['recordLimit' => 20]);
                $server->rules(self::okRule((int) ($p['delay'] ?? 0)));
                $registry = new CurlPoolRegistry($limit);
                $transport = new CurlTransport($registry);
                $rec = new Recorder(true);
                $body = Kit::payloadBody((int) ($p['payload'] ?? 256));
                $runs[] = Harness::measure($server, $rec, function (Recorder $rec) use ($tenants, $per, $transport, $registry, $server, $body): array {
                    $series = [];
                    for ($t = 0; $t < $tenants; $t++) {
                        $profile = self::profile($server, "apns|tenant:{$t}|key:K{$t}|production|gen:1", 100, 1);
                        foreach ($transport->send(self::requests($server->baseUrl(), $per, $body), $profile) as $exchange) {
                            $rec->exchange($exchange);
                        }
                        if (($t + 1) % 20 === 0) {
                            $s = $server->stats();
                            $series[] = ['tenants_done' => $t + 1, 'pools' => $registry->count(), 'evictions' => $registry->evictions(), 'fds' => Harness::fdCount(), 'memory_bytes' => memory_get_usage(), 'server_open_connections' => $s['openConnections'], 'server_connections_total' => $s['connections']];
                        }
                    }

                    return ['series' => $series];
                });
                $transport->close();
                $after = $server->waitFor(fn (array $s) => $s['openConnections'] === 0, 3000);
                $runs[count($runs) - 1]['extra']['after_close'] = ['pools' => $registry->count(), 'fds' => Harness::fdCount(), 'server_open_connections' => $after['openConnections']];
            }
            $cases[] = ['label' => "{$tenants} pool keys in sequence, {$per} requests each, registry limit {$limit}", 'params' => ['tenants' => $tenants, 'per_tenant' => $per, 'registry_limit' => $limit], 'runs' => $runs, 'summary' => Harness::summarize($runs)];
        }

        return self::wrap('many-tenants', 'One process, one transport, N different pool keys used one after the other (each key = its own connection). Registry limit 32 is the default; 1000 shows what happens without effective eviction.', $cases);
    }

    // ------------------------------------------------------------------ building blocks (also used by Compare)

    /** @return array{count:int,window:int,connections:int,payload:int,delay:int,repeat:int,peerLimit:int} */
    public static function opts(array $p, array $defaults): array
    {
        return [
            'count' => (int) ($p['count'] ?? $defaults['count'] ?? 10000),
            'window' => (int) ($p['window'] ?? $defaults['window'] ?? 100),
            'connections' => (int) ($p['connections'] ?? $defaults['connections'] ?? 1),
            'payload' => (int) ($p['payload-bytes'] ?? $defaults['payload'] ?? 256),
            'delay' => (int) ($p['server-delay-ms'] ?? $defaults['delay'] ?? 0),
            'repeat' => (int) ($p['repeat'] ?? $defaults['repeat'] ?? 3),
            'peerLimit' => (int) ($p['peer-limit'] ?? $defaults['peerLimit'] ?? 1000),
        ];
    }

    public static function wrap(string $name, string $description, array $cases): array
    {
        return ['scenario' => $name, 'description' => $description, 'cases' => $cases];
    }

    public static function okRule(int $delayMs, string $provider = 'apns'): array
    {
        if ($provider === 'fcm') {
            return ['action' => 'respond', 'status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"name":"projects/example-project/messages/0:1"}', 'delayMs' => $delayMs];
        }

        return ['action' => 'respond', 'status' => 200, 'headers' => ['apns-id' => '00000000-0000-4000-8000-000000000001'], 'body' => '', 'delayMs' => $delayMs];
    }

    public static function profile(MockServer $server, string $key, int $window, int $connections): ConnectionProfile
    {
        return new ConnectionProfile(poolKey: $key, window: $window, maxConnections: $connections, connectTimeoutMs: 5000, timeoutMs: 30000, caBundlePath: $server->caBundlePath());
    }

    /** @return \Generator<int, Request> */
    public static function requests(string $base, int $count, string $body): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            yield new Request("r{$i}", 'POST', $base.'/3/device/d'.sprintf('%063x', $i), [
                'content-type' => 'application/json',
                'apns-topic' => Kit::BUNDLE,
                'apns-push-type' => 'alert',
                'authorization' => 'bearer benchmark.fake.token',
            ], $body);
        }
    }

    /** @param array<string, mixed> $o */
    private static function prepareServer(MockServer $server, array $o, array $rules): void
    {
        $server->reset();
        $server->settings(['maxConcurrentStreams' => $o['peerLimit'] ?? 1000, 'recordLimit' => 50]);
        $server->rules(...$rules);
    }

    /** @param array<string, mixed> $o */
    public static function rawCase(array $o): array
    {
        $server = self::server();
        self::prepareServer($server, $o, [self::okRule($o['delay'])]);
        $body = Kit::payloadBody($o['payload']);
        $profile = self::profile($server, 'bench-raw', $o['window'], $o['connections']);
        $transport = new CurlTransport(new CurlPoolRegistry);

        if ($o['warm']) {
            foreach ($transport->send(self::requests($server->baseUrl(), 1, $body), $profile) as $_) {
            }
        }
        $runs = [];
        for ($r = 0; $r < $o['repeat']; $r++) {
            if (! $o['warm']) {
                $transport->close();
                $transport = new CurlTransport(new CurlPoolRegistry);
            }
            $rec = new Recorder(true);
            $runs[] = Harness::measure($server, $rec, function (Recorder $rec) use ($transport, $server, $o, $body, $profile): void {
                foreach ($transport->send(self::requests($server->baseUrl(), $o['count'], $body), $profile) as $exchange) {
                    $rec->exchange($exchange);
                }
            });
        }
        $transport->close();

        return ['label' => $o['label'], 'params' => self::publicParams($o), 'runs' => $runs, 'summary' => Harness::summarize($runs)];
    }

    /** @param array<string, mixed> $o */
    public static function pipelineCase(array $o): array
    {
        $server = self::server();
        $provider = $o['provider'] ?? 'apns';
        $mixed = (bool) ($o['mixed'] ?? false);
        $rules = $mixed ? self::mixedRules($o['delay']) : [self::okRule($o['delay'], $provider)];
        self::prepareServer($server, $o, $rules);

        $kit = new Kit;
        $driver = $provider === 'fcm' ? $kit->fcmDriver($server, $o['window'], $o['connections']) : $kit->apnsDriver($server, $o['window'], $o['connections']);
        $message = Kit::message($o['payload']);
        $make = fn () => new RecordingTransport(new CurlTransport(new CurlPoolRegistry));
        $transport = $make();
        $sender = $kit->sender($transport);
        $destinations = fn (int $n) => $provider === 'fcm' ? Kit::fcmTokens($n) : ($mixed ? Kit::mixedApnsTokens($n) : Kit::apnsTokens($n));

        if ($o['warm']) {
            foreach ($sender->send($driver, $message, $destinations(1), Kit::options())->results() as $_) {
            }
        }
        $runs = [];
        for ($r = 0; $r < $o['repeat']; $r++) {
            if (! $o['warm']) {
                $transport->close();
                $transport = $make();
                $sender = $kit->sender($transport);
            }
            $rec = new Recorder(true, (int) ($o['sample'] ?? 1));
            $transport->recorder = $rec;
            $every = (int) ($o['checkpointEvery'] ?? 0);
            $runs[] = Harness::measure($server, $rec, function (Recorder $rec) use ($sender, $driver, $message, $destinations, $o, $every): void {
                $k = 0;
                foreach ($sender->send($driver, $message, $destinations($o['count']), Kit::options())->results() as $result) {
                    $rec->outcome($result->status->value.($result->reason ? '/'.$result->reason->code->value : ''));
                    if ($every > 0 && (++$k % $every) === 0) {
                        $rec->checkpoint($k);
                    }
                }
            });
            $transport->recorder = null;
            if ($every > 0) {
                $runs[count($runs) - 1]['body_bytes_on_server'] = self::sampleBodyBytes($server);
            }
        }
        $transport->close();
        $last = &$runs[count($runs) - 1];
        $last['body_bytes_on_server'] ??= self::sampleBodyBytes($server);
        unset($last);

        return ['label' => $o['label'], 'params' => self::publicParams($o), 'runs' => $runs, 'summary' => Harness::summarize($runs)];
    }

    /** @return list<array<string, mixed>> */
    private static function mixedRules(int $delay): array
    {
        $rule = fn (string $prefix, int $status, string $body) => ['path' => '^/3/device/'.$prefix, 'action' => 'respond', 'status' => $status, 'headers' => ['content-type' => 'application/json', 'apns-id' => '00000000-0000-4000-8000-000000000001'], 'body' => $body, 'delayMs' => $delay];

        return [
            $rule('a', 410, '{"reason":"Unregistered","timestamp":1763600000123}'),
            $rule('b', 400, '{"reason":"BadDeviceToken"}'),
            $rule('c', 500, '{"reason":"InternalServerError"}'),
            self::okRule($delay),
        ];
    }

    public static function sampleBodyBytes(MockServer $server): ?float
    {
        $bodies = array_map(fn ($r) => strlen($r['body']), $server->requests());

        return $bodies === [] ? null : round(array_sum($bodies) / count($bodies), 1);
    }

    private static function publicParams(array $o): array
    {
        unset($o['label'], $o['checkpointEvery'], $o['sample']);

        return $o;
    }
}
