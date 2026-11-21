<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

/**
 * In-memory connections for host tests that have no credentials. Keys are generated at runtime and live only in
 * the process; no key material is shipped with the package.
 *
 * @internal
 */
final class TestConnections
{
    public const BUNDLE = 'com.example.fake';

    public const PROJECT = 'fake-project';

    /** @var array<string, string> */
    private static array $pems = [];

    /**
     * Connections the intent fake registers when the application configures none (nothing is ever resolved from
     * them: the fake driver replaces the real one).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function intent(): array
    {
        return [
            'ios' => ['driver' => 'apns', 'bundle_id' => self::BUNDLE, 'team_id' => 'FAKETEAM01', 'environment' => 'sandbox', 'fake' => true],
            'android' => ['driver' => 'fcm', 'project_id' => self::PROJECT, 'fake' => true],
        ];
    }

    /**
     * Fully valid connections, with freshly generated keys, for the real pipeline behind fakeTransport().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function wire(): array
    {
        return [
            'ios' => [
                'driver' => 'apns',
                'auth' => 'token',
                'team_id' => 'FAKETEAM01',
                'key_id' => 'FAKEKEY001',
                'key' => ['path' => null, 'base64' => base64_encode(self::pem('ec'))],
                'bundle_id' => self::BUNDLE,
                'environment' => 'sandbox',
            ],
            'android' => [
                'driver' => 'fcm',
                'auth' => 'service_account',
                'credentials' => ['path' => null, 'base64' => base64_encode((string) json_encode([
                    'type' => 'service_account',
                    'project_id' => self::PROJECT,
                    'private_key_id' => 'fake-key-1',
                    'private_key' => self::pem('rsa'),
                    'client_email' => 'push@fake-project.iam.gserviceaccount.com',
                ]))],
                'project_id' => null,
            ],
        ];
    }

    private static function pem(string $type): string
    {
        return self::$pems[$type] ??= self::generate($type === 'ec'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }

    /** @param  array<string, mixed>  $options */
    private static function generate(array $options): string
    {
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a test key: is the OpenSSL extension available?');
        }
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }
}
