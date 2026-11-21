<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Benchmarks;

use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/**
 * Collects what the client saw while a scenario runs. Latency is the per-request duration the transport reports
 * in each Exchange (from handing the request to libcurl until its completion), so it includes any wait inside
 * libcurl and the mock server's artificial delay.
 */
final class Recorder
{
    /** @var list<float> */
    public array $latencies = [];

    public int $n = 0;

    public int $errors = 0;

    /** @var array<string, int> */
    public array $http = [];

    /** @var array<string, int> */
    public array $outcomes = [];

    /** @var list<array{at: int, usage: int}> */
    public array $checkpoints = [];

    private int $seen = 0;

    public function __construct(public readonly bool $latency = true, public readonly int $sampleEvery = 1) {}

    public function exchange(Exchange $e): void
    {
        $this->n++;
        if ($e->response === null) {
            $this->errors++;
        } else {
            $k = (string) $e->response->status;
            $this->http[$k] = ($this->http[$k] ?? 0) + 1;
        }
        if ($this->latency && ($this->seen++ % $this->sampleEvery) === 0) {
            $this->latencies[] = $e->durationMs;
        }
    }

    /** For clients that expose no Exchange (e.g. pushok): count one answered request by HTTP status. */
    public function answered(int $status): void
    {
        $this->n++;
        $this->http[(string) $status] = ($this->http[(string) $status] ?? 0) + 1;
    }

    public function outcome(string $status): void
    {
        $this->outcomes[$status] = ($this->outcomes[$status] ?? 0) + 1;
    }

    public function checkpoint(int $at): void
    {
        $this->checkpoints[] = ['at' => $at, 'usage' => memory_get_usage()];
    }
}

