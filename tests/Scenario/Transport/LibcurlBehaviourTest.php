<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlErrorClassifier;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/*
 * Characterisation of the runtime, not of our code: raw curl_multi against the mock, pinning the libcurl
 * behaviour the transport's design depends on (docs/transport.md, "Investigation"). If a libcurl upgrade
 * changes any of this, these tests fail and the design must be re-evaluated.
 */

$server = null;

beforeAll(function () use (&$server): void {
    if (MockServer::available()) {
        $server = MockServer::start();
    }
});

afterAll(function () use (&$server): void {
    $server?->stop();
});

beforeEach(function () use (&$server): void {
    if ($server === null) {
        $this->markTestSkipped(MockServer::unavailableReason());
    }
    $server->reset();
});

/**
 * @param  'postfields'|'readfunction'  $bodyMode
 * @return array<string, array{errno: int, error: string}>
 */
function libcurlRawMultiBatch(MockServer $server, int $count, string $bodyMode): array
{
    $multi = curl_multi_init();
    curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 1);
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $body = '{"i":'.$i.'}';
        $handle = curl_init($server->baseUrl()."/r/{$i}");
        $options = [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS, CURLOPT_PIPEWAIT => true, CURLOPT_CAINFO => $server->caBundlePath(),
            CURLOPT_PROXY => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 3000, CURLOPT_HTTPHEADER => ['expect:'],
        ];
        if ($bodyMode === 'postfields') {
            $options[CURLOPT_POSTFIELDS] = $body;
        } else {
            $options += [
                CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILESIZE => strlen($body),
                CURLOPT_READFUNCTION => (static function (string $body): Closure {
                    $sent = false;

                    return function () use (&$sent, $body): string {
                        $chunk = $sent ? '' : $body;
                        $sent = true;

                        return $chunk;
                    };
                })($body),
            ];
        }
        curl_setopt_array($handle, $options);
        curl_multi_add_handle($multi, $handle);
        $handles["r{$i}"] = $handle;
    }
    $results = [];
    do {
        curl_multi_exec($multi, $running);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $id = array_search($info['handle'], $handles, true);
            $results[(string) $id] = ['errno' => (int) $info['result'], 'error' => curl_error($info['handle'])];
        }
        if ($running > 0) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running > 0 || count($results) < $count);
    curl_multi_close($multi);

    return $results;
}

/** @return array<string, int> path => times received */
function libcurlReceivedPerPath(MockServer $server): array
{
    $counts = [];
    foreach ($server->requests() as $r) {
        $counts[$r['path']] = ($counts[$r['path']] ?? 0) + 1;
    }

    return $counts;
}

it('libcurl silently re-sends a REFUSED_STREAM request when the body is in memory', function () use (&$server): void {
    $server->rules(['path' => '^/r/2$', 'action' => 'refuse', 'times' => 1]);
    $results = libcurlRawMultiBatch($server, 4, 'postfields');

    expect($results['r2']['errno'])->toBe(0) // looks like a plain success to the caller...
        ->and(libcurlReceivedPerPath($server)['/r/2'])->toBe(2); // ...but the server saw it twice
});

it('libcurl gives up a stream refused every time after 5 retries with errno 56', function () use (&$server): void {
    $server->rules(['path' => '^/r/1$', 'action' => 'refuse']);
    $results = libcurlRawMultiBatch($server, 2, 'postfields');

    expect($results['r1']['errno'])->toBe(CurlErrorClassifier::RECV_ERROR)
        ->and($results['r1']['error'])->toContain('tried 5 times')
        ->and(libcurlReceivedPerPath($server)['/r/1'])->toBe(6);
});

it('libcurl silently re-sends streams above a GOAWAY last-stream-id when the body is in memory', function () use (&$server): void {
    $server->rules(['path' => '^/r/[0-2]$', 'delayMs' => 50], ['path' => '^/r/3$', 'action' => 'goaway', 'lastStreamId' => 'previous', 'times' => 1]);
    $results = libcurlRawMultiBatch($server, 6, 'postfields');

    expect(array_column($results, 'errno'))->each->toBe(0)
        ->and(libcurlReceivedPerPath($server)['/r/3'])->toBe(2)
        ->and($server->stats()['connections'])->toBe(2);
});

it('a read-callback body without a seek callback turns every such re-send into errno 65', function () use (&$server): void {
    $server->rules(['path' => '^/r/2$', 'action' => 'refuse']);
    $results = libcurlRawMultiBatch($server, 4, 'readfunction');

    expect($results['r2']['errno'])->toBe(CurlErrorClassifier::SEND_FAIL_REWIND)
        // "necessary data rewind wasn't possible" up to libcurl 8.5, "was not possible" in later releases.
        ->and($results['r2']['error'])->toMatch('/^necessary data rewind (wasn\'t|was not) possible$/')
        ->and(libcurlReceivedPerPath($server)['/r/2'])->toBe(1);
});

it('curl_upkeep() sends no HTTP/2 PING for a connection owned by a multi handle', function () use (&$server): void {
    $multi = curl_multi_init();
    $handle = curl_init($server->baseUrl().'/upkeep');
    curl_setopt_array($handle, [CURLOPT_CAINFO => $server->caBundlePath(), CURLOPT_PROXY => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_UPKEEP_INTERVAL_MS => 100]);
    curl_multi_add_handle($multi, $handle);
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0);
    curl_multi_remove_handle($multi, $handle);

    for ($i = 0; $i < 3; $i++) {
        usleep(150_000);
        curl_upkeep($handle);
    }

    expect($server->stats()['pings'])->toBe(0)->and($server->stats()['openConnections'])->toBe(1);
    curl_multi_close($multi);
});
