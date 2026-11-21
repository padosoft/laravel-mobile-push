<?php

/*
 * For the "backends" CI workflow (not a test of the suite): makes the stock "sqs" queue connection of a freshly created
 * Laravel application talk to a local SQS-compatible server (ElasticMQ).
 *
 *     php tests/Support/FreshApp/sqs.php /path/to/app
 *
 * Laravel's config/queue.php has no endpoint key for SQS; the connector hands the whole array to the AWS SDK, so one
 * line is enough. Nothing else is changed: the application reads SQS_PREFIX, AWS_* and SQS_ENDPOINT from its .env.
 */

declare(strict_types=1);

$app = rtrim($argv[1] ?? '', '/');
$file = $app.'/config/queue.php';
if ($app === '' || ! is_file($file)) {
    fwrite(STDERR, "Usage: php sqs.php <laravel app>\n");
    exit(2);
}

$config = (string) file_get_contents($file);
if (! str_contains($config, "'endpoint' => env('SQS_ENDPOINT')")) {
    $patched = preg_replace("/('driver' => 'sqs',\n)/", "$1            'endpoint' => env('SQS_ENDPOINT'),\n", $config, 1, $count);
    if ($count !== 1) {
        fwrite(STDERR, "The sqs connection was not found in config/queue.php.\n");
        exit(1);
    }
    file_put_contents($file, $patched);
}

echo "configured: the sqs connection uses SQS_ENDPOINT\n";
