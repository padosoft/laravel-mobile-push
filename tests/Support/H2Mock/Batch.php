<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Support\H2Mock;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;

/**
 * Small helpers shared by the transport scenarios.
 */
final class Batch
{
    /**
     * Lazily generated POST requests: ids r0..r{n-1}, paths /r/0.. /r/{n-1}.
     *
     * @return \Generator<int, Request>
     */
    public static function requests(string $baseUrl, int $count, string $body = '{"aps":{"alert":"hello"}}', ?int $timeoutMs = null): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            yield new Request("r{$i}", 'POST', "{$baseUrl}/r/{$i}", ['content-type' => 'application/json', 'x-seq' => (string) $i], $body, $timeoutMs);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides  named ConnectionProfile arguments
     */
    public static function profile(MockServer $server, string $poolKey = 'pool-a', array $overrides = []): ConnectionProfile
    {
        /** @phpstan-ignore argument.type */
        return new ConnectionProfile(...array_merge([
            'poolKey' => $poolKey,
            'caBundlePath' => $server->caBundlePath(),
            'connectTimeoutMs' => 2000,
            'timeoutMs' => 5000,
        ], $overrides));
    }

    /**
     * Consume a send() generator completely.
     *
     * @param  iterable<string, Exchange>  $exchanges
     * @return array<string, list<Exchange>> request id => every exchange yielded for it (must be exactly one)
     */
    public static function collect(iterable $exchanges): array
    {
        $byId = [];
        foreach ($exchanges as $id => $exchange) {
            $byId[$id][] = $exchange;
        }

        return $byId;
    }

    /**
     * How many times the server processed / received each path.
     *
     * @param  list<array{path: string, processed: bool}>  $recorded
     * @return array{processed: array<string, int>, received: array<string, int>}
     */
    public static function serverCounts(array $recorded): array
    {
        $processed = [];
        $received = [];
        foreach ($recorded as $r) {
            $received[$r['path']] = ($received[$r['path']] ?? 0) + 1;
            if ($r['processed']) {
                $processed[$r['path']] = ($processed[$r['path']] ?? 0) + 1;
            }
        }

        return ['processed' => $processed, 'received' => $received];
    }

    /** A TCP port with nothing listening on it. */
    public static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('cannot allocate a port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
