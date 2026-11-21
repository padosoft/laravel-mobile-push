<?php

/*
 * Compares Google's live FCM Discovery document with the snapshot the message builders and the tests are pinned to
 * (tests/Fixtures/fcm/discovery-v1.json). Not a test of the suite: run by the scheduled "fcm-discovery" workflow, or
 * by hand:
 *
 *     php tests/Support/Probes/fcm-discovery-drift.php
 *
 * Exit 0: identical. Exit 1: the API description changed (a summary is printed: review it, update the builders if a
 * field the package uses changed, then refresh the snapshot and its .meta.json). Exit 2: the document could not be
 * fetched or parsed (nothing is concluded).
 */

declare(strict_types=1);

const DISCOVERY_URL = 'https://fcm.googleapis.com/$discovery/rest?version=v1';

$fixture = dirname(__DIR__, 2).'/Fixtures/fcm/discovery-v1.json';
$snapshot = json_decode((string) file_get_contents($fixture), true);

$curl = curl_init(DISCOVERY_URL);
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
if (is_string(getenv('SSL_CERT_FILE')) && getenv('SSL_CERT_FILE') !== '') {
    curl_setopt($curl, CURLOPT_CAINFO, getenv('SSL_CERT_FILE'));
}
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$live = is_string($body) ? json_decode($body, true) : null;
if ($status !== 200 || ! is_array($live) || ! is_array($snapshot)) {
    fwrite(STDERR, sprintf("Could not fetch or parse the Discovery document (HTTP %d, %s).\n", $status, curl_error($curl) ?: 'no curl error'));
    exit(2);
}

/** @param array<mixed> $value */
function canonical(array $value): string
{
    $sort = static function (mixed $v) use (&$sort): mixed {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map($sort, $v);
    };

    return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * @param  array<mixed>  $document
 * @return array<string, array<mixed>>
 */
function methodsOf(array $document, string $prefix = ''): array
{
    $found = [];
    foreach ($document['resources'] ?? [] as $name => $resource) {
        foreach ($resource['methods'] ?? [] as $method => $definition) {
            $found[$prefix.$name.'.'.$method] = $definition;
        }
        $found += methodsOf($resource, $prefix.$name.'.');
    }

    return $found;
}

printf("snapshot revision %s, live revision %s\n", $snapshot['revision'] ?? '?', $live['revision'] ?? '?');
if (canonical($live) === canonical($snapshot)) {
    echo "identical: the pinned snapshot is current\n";
    exit(0);
}

echo "DIFFERENT: the FCM API description changed\n";
$liveSchemas = $live['schemas'] ?? [];
$oldSchemas = $snapshot['schemas'] ?? [];
foreach (array_diff(array_keys($liveSchemas), array_keys($oldSchemas)) as $name) {
    echo "  + schema {$name}\n";
}
foreach (array_diff(array_keys($oldSchemas), array_keys($liveSchemas)) as $name) {
    echo "  - schema {$name}\n";
}
foreach (array_intersect(array_keys($liveSchemas), array_keys($oldSchemas)) as $name) {
    if (canonical($liveSchemas[$name]) === canonical($oldSchemas[$name])) {
        continue;
    }
    $now = array_keys($liveSchemas[$name]['properties'] ?? []);
    $before = array_keys($oldSchemas[$name]['properties'] ?? []);
    printf("  ~ schema %s: added [%s], removed [%s]%s\n", $name, implode(', ', array_diff($now, $before)), implode(', ', array_diff($before, $now)), array_diff($now, $before) === [] && array_diff($before, $now) === [] ? ' (a property definition or description changed)' : '');
}
$liveMethods = methodsOf($live);
$oldMethods = methodsOf($snapshot);
foreach (array_diff(array_keys($liveMethods), array_keys($oldMethods)) as $name) {
    echo "  + method {$name}\n";
}
foreach (array_diff(array_keys($oldMethods), array_keys($liveMethods)) as $name) {
    echo "  - method {$name}\n";
}
foreach (array_intersect(array_keys($liveMethods), array_keys($oldMethods)) as $name) {
    if (canonical($liveMethods[$name]) !== canonical($oldMethods[$name])) {
        echo "  ~ method {$name}\n";
    }
}
exit(1);