final class Harness
{
    public static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return NAN;
        }
        $rank = (int) ceil($p / 100 * $n);

        return (float) $sorted[max(0, min($n - 1, $rank - 1))];
    }

    public static function median(array $values): float
    {
        sort($values);

        return self::medianSorted($values);
    }

    private static function medianSorted(array $v): float
    {
        $n = count($v);
        if ($n === 0) {
            return NAN;
        }

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    public static function fdCount(): int
    {
        $list = @scandir('/proc/self/fd');

        // minus ".", ".." and the directory handle scandir itself holds
        return $list === false ? -1 : count($list) - 3;
    }

    /** Run $body once and measure client side and server side. */
    public static function measure(MockServer $server, Recorder $rec, callable $body): array
    {
        gc_collect_cycles();
        $s0 = $server->stats();
        $fd0 = self::fdCount();
        $ru0 = getrusage();
        $m0 = memory_get_usage();
        memory_reset_peak_usage();
        $t0 = hrtime(true);

        $extra = $body($rec);

        $wall = (hrtime(true) - $t0) / 1e9;
        $m1 = memory_get_usage();
        $peak = memory_get_peak_usage();
        $ru1 = getrusage();
        $fd1 = self::fdCount();
        $s1 = $server->stats();
        gc_collect_cycles();
        $m2 = memory_get_usage();

        $lat = $rec->latencies;
        sort($lat);
        $cpu = ($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e6
            + ($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1e6;
        $count = $rec->n;

        $out = [
            'requests' => $count,
            'wall_s' => round($wall, 4),
            'rps' => $wall > 0 ? round($count / $wall, 1) : 0.0,
            'latency_ms' => $lat === [] ? null : [
                'p50' => round(self::percentile($lat, 50), 3),
                'p95' => round(self::percentile($lat, 95), 3),
                'p99' => round(self::percentile($lat, 99), 3),
                'max' => round((float) end($lat), 3),
                'samples' => count($lat),
            ],
            'peak_over_start_mib' => round(($peak - $m0) / 1048576, 3),
            'mem_start_bytes' => $m0,
            'mem_end_bytes' => $m1,
            'mem_growth_bytes' => $m1 - $m0,
            'mem_growth_after_gc_bytes' => $m2 - $m0,
            'recorder_bytes_estimate' => count($rec->latencies) * 16,
            'cpu_s' => round($cpu, 3),
            'transport_errors' => $rec->errors,
            'http_status' => $rec->http,
            'outcomes' => $rec->outcomes,
            'fds' => ['before' => $fd0, 'after' => $fd1],
            'server' => [
                'connections_opened' => $s1['connections'] - $s0['connections'],
                'connections_total_since_reset' => $s1['connections'],
                'tcp_connections' => $s1['tcpConnections'] - $s0['tcpConnections'],
                'requests_received' => $s1['received'] - $s0['received'],
                'requests_processed' => $s1['processed'] - $s0['processed'],
                'max_concurrent_streams_cumulative' => $s1['maxConcurrentStreams'],
                'open_connections_after' => $s1['openConnections'],
            ],
        ];
        if ($rec->checkpoints !== []) {
            $out['checkpoints'] = $rec->checkpoints;
        }
        if (is_array($extra)) {
            $out['extra'] = $extra;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $runs */
    public static function summarize(array $runs): array
    {
        $pick = fn (callable $f): array => array_values(array_filter(array_map($f, $runs), fn ($v) => $v !== null));
        $stat = function (array $values): array {
            if ($values === []) {
                return [];
            }
            $med = self::median($values);

            return [
                'median' => round($med, 3),
                'min' => round(min($values), 3),
                'max' => round(max($values), 3),
                'spread_pct' => $med > 0 ? round((max($values) - min($values)) / $med * 100, 1) : 0.0,
            ];
        };

        return [
            'runs' => count($runs),
            'rps' => $stat($pick(fn ($r) => $r['rps'])),
            'wall_s' => $stat($pick(fn ($r) => $r['wall_s'])),
            'p50_ms' => $stat($pick(fn ($r) => $r['latency_ms']['p50'] ?? null)),
            'p95_ms' => $stat($pick(fn ($r) => $r['latency_ms']['p95'] ?? null)),
            'p99_ms' => $stat($pick(fn ($r) => $r['latency_ms']['p99'] ?? null)),
            'max_ms' => $stat($pick(fn ($r) => $r['latency_ms']['max'] ?? null)),
            'peak_over_start_mib' => $stat($pick(fn ($r) => $r['peak_over_start_mib'])),
            'mem_growth_bytes' => $stat($pick(fn ($r) => $r['mem_growth_bytes'])),
            'mem_growth_after_gc_bytes' => $stat($pick(fn ($r) => $r['mem_growth_after_gc_bytes'])),
            'cpu_s' => $stat($pick(fn ($r) => $r['cpu_s'])),
            'transport_errors' => $stat($pick(fn ($r) => $r['transport_errors'])),
            'connections_opened' => $stat($pick(fn ($r) => $r['server']['connections_opened'])),
            'max_concurrent_streams' => $stat($pick(fn ($r) => $r['server']['max_concurrent_streams_cumulative'])),
        ];
    }

    public static function env(): array
    {
        $cpu = 'unknown';
        foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'model name')) {
                $cpu = trim(substr($line, strpos($line, ':') + 1));
                break;
            }
        }
        $curl = curl_version();
        $node = trim((string) @shell_exec('node --version 2>/dev/null'));
        $nproc = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        $mem = '';
        foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'MemTotal')) {
                $mem = trim(substr($line, strpos($line, ':') + 1));
                break;
            }
        }
        $commit = trim((string) @shell_exec('git -C '.escapeshellarg(dirname(__DIR__, 2)).' rev-parse --short HEAD 2>/dev/null'));
        $pushok = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('edamov/pushok')
            ? [
                'version' => \Composer\InstalledVersions::getPrettyVersion('edamov/pushok'),
                'reference' => \Composer\InstalledVersions::getReference('edamov/pushok'),
            ] : null;

        return [
            'date_utc' => gmdate('c'),
            'php' => PHP_VERSION.' ('.PHP_SAPI.', '.(PHP_ZTS ? 'ZTS' : 'NTS').')',
            'libcurl' => $curl['version'],
            'libcurl_ssl' => $curl['ssl_version'],
            'libcurl_protocols_h2' => (bool) ($curl['features'] & CURL_VERSION_HTTP2),
            'node' => $node,
            'cpu_model' => $cpu,
            'cpu_cores' => $nproc,
            'mem_total' => $mem,
            'kernel' => php_uname('r'),
            'loadavg_at_start' => sys_getloadavg(),
            'package_commit' => $commit !== '' ? $commit : null,
            'pushok' => $pushok,
            'php_memory_limit' => ini_get('memory_limit'),
        ];
    }
}
