<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Benchmarks;

final class Table
{
    public static function env(array $e): string
    {
        $out = "date {$e['date_utc']}\nphp {$e['php']} | libcurl {$e['libcurl']} ({$e['libcurl_ssl']}) | node {$e['node']}\n";
        $out .= "cpu {$e['cpu_model']} x{$e['cpu_cores']} | mem {$e['mem_total']} | kernel {$e['kernel']}\n";
        $out .= 'loadavg at start '.implode(' ', array_map(fn ($v) => round($v, 2), $e['loadavg_at_start']))."\n";

        return $out;
    }

    public static function scenario(array $s): string
    {
        $out = $s['description']."\n";
        if (isset($s['sections'])) {
            foreach ($s['sections'] as $title => $text) {
                $out .= "\n-- {$title} --\n".(is_string($text) ? $text : json_encode($text, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n";
            }
        }
        $rows = [];
        foreach ($s['cases'] as $c) {
            if (! isset($c['summary']['rps'])) {
                continue;
            }
            $sum = $c['summary'];
            $rows[] = [
                $c['label'],
                (string) ($c['runs'][0]['requests'] ?? ''),
                self::range($sum['rps'], 0),
                self::med($sum['p50_ms'] ?? [], 1),
                self::med($sum['p95_ms'] ?? [], 1),
                self::med($sum['p99_ms'] ?? [], 1),
                self::med($sum['max_ms'] ?? [], 1),
                self::med($sum['peak_over_start_mib'], 2),
                self::med($sum['mem_growth_bytes'], 0),
                self::med($sum['transport_errors'], 0),
                self::med($sum['connections_opened'], 0),
                (string) ($sum['max_concurrent_streams']['median'] ?? ''),
                ($sum['rps']['spread_pct'] ?? 0) > 15 ? 'SPREAD '.$sum['rps']['spread_pct'].'%' : '',
            ];
        }
        if ($rows !== []) {
            $out .= self::render(['case', 'req/run', 'req/s med [min-max]', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms', 'peak MiB', 'growth B', 'errors', 'conns', 'max strm', 'note'], $rows);
        }

        return $out;
    }

    private static function med(array $stat, int $dec): string
    {
        return isset($stat['median']) ? number_format((float) $stat['median'], $dec, '.', '') : '-';
    }

    private static function range(array $stat, int $dec): string
    {
        if (! isset($stat['median'])) {
            return '-';
        }

        return number_format($stat['median'], $dec, '.', '').' ['.number_format($stat['min'], $dec, '.', '').'-'.number_format($stat['max'], $dec, '.', '').']';
    }

    /** @param list<string> $head @param list<list<string>> $rows */
    public static function render(array $head, array $rows): string
    {
        $w = array_map('strlen', $head);
        foreach ($rows as $r) {
            foreach ($r as $i => $cell) {
                $w[$i] = max($w[$i], strlen($cell));
            }
        }
        $line = fn (array $cells) => implode(' | ', array_map(fn ($c, $i) => str_pad($c, $w[$i]), $cells, array_keys($cells)))."\n";
        $out = $line($head).implode('-+-', array_map(fn ($x) => str_repeat('-', $x), $w))."\n";
        foreach ($rows as $r) {
            $out .= $line($r);
        }

        return $out;
    }
}
