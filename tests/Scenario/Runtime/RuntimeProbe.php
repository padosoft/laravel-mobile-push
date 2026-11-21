<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Runtime;

use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * What a long-lived process can observe about itself (memory, open descriptors) and small helpers shared by the
 * runtime scenarios. Every count of connections and requests comes from the mock server, never from here.
 */
final class RuntimeProbe
{
    /** Open file descriptors of this process (Linux /proc), null where /proc is not available. */
    public static function openDescriptors(): ?int
    {
        if (! is_dir('/proc/self/fd')) {
            return null;
        }
        $entries = @scandir('/proc/self/fd');

        // scandir itself holds one descriptor on the directory while it reads it.
        return $entries === false ? null : count($entries) - 3;
    }

    /** Open TCP sockets of this process (descriptors that point at a socket). */
    public static function openSockets(): ?int
    {
        if (! is_dir('/proc/self/fd')) {
            return null;
        }
        $n = 0;
        foreach (@scandir('/proc/self/fd') ?: [] as $fd) {
            // skip the dots and the descriptor scandir() used (already closed): readlink() would warn
            if ($fd === '.' || $fd === '..' || ! is_link('/proc/self/fd/'.$fd)) {
                continue;
            }
            $target = @readlink('/proc/self/fd/'.$fd);
            if (is_string($target) && str_starts_with($target, 'socket:')) {
                $n++;
            }
        }

        return $n;
    }

    public static function memory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }

    /**
     * Resident set size of this process, with the source it came from: `VmRSS` of /proc/self/status (current RSS,
     * Linux) or, where /proc does not exist (macOS), `ru_maxrss` of getrusage() (PEAK RSS: bytes on macOS, kilobytes
     * on Linux). Null when neither is available; callers must then skip the RSS assertion, never fail.
     *
     * @return array{bytes: int, source: string}|null
     */
    public static function rss(): ?array
    {
        gc_collect_cycles();
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $m) === 1) {
            return ['bytes' => (int) $m[1] * 1024, 'source' => 'VmRSS (/proc/self/status)'];
        }
        $peak = function_exists('getrusage') ? (getrusage()['ru_maxrss'] ?? 0) : 0;
        if (is_int($peak) && $peak > 0) {
            return ['bytes' => PHP_OS_FAMILY === 'Darwin' ? $peak : $peak * 1024, 'source' => 'ru_maxrss (getrusage, peak RSS)'];
        }

        return null;
    }

    /**
     * Asserts that the process RSS grew by less than `$bound` bytes between two readings of rss(). Skips the test
     * (with the reason) when the platform cannot report RSS. Call it LAST in a test: the skip ends the test.
     *
     * @param  array{bytes: int, source: string}|null  $from
     * @param  array{bytes: int, source: string}|null  $to
     */
    public static function assertRssGrowthBelow(?array $from, ?array $to, int $bound, string $what): void
    {
        if ($from === null || $to === null) {
            throw new SkippedWithMessageException('RSS assertion skipped: this platform reports neither VmRSS nor ru_maxrss.');
        }
        $growth = $to['bytes'] - $from['bytes'];
        Assert::assertLessThan(
            $bound,
            $growth,
            sprintf('RSS grew by %d KiB %s (bound %d KiB, source %s)', intdiv($growth, 1024), $what, intdiv($bound, 1024), $to['source']),
        );
    }

    /** CPU seconds (user + system) used by this process so far. */
    public static function cpuSeconds(): float
    {
        $r = getrusage();

        return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
    }

    /**
     * Requests whose id and path carry the sender's tag, so that whoever receives an answer can check it is the
     * answer to its own request: the server echoes the tag in the body (see tagRules()).
     *
     * @return \Generator<int, Request>
     */
    public static function tagged(string $baseUrl, string $tag, int $count, int $from = 0): \Generator
    {
        for ($i = $from; $i < $from + $count; $i++) {
            yield new Request("{$tag}-{$i}", 'POST', "{$baseUrl}/{$tag}/{$i}", ['content-type' => 'application/json'], '{"aps":{"alert":"'.$tag.'"}}');
        }
    }

    /**
     * Server rules that answer every request under /<tag>/ with the body "<tag>/<n>" (the request's own path).
     *
     * @param  list<string>  $tags
     * @return list<array<string, mixed>>
     */
    public static function tagRules(array $tags, int $count, int $delayMs = 0): array
    {
        $rules = [];
        foreach ($tags as $tag) {
            for ($i = 0; $i < $count; $i++) {
                $rules[] = ['path' => '^/'.preg_quote($tag, '/').'/'.$i.'$', 'action' => 'respond', 'status' => 200, 'body' => $tag.'/'.$i, 'delayMs' => $delayMs];
            }
        }

        return $rules;
    }

    /**
     * Problems in a set of exchanges received by the sender tagged `$tag`: duplicates, missing ids, foreign ids,
     * failures, and answers whose body is not the answer to that very request. Empty = everything is right.
     *
     * @param  iterable<string, Exchange>  $exchanges
     * @return list<string>
     */
    public static function problems(iterable $exchanges, string $tag, int $count, int $from = 0): array
    {
        $problems = [];
        $seen = [];
        foreach ($exchanges as $id => $exchange) {
            if (isset($seen[$id])) {
                $problems[] = "duplicate {$id}";
            }
            $seen[$id] = true;
            if (! str_starts_with($id, $tag.'-')) {
                $problems[] = "foreign {$id}";

                continue;
            }
            $n = substr($id, strlen($tag) + 1);
            if ($exchange->response === null) {
                $problems[] = "failed {$id}: ".($exchange->failure?->message ?? '?');
            } elseif ($exchange->response->body !== $tag.'/'.$n) {
                $problems[] = "wrong answer for {$id}: ".$exchange->response->body;
            }
        }
        for ($i = $from; $i < $from + $count; $i++) {
            if (! isset($seen["{$tag}-{$i}"])) {
                $problems[] = "missing {$tag}-{$i}";
            }
        }

        return $problems;
    }

    /** Polls the server until `$key` equals `$value` (connections close asynchronously); returns the last stats. */
    public static function settle(MockServer $server, string $key, int $value, int $timeoutMs = 2000): array
    {
        return $server->waitFor(static fn (array $s): bool => ($s[$key] ?? null) === $value, $timeoutMs);
    }
}
