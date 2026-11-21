<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Campaigns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Measures where the time of a durable send goes: every SQL statement is timed (DB::listen) and filed under the phase
 * of the pipeline it belongs to; the wall time of each queued job is filed under its job class. What is not SQL in a
 * relay job is the Sender itself (compile, FakeTransport, classification, observers).
 *
 * Used by the scaling tests (Pest group `slow`) and to print the per-phase table of a run.
 */
final class CampaignProfiler
{
    /** Phase => regex on the SQL text (first match wins; order matters). */
    private const PHASES = [
        'audience slice' => '/^select .* from "mobile_push_devices" where "scope" = \? and "binding_identity" = \? and "state" = \? and "id" > \?/',
        'device resolution' => '/from "mobile_push_devices" where "id" in/',
        'intent insert' => '/^insert or ignore into "mobile_push_deliveries"/',
        'counts' => '/count\(\*\)|sum\(case/',
        'mark started' => '/^update "mobile_push_deliveries" set "state" = \?, "attempt_started_at"/',
        'record' => '/^(select \* from "mobile_push_deliveries" where "id" = \? and "scope" = \? limit 1|update "mobile_push_deliveries" set "state" = \?, "attempts")/',
        'reclaim/expire' => '/"lease_expires_at" <= \?|"expires_at" is not null and "expires_at" <= \?/',
        'claim' => '/"mobile_push_deliveries"/',
        'effects' => '/"mobile_push_effects"/',
        'campaign row' => '/"mobile_push_campaigns"/',
    ];

    /** @var array<string, array{ms: float, n: int}> */
    public array $phases = [];

    /** @var array<string, array{ms: float, n: int, bindings: array<mixed>}> */
    public array $queries = [];

    /** @var array<string, float> job class => wall ms */
    public array $jobs = [];

    private bool $on = false;

    public function __construct()
    {
        DB::listen(function (QueryExecuted $q): void {
            if (! $this->on) {
                return;
            }
            $phase = 'other';
            foreach (self::PHASES as $name => $pattern) {
                if (preg_match($pattern, $q->sql) === 1) {
                    $phase = $name;

                    break;
                }
            }
            $this->phases[$phase]['ms'] = ($this->phases[$phase]['ms'] ?? 0.0) + $q->time;
            $this->phases[$phase]['n'] = ($this->phases[$phase]['n'] ?? 0) + 1;
            $sql = preg_replace('/\(\?(, \?)+\)/', '(?…)', $q->sql) ?? $q->sql;
            $sql = preg_replace('/\((\?, )+\?\)(, \((\?, )+\?\))+/', '(…rows…)', $sql) ?? $sql;
            $this->queries[$sql]['ms'] = ($this->queries[$sql]['ms'] ?? 0.0) + $q->time;
            $this->queries[$sql]['n'] = ($this->queries[$sql]['n'] ?? 0) + 1;
            $this->queries[$sql]['bindings'] ??= $q->bindings;
            $this->queries[$sql]['raw'] ??= $q->sql;
        });
    }

    public function start(): void
    {
        $this->on = true;
    }

    public function stop(): void
    {
        $this->on = false;
    }

    /** Drain the world's queue, timing every job by class. */
    public function drain(CampaignWorld $w): void
    {
        $this->start();
        $last = null;
        $t = hrtime(true);
        $w->drain(before: function (object $job) use (&$last, &$t): void {
            $now = hrtime(true);
            if ($last !== null) {
                $this->jobs[$last] = ($this->jobs[$last] ?? 0.0) + ($now - $t) / 1e6;
            }
            $last = (new \ReflectionClass($job))->getShortName();
            $t = $now;
        });
        if ($last !== null) {
            $this->jobs[$last] = ($this->jobs[$last] ?? 0.0) + (hrtime(true) - $t) / 1e6;
        }
        $this->stop();
    }

    public function sqlMs(): float
    {
        return array_sum(array_column($this->phases, 'ms'));
    }

    /** @return list<array{0: string, 1: float, 2: int}> the slowest statements: [sql, ms, executions] */
    public function top(int $n = 12): array
    {
        $rows = [];
        foreach ($this->queries as $sql => $q) {
            $rows[] = [$sql, $q['ms'], $q['n']];
        }
        usort($rows, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return array_slice($rows, 0, $n);
    }

    /** EXPLAIN QUERY PLAN of the slowest statements, with the first bindings seen. @return array<string, string> */
    public function plans(int $n = 8): array
    {
        $out = [];
        foreach ($this->top($n) as [$sql]) {
            $q = $this->queries[$sql];
            if (! str_starts_with((string) $q['raw'], 'select')) {
                continue;
            }
            $plan = DB::select('explain query plan '.$q['raw'], $q['bindings']);
            $out[$sql] = implode(' | ', array_map(static fn (object $r): string => (string) $r->detail, $plan));
        }

        return $out;
    }

    public function report(string $title, int $destinations, float $totalMs): string
    {
        $lines = [sprintf('== %s: %d destinations, %.1f s total, %.3f ms per 1000 = %.0f/s', $title, $destinations, $totalMs / 1000, $totalMs / $destinations * 1000, $destinations / ($totalMs / 1000))];
        foreach ($this->jobs as $job => $ms) {
            $lines[] = sprintf('   job %-22s %9.1f ms', $job, $ms);
        }
        $phases = $this->phases;
        uasort($phases, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        foreach ($phases as $phase => $p) {
            $lines[] = sprintf('   sql %-22s %9.1f ms %8d stmts  %8.0f dest/s', $phase, $p['ms'], $p['n'], $p['ms'] > 0 ? $destinations / ($p['ms'] / 1000) : 0);
        }
        $lines[] = sprintf('   non-SQL (sender, php)      %9.1f ms', $totalMs - $this->sqlMs());
        $gc = gc_status();
        $lines[] = sprintf('   php gc: %d runs, %d collected, %.1f ms collector; peak memory %.1f MB', $gc['runs'], $gc['collected'], ($gc['collector_time'] ?? 0.0) * 1000, memory_get_peak_usage() / 1048576);

        return implode("\n", $lines);
    }
}
