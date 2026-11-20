<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\HttpVersion;

/**
 * One persistent CurlMultiHandle for one pool key: its connection cache IS the pool.
 *
 * Several send() generators may use the same pool at the same time (a consumer can start a second send while
 * iterating the first). The multi handle is shared, so completion messages are routed to the generator that
 * owns each easy handle instead of being consumed by whoever happens to call curl_multi_info_read().
 *
 * @internal
 */
final class CurlPool
{
    /** Easy handles kept for reuse (curl_reset keeps the handle's DNS/TLS-session caches, drops options). */
    private const MAX_SPARE_HANDLES = 256;

    private \CurlMultiHandle $multi;

    /** @var list<\CurlHandle> */
    private array $spare = [];

    /** @var array<int, int> spl_object_id(easy) => owner token */
    private array $owner = [];

    /** @var array<int, list<array{handle: \CurlHandle, result: int}>> owner token => completed transfers */
    private array $completed = [];

    private int $users = 0;

    private float $lastUsedAt;

    private bool $closed = false;

    /** Protocol negotiated by the last completed transfer ('2', '1.1', ...), null while unknown. */
    public ?string $negotiated = null;

    public function __construct(public readonly string $key, ConnectionProfile $profile)
    {
        $this->multi = curl_multi_init();
        $this->lastUsedAt = microtime(true);
        $this->configure($profile);
    }

    public function configure(ConnectionProfile $profile): void
    {
        $maxConnections = max(1, $profile->maxConnections);
        curl_multi_setopt($this->multi, CURLMOPT_PIPELINING, $profile->httpVersion === HttpVersion::Http2 ? CURLPIPE_MULTIPLEX : 0);
        curl_multi_setopt($this->multi, CURLMOPT_MAX_HOST_CONNECTIONS, $maxConnections);
        curl_multi_setopt($this->multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, $maxConnections);
        // Our own per-connection stream cap (libcurl default 100). The peer's SETTINGS can only lower it.
        curl_multi_setopt($this->multi, CURLMOPT_MAX_CONCURRENT_STREAMS, max(1, $profile->window));
        // Size of the idle-connection cache of this multi handle.
        curl_multi_setopt($this->multi, CURLMOPT_MAXCONNECTS, $maxConnections);
    }

    public function multi(): \CurlMultiHandle
    {
        return $this->multi;
    }

    public function takeHandle(): \CurlHandle
    {
        $handle = array_pop($this->spare);
        if ($handle !== null) {
            return $handle;
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('curl_init() failed.');
        }

        return $handle;
    }

    /** Give back a handle that is no longer attached to the multi handle. */
    public function recycle(\CurlHandle $handle): void
    {
        if ($this->closed || count($this->spare) >= self::MAX_SPARE_HANDLES) {
            curl_close($handle);

            return;
        }
        curl_reset($handle);
        $this->spare[] = $handle;
    }

    public function add(\CurlHandle $handle, int $owner): int
    {
        $code = curl_multi_add_handle($this->multi, $handle);
        if ($code === CURLM_OK) {
            $this->owner[spl_object_id($handle)] = $owner;
        }

        return $code;
    }

    /** Detach a handle (aborting it if still running). Its completion, if queued, is discarded. */
    public function remove(\CurlHandle $handle): void
    {
        $id = spl_object_id($handle);
        if (isset($this->owner[$id])) {
            $owner = $this->owner[$id];
            unset($this->owner[$id]);
            curl_multi_remove_handle($this->multi, $handle);
            if (isset($this->completed[$owner])) {
                $this->completed[$owner] = array_values(array_filter(
                    $this->completed[$owner],
                    static fn (array $done): bool => $done['handle'] !== $handle,
                ));
            }
        }
    }

    /**
     * Drive the transfers and wait up to $waitSeconds for activity.
     */
    public function perform(float $waitSeconds): void
    {
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        $this->collect();
        if ($running > 0 && $waitSeconds > 0 && $this->completedCountTotal() === 0) {
            curl_multi_select($this->multi, $waitSeconds);
            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            $this->collect();
        }
    }

    /**
     * @return list<array{handle: \CurlHandle, result: int}>
     */
    public function takeCompleted(int $owner): array
    {
        $done = $this->completed[$owner] ?? [];
        unset($this->completed[$owner]);

        return $done;
    }

    public function acquire(): void
    {
        $this->users++;
        $this->lastUsedAt = microtime(true);
    }

    public function release(): void
    {
        $this->users = max(0, $this->users - 1);
        $this->lastUsedAt = microtime(true);
    }

    public function inUse(): bool
    {
        return $this->users > 0;
    }

    public function lastUsedAt(): float
    {
        return $this->lastUsedAt;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Controlled close: detach everything, close the multi handle (and with it every connection of this pool). */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        while (curl_multi_info_read($this->multi) !== false) {
            // drain pending completion messages
        }
        $this->owner = [];
        $this->completed = [];
        foreach ($this->spare as $handle) {
            curl_close($handle);
        }
        $this->spare = [];
        curl_multi_close($this->multi);
        $this->negotiated = null;
    }

    private function collect(): void
    {
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            if (($info['msg'] ?? null) !== CURLMSG_DONE || ! ($info['handle'] ?? null) instanceof \CurlHandle) {
                continue;
            }
            $handle = $info['handle'];
            $owner = $this->owner[spl_object_id($handle)] ?? null;
            if ($owner === null) {
                continue;
            }
            $this->completed[$owner][] = ['handle' => $handle, 'result' => is_int($info['result'] ?? null) ? $info['result'] : -1];
        }
    }

    private function completedCountTotal(): int
    {
        $n = 0;
        foreach ($this->completed as $list) {
            $n += count($list);
        }

        return $n;
    }
}
