<?php

/*
 * For the Octane CI jobs (not a test of the suite): runs the protocol-level HTTP/2 mock server next to a freshly
 * created application, so that the application's real transport (libcurl, its connection pool) can be exercised
 * under a long-lived runtime and the SERVER can say how many connections it saw.
 *
 *     php tests/Support/FreshApp/wire-mock.php /path/to/state.json &
 *
 * Writes {baseUrl, ca} to the state file once the server listens, then waits. When "<state>.stop" appears it writes
 * the server's counters to "<state>.stats" and exits. Key material is generated at runtime and deleted on exit.
 */

declare(strict_types=1);

require dirname(__DIR__).'/H2Mock/MockServer.php';

use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

$state = $argv[1] ?? '';
if ($state === '') {
    fwrite(STDERR, "Usage: php wire-mock.php <state file>\n");
    exit(2);
}
if (! MockServer::available()) {
    fwrite(STDERR, MockServer::unavailableReason()."\n");
    exit(1);
}

$server = MockServer::start();
file_put_contents($state, json_encode(['baseUrl' => $server->ipBaseUrl(), 'ca' => $server->caBundlePath()], JSON_THROW_ON_ERROR));

$deadline = time() + 600;
while (! is_file($state.'.stop') && time() < $deadline) {
    usleep(100_000);
}

$stats = $server->stats();
file_put_contents($state.'.stats', json_encode([
    'received' => $stats['received'] ?? null,
    'processed' => $stats['processed'] ?? null,
    'connections' => $stats['connections'] ?? null,
    'sessions' => $stats['sessions'] ?? null,
    'alpn' => $stats['alpn'] ?? null,
    'perSession' => array_values(array_map(static fn (array $s): int => (int) $s['received'], $stats['perSession'] ?? [])),
], JSON_THROW_ON_ERROR));
$server->stop();
