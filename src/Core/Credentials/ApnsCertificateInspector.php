<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Reads an APNs client certificate (PEM, or PKCS#12 with a passphrase) without any network access.
 *
 * What is decoded from Apple's private extensions:
 *  - 1.2.840.113635.100.6.3.4 (topics): the extension DER is walked and every string that looks like a
 *    bundle id (contains a dot, no whitespace) is returned; the labels Apple stores next to them ("app",
 *    "voip", "complication", ...) are dropped. The exact meaning of each label is NOT decoded.
 *  - 1.2.840.113635.100.6.3.6: its mere presence marks a universal (sandbox and production) certificate.
 *    Its content is not interpreted.
 * If the extension is absent or the DER cannot be walked the topics list is empty: nothing is guessed.
 * Environment: universal if extension .6.3.6 is present; else sandbox if the CN contains "Development" or
 * "Sandbox"; else production if the CN contains "Production"; otherwise Unknown. A bare
 * "Apple Push Services: <bundle>" subject without the universal extension stays Unknown.
 */
final class ApnsCertificateInspector
{
    public const OID_TOPICS = '1.2.840.113635.100.6.3.4';

    public const OID_UNIVERSAL = '1.2.840.113635.100.6.3.6';

    /**
     * @param  string  $pem  a certificate, or a combined file that also holds the private key
     * @param  string|null  $privateKeyPem  key to check against the certificate (falls back to a key block in `$pem`)
     *
     * @throws ConfigurationException
     */
    public function fromPem(
        #[\SensitiveParameter] string $pem,
        #[\SensitiveParameter] ?string $privateKeyPem = null,
        #[\SensitiveParameter] ?string $passphrase = null,
    ): CertificateInfo {
        if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m) !== 1) {
            throw new ConfigurationException('No PEM certificate block was found. Provide the Apple push certificate as PEM ("-----BEGIN CERTIFICATE-----") or as a PKCS#12 file with its passphrase.');
        }
        $certPem = $m[0]."\n";

        $keyPem = $privateKeyPem;
        if ($keyPem === null && preg_match('/-----BEGIN (?:ENCRYPTED |RSA |EC )?PRIVATE KEY-----.+?-----END (?:ENCRYPTED |RSA |EC )?PRIVATE KEY-----/s', $pem, $k) === 1) {
            $keyPem = $k[0]."\n";
        }

        return $this->inspect($certPem, $keyPem, $passphrase);
    }

    /** @throws ConfigurationException */
    public function fromPkcs12(#[\SensitiveParameter] string $blob, #[\SensitiveParameter] string $passphrase): CertificateInfo
    {
        $read = [];
        $ok = self::quietly(static function () use ($blob, &$read, $passphrase): bool {
            return openssl_pkcs12_read($blob, $read, $passphrase);
        });
        self::drainErrors();
        /** @var mixed $bundle */
        $bundle = $read;
        $cert = is_array($bundle) ? ($bundle['cert'] ?? null) : null;
        if (! $ok || ! is_string($cert)) {
            throw new ConfigurationException('The PKCS#12 file could not be read: wrong passphrase or not a PKCS#12 file. Re-export it from Keychain Access with a passphrase, or convert it to PEM.');
        }
        $pkey = $bundle['pkey'] ?? null;
        $key = is_string($pkey) ? $pkey : null;
        unset($bundle, $read, $pkey);

        return $this->inspect($cert, $key, null);
    }

    private function inspect(#[\SensitiveParameter] string $certPem, #[\SensitiveParameter] ?string $keyPem, #[\SensitiveParameter] ?string $passphrase): CertificateInfo
    {
        $cert = self::quietly(static fn () => openssl_x509_read($certPem));
        self::drainErrors();
        $parsed = $cert === false ? false : openssl_x509_parse($cert);
        if ($cert === false || $parsed === false) {
            throw new ConfigurationException('The certificate could not be parsed. Check that the PEM block is complete and was not altered.');
        }

        $from = $parsed['validFrom_time_t'] ?? null;
        $to = $parsed['validTo_time_t'] ?? null;
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
        if (! is_int($from) || ! is_int($to) || ! is_string($fingerprint)) {
            throw new ConfigurationException('The certificate has no readable validity period.');
        }

        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $commonName = self::attribute($subject, 'CN');
        $teamId = self::attribute($subject, 'OU');

        $der = self::der($certPem);
        $extensions = $der === null ? [] : DerReader::certificateExtensions($der);

        return new CertificateInfo(
            commonName: $commonName,
            teamId: $teamId,
            notBefore: (new \DateTimeImmutable('@'.$from)),
            notAfter: (new \DateTimeImmutable('@'.$to)),
            fingerprintSha256: strtolower($fingerprint),
            topics: self::topics($extensions[self::OID_TOPICS] ?? null),
            kind: self::kind($commonName, isset($extensions[self::OID_UNIVERSAL])),
            keyMatches: $keyPem === null ? null : self::keyMatches($cert, $keyPem, $passphrase),
        );
    }

    private static function keyMatches(\OpenSSLCertificate $cert, #[\SensitiveParameter] string $keyPem, #[\SensitiveParameter] ?string $passphrase): bool
    {
        $key = self::quietly(static fn () => openssl_pkey_get_private($keyPem, $passphrase ?? ''));
        self::drainErrors();
        if ($key === false) {
            throw new ConfigurationException('The private key could not be parsed (wrong format or passphrase).');
        }
        $result = openssl_x509_check_private_key($cert, $key);
        self::drainErrors();

        return $result;
    }

    /** @return list<string> */
    private static function topics(?string $extension): array
    {
        if ($extension === null) {
            return [];
        }
        $topics = [];
        foreach (DerReader::strings($extension) as $string) {
            if (str_contains($string, '.') && preg_match('/^[A-Za-z0-9._\-]+$/', $string) === 1 && ! in_array($string, $topics, true)) {
                $topics[] = $string;
            }
        }

        return $topics;
    }

    private static function kind(?string $commonName, bool $universalExtension): ApnsCertificateKind
    {
        if ($universalExtension) {
            return ApnsCertificateKind::Universal;
        }
        $cn = strtolower($commonName ?? '');
        if (str_contains($cn, 'development') || str_contains($cn, 'sandbox')) {
            return ApnsCertificateKind::Sandbox;
        }
        if (str_contains($cn, 'production')) {
            return ApnsCertificateKind::Production;
        }

        return ApnsCertificateKind::Unknown;
    }

    /** @param  array<mixed>  $subject */
    private static function attribute(array $subject, string $name): ?string
    {
        $value = $subject[$name] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function der(#[\SensitiveParameter] string $certPem): ?string
    {
        $body = preg_replace('/-----(?:BEGIN|END) CERTIFICATE-----|\s+/', '', $certPem);
        $der = is_string($body) ? base64_decode($body, true) : false;

        return $der === false ? null : $der;
    }

    /**
     * Runs an OpenSSL call whose PHP warnings (which can echo input) are discarded.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T
     */
    private static function quietly(\Closure $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private static function drainErrors(): void
    {
        while (is_string(openssl_error_string())) {
            // never expose OpenSSL's error text
        }
    }
}
