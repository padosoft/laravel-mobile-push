<?php

/*
 * Writes into config/horizon.php of a fresh application the two supervisors that `mobile-push:install` prints, for
 * the "runtimes" CI job (not a test of the suite):
 *
 *     php tests/Support/FreshApp/horizon.php /path/to/app
 */

declare(strict_types=1);

$app = rtrim($argv[1] ?? '', '/');
$file = $app.'/config/horizon.php';
if (! is_file($file)) {
    fwrite(STDERR, "Usage: php horizon.php <laravel app with laravel/horizon installed>\n");
    exit(2);
}

$supervisors = <<<'PHP'
        'local' => [
            'push' => [
                'connection' => 'redis',
                'queue' => ['push'],
                'balance' => false,
                'processes' => 3,
                'tries' => 1,
                'timeout' => 55,
                'memory' => 256,
            ],
            'push-bulk' => [
                'connection' => 'redis',
                'queue' => ['push-bulk'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 1,
                'timeout' => 55,
                'memory' => 256,
            ],
        ],
PHP;

$config = (string) file_get_contents($file);
$patched = preg_replace("/        'local' => \\[\\n.*?\\n        \\],/s", $supervisors, $config, 1, $count);
if ($count !== 1 || ! is_string($patched)) {
    fwrite(STDERR, "The 'local' environment block was not found in config/horizon.php.\n");
    exit(1);
}
file_put_contents($file, $patched);
echo "configured: Horizon supervisors push and push-bulk (local environment)\n";
