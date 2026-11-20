<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Auth;

/** Runtime-generated keys and small doubles for the auth tests. */
final class TestKeys
{
    /** @return array{pem: string, public: string} */
    public static function ec(string $curve = 'prime256v1'): array
    {
        return self::export(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
    }

    /** @return array{pem: string, public: string} */
    public static function rsa(int $bits = 2048): array
    {
        return self::export(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{pem: string, public: string}
     */
    private static function export(array $options): array
    {
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a test key.');
        }
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);

        return ['pem' => (string) $pem, 'public' => (string) ($details['key'] ?? '')];
    }
}
