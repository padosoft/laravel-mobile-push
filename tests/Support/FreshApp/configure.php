<?php

/*
 * Prepares a freshly created Laravel application for the smoke run of the "fresh-app" CI job (not a test of the suite):
 *
 *     php tests/Support/FreshApp/configure.php /path/to/app
 *
 * It does by script what the README asks a person to do after `mobile-push:install`: it uncomments the two example
 * connections in config/mobile-push.php, points them at keys generated here (no real credential exists anywhere), adds
 * the device trait to the User model and appends the variables to .env.
 */

declare(strict_types=1);

$app = rtrim($argv[1] ?? '', '/');
if ($app === '' || ! is_file($app.'/artisan') || ! is_file($app.'/config/mobile-push.php')) {
    fwrite(STDERR, "Usage: php configure.php <laravel app with config/mobile-push.php published>\n");
    exit(2);
}

// Keys generated on the spot.
@mkdir($app.'/storage/push', 0700, true);
openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $p8);
file_put_contents($app.'/storage/push/AuthKey.p8', $p8);
openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), $rsa);
file_put_contents($app.'/storage/push/firebase.json', json_encode([
    'type' => 'service_account',
    'project_id' => 'demo-project',
    'private_key_id' => 'demo-key',
    'private_key' => $rsa,
    'client_email' => 'push@demo-project.iam.gserviceaccount.com',
], JSON_THROW_ON_ERROR));

// The connections block of the published config: take the commented example and make it real.
$config = (string) file_get_contents($app.'/config/mobile-push.php');
if (preg_match("/\\|   'ios' => \\[.*?\\n    \\|   \\],\\n    \\|\\n    \\|   'android' => \\[.*?\\n    \\|   \\],\\n/s", $config, $example) !== 1) {
    fwrite(STDERR, "The commented connection example was not found in config/mobile-push.php.\n");
    exit(1);
}
$connections = preg_replace('/^ *\|   /m', '        ', $example[0]);
$connections = preg_replace('/^ *\| *$/m', '', (string) $connections);
$placeholder = "    'connections' => [\n        // 'ios' => [...],\n        // 'android' => [...],\n    ],";
if (! str_contains($config, $placeholder)) {
    fwrite(STDERR, "The empty 'connections' block was not found in config/mobile-push.php.\n");
    exit(1);
}
file_put_contents($app.'/config/mobile-push.php', str_replace($placeholder, "    'connections' => [\n".$connections.'    ],', $config));

// The device trait on the User model.
$model = (string) file_get_contents($app.'/app/Models/User.php');
if (! str_contains($model, 'HasPushDevices')) {
    $model = preg_replace('/^use Illuminate\\\\Notifications\\\\Notifiable;$/m', "use Illuminate\\Notifications\\Notifiable;\nuse Padosoft\\MobilePush\\Laravel\\Devices\\HasPushDevices;", $model, 1);
    $model = preg_replace('/(\n    use [^;]*)Notifiable;/', '$1HasPushDevices, Notifiable;', (string) $model, 1);
    file_put_contents($app.'/app/Models/User.php', $model);
}
if (! str_contains((string) file_get_contents($app.'/app/Models/User.php'), 'HasPushDevices, Notifiable;')) {
    fwrite(STDERR, "Could not add HasPushDevices to app/Models/User.php.\n");
    exit(1);
}

file_put_contents($app.'/.env', "\n".implode("\n", [
    'MOBILE_PUSH_MODE=auto',
    'MOBILE_PUSH_DELIVERY_PROFILE=durable',
    'MOBILE_PUSH_DEVICES_ENABLED=true',
    'MOBILE_PUSH_LIVE_ACTIVITIES_ENABLED=true',
    'MOBILE_PUSH_LOG_SINKS=database',
    'MOBILE_PUSH_LOG_DB_MODE=all',
    'MOBILE_PUSH_CAMPAIGNS_ENABLED=true',
    'MOBILE_PUSH_RECEIPTS_ENABLED=true',
    'MOBILE_PUSH_APNS_TEAM_ID=TEAM123456',
    'MOBILE_PUSH_APNS_KEY_ID=KEY1234567',
    'MOBILE_PUSH_APNS_KEY_PATH='.$app.'/storage/push/AuthKey.p8',
    'MOBILE_PUSH_APNS_BUNDLE_ID=com.example.shop',
    'MOBILE_PUSH_APNS_ENVIRONMENT=sandbox',
    'MOBILE_PUSH_FCM_CREDENTIALS_PATH='.$app.'/storage/push/firebase.json',
])."\n", FILE_APPEND);

echo "configured: two connections (ios, android), HasPushDevices on User, .env written\n";
