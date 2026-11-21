<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Integration\ConfigReference;
use Padosoft\MobilePush\Laravel\Integration\ConfigSchema;

/*
 * "The configuration reference is complete and current": every key the package reads is in ConfigSchema, every
 * default there is the default the shipped config file produces in a clean environment, every key of the file is
 * documented, and docs/configuration.md is exactly what the schema renders.
 */

/** Literals that start with "mobile-push." but are not config keys (route, log and Telescope entry names). */
const MP_NOT_CONFIG = ['php', 'send', 'receipts.store', 'batch', 'result'];

/**
 * Readers that build the key from a relative name: file => [prefix, regex capturing the relative key].
 *
 * @return array<string, list<array{0: string, 1: string}>>
 */
function mpRelativeReaders(): array
{
    return [
        'src/Laravel/Health/HealthSettings.php' => [['', '/\$(?:string|int|bool|float)\(\'([a-z0-9_.]+)\'/'], ['', '/self::list\(\$config, \'([a-z0-9_.]+)\'/']],
        'src/Laravel/Logging/LoggingModule.php' => [['log.', '/self::int\(\$app, \'([a-z0-9_.]+)\'/']],
        'src/Laravel/Sending/SendingSettings.php' => [['', '/\$c->\w+\(\'([a-z0-9_.]+)\'/']],
        'src/Laravel/Console/Ops/PruneDevicesCommand.php' => [['devices.prune.', '/self::configured\(\'([a-z0-9_]+)\'/']],
        'src/Laravel/Console/Support/ConnectionInspector.php' => [['connections.*.', '/\$c->(?:string|int|bool|float|choice|raw|requiredString|nullableInt|stringList|error)\(\'([a-z0-9_.]+)\'/']],
        'src/Laravel/Sending/ConnectionFactory.php' => [['connections.*.', '/\$c->(?:string|int|bool|float|choice|raw|requiredString|nullableInt|stringList|error)\(\'([a-z0-9_.]+)\'/']],
    ];
}

/** @return array<string, list<string>> key => files reading it */
function mpKeysReadBySource(): array
{
    $root = dirname(__DIR__, 4);
    $keys = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $code = (string) file_get_contents($file->getPathname());
        preg_match_all('/[\'"]mobile-push\.([A-Za-z0-9_.*]+)/', $code, $m);
        foreach ($m[1] as $key) {
            $key = rtrim($key, '.');
            if ($key !== '' && ! in_array($key, MP_NOT_CONFIG, true)) {
                $keys[$key][] = $relative;
            }
        }
        foreach (mpRelativeReaders()[$relative] ?? [] as [$prefix, $regex]) {
            preg_match_all($regex, $code, $r);
            foreach ($r[1] as $key) {
                $keys[$prefix.$key][] = $relative;
            }
        }
    }
    ksort($keys);

    return $keys;
}

/** The shipped config file evaluated in a fresh PHP process with no MOBILE_PUSH_* (or any other) variable set. */
function mpCleanConfig(): array
{
    $root = dirname(__DIR__, 4);
    $script = 'require '.var_export($root.'/vendor/autoload.php', true).'; echo serialize(require '.var_export($root.'/config/mobile-push.php', true).');';
    $process = proc_open(['php', '-d', 'variables_order=EGPCS', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => (string) getenv('PATH')]);
    expect($process)->not->toBeFalse();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);
    $config = @unserialize((string) $out);
    expect($config)->toBeArray('config file did not evaluate: '.$err);

    return $config;
}

it('documents every key the package reads', function () {
    $missing = [];
    foreach (mpKeysReadBySource() as $key => $files) {
        if (! ConfigSchema::covers($key)) {
            $missing[] = $key.' (read in '.implode(', ', array_unique($files)).')';
        }
    }

    expect($missing)->toBe([]);
});

it('uses in the schema exactly the defaults the config file produces in a clean environment', function () {
    $config = mpCleanConfig();
    $wrong = [];
    foreach (ConfigSchema::keys() as $key) {
        if ($key->perConnection()) {
            continue;
        }
        $segments = $key->segments();
        $value = $config;
        $present = true;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                $present = false;

                break;
            }
            $value = $value[$segment];
        }
        if (! $present) {
            $wrong[] = $key->key.': not in config/mobile-push.php';
        } elseif ($value !== $key->default) {
            $wrong[] = $key->key.': schema '.var_export($key->default, true).', config file '.var_export($value, true);
        }
    }

    expect($wrong)->toBe([]);
});

it('has a schema entry for every key of the config file', function () {
    $undocumented = [];
    $walk = function (array $node, string $path) use (&$walk, &$undocumented): void {
        foreach ($node as $name => $value) {
            $key = $path === '' ? (string) $name : $path.'.'.$name;
            if (ConfigSchema::find($key) !== null) {
                continue;
            }
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $walk($value, $key);

                continue;
            }
            $undocumented[] = $key;
        }
    };
    $walk(mpCleanConfig(), '');

    expect($undocumented)->toBe([]);
});

it('knows every file that composes a key from a variable, so no reader is invisible to the scan', function () {
    // Keys composed in these files are listed in the schema by hand (privacy key rings: index/encryption pairs).
    $manual = ['src/Laravel/Privacy/PrivacyModule.php'];
    $root = dirname(__DIR__, 4);
    $unknown = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($file->getExtension() === 'php' && preg_match('/[\'"]mobile-push\.(?!connections\.[\'"])[a-z0-9_.]*[\'"]\s*\.\s*\$/', (string) file_get_contents($file->getPathname())) === 1
            && ! isset(mpRelativeReaders()[$relative]) && ! in_array($relative, $manual, true)) {
            $unknown[] = $relative;
        }
    }

    expect($unknown)->toBe([]);
});

it('finds the keys of every module, so the scanner itself cannot silently miss a reader', function () {
    $read = array_keys(mpKeysReadBySource());

    expect($read)->toContain('ledger.lease_seconds', 'queue.timeout', 'metrics.max_series', 'alerts.error_rate.min_sample', 'retry.jitter', 'log.buffer.size', 'connections.*.bundle_id', 'connections.*.live.only_to', 'receipts.marker_ttl')
        ->and(ConfigSchema::covers('connections.ios.driver'))->toBeTrue()
        ->and(ConfigSchema::covers('connections.ios.not_a_key'))->toBeFalse()
        ->and(ConfigSchema::covers('log.levels.accepted'))->toBeTrue()
        ->and(ConfigSchema::covers('queue.no_such_key'))->toBeFalse();
});

it('ships a docs/configuration.md that is exactly what the schema renders', function () {
    expect(ConfigReference::isCurrent())->toBeTrue('docs/configuration.md is stale: run ConfigReference::write()')
        ->and(ConfigReference::toMarkdown())->toContain('| `delivery.profile` | `MOBILE_PUSH_DELIVERY_PROFILE` | `\'light\'`')
        ->and(ConfigReference::toMarkdown())->toContain("'driver' => 'apns'")->toContain("'driver' => 'fcm'");
});
