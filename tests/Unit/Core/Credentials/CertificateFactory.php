<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Unit\Core\Credentials;

/** Builds throw-away APNs-looking certificates at runtime. No key material lives in the repository. */
final class CertificateFactory
{
    /**
     * @param  list<string>  $topics  encoded into Apple extension 1.2.840.113635.100.6.3.4
     * @return array{cert: string, key: string, pkcs12: callable(string): string}
     */
    public static function make(
        string $commonName = 'Apple Push Services: com.example.app',
        string $team = 'TEAM123456',
        array $topics = [],
        bool $universal = false,
        int $days = 365,
        ?\OpenSSLAsymmetricKey $key = null,
    ): array {
        $key ??= self::newKey();
        $config = self::config($topics, $universal);

        $dn = ['commonName' => $commonName, 'organizationalUnitName' => $team, 'organizationName' => 'Example Org', 'countryName' => 'US'];
        $csr = openssl_csr_new($dn, $key, ['config' => $config, 'digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new \RuntimeException('csr failed');
        }
        $cert = openssl_csr_sign($csr, null, $key, $days, ['config' => $config, 'x509_extensions' => 'v3_apns', 'digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new \RuntimeException('sign failed');
        }
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        @unlink($config);

        return [
            'cert' => (string) $certPem,
            'key' => (string) $keyPem,
            'pkcs12' => static function (string $passphrase) use ($cert, $key): string {
                openssl_pkcs12_export($cert, $out, $key, $passphrase);

                return (string) $out;
            },
        ];
    }

    public static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new \RuntimeException('key failed');
        }

        return $key;
    }

    /** @param  list<string>  $topics */
    private static function config(array $topics, bool $universal): string
    {
        $lines = ['[req]', 'distinguished_name = dn', 'prompt = no', '[dn]', 'CN = x', '[v3_apns]', 'basicConstraints = CA:FALSE'];
        if ($topics !== []) {
            $seq = '';
            foreach ($topics as $topic) {
                $seq .= self::tlv(0x0C, $topic).self::tlv(0x30, self::tlv(0x0C, 'app'));
            }
            $lines[] = '1.2.840.113635.100.6.3.4 = DER:'.bin2hex(self::tlv(0x30, $seq));
        }
        if ($universal) {
            $lines[] = '1.2.840.113635.100.6.3.6 = DER:0500';
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'mpcert');
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private static function tlv(int $tag, string $content): string
    {
        $length = strlen($content);
        $encoded = $length < 128 ? chr($length) : chr(0x81).chr($length);

        return chr($tag).$encoded.$content;
    }
}
