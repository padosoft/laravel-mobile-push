<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Exceptions\TransportConfigurationException;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\HttpVersion;

/**
 * What the PHP curl extension and the libcurl it is linked against can actually do.
 *
 * assertSupports() runs before any request of a send() is handed to libcurl and throws
 * TransportConfigurationException with a concrete remedy when the profile needs something missing.
 */
final readonly class CurlCapabilities
{
    /** Oldest libcurl accepted: multiplexing by default (7.62), CURLOPT_MAXAGE_CONN (7.65), CURLINFO_*_TIME_T (7.61). */
    public const MIN_LIBCURL = 0x074400; // 7.68.0

    /** CURLOPT_SSLCERT_BLOB / CURLOPT_SSLKEY_BLOB. */
    public const MIN_LIBCURL_BLOB = 0x074700; // 7.71.0

    /**
     * @param  list<string>  $missingConstants
     */
    public function __construct(
        public bool $extensionLoaded,
        public string $libcurlVersion,
        public int $libcurlVersionNumber,
        public string $sslVersion,
        public bool $http2,
        public bool $ssl,
        public bool $blobOptions,
        public array $missingConstants,
    ) {}

    public static function detect(): self
    {
        static $detected = null;
        if ($detected instanceof self) {
            return $detected;
        }

        if (! extension_loaded('curl') || ! function_exists('curl_version')) {
            return $detected = new self(false, '', 0, '', false, false, false, []);
        }

        $version = curl_version();
        $version = is_array($version) ? $version : [];
        $features = is_int($version['features'] ?? null) ? $version['features'] : 0;
        $number = is_int($version['version_number'] ?? null) ? $version['version_number'] : 0;
        $libcurl = is_string($version['version'] ?? null) ? $version['version'] : '';
        $ssl = is_string($version['ssl_version'] ?? null) ? $version['ssl_version'] : '';

        $required = [
            'CURLMOPT_MAX_HOST_CONNECTIONS', 'CURLMOPT_MAX_CONCURRENT_STREAMS', 'CURLMOPT_PIPELINING', 'CURLPIPE_MULTIPLEX',
            'CURLOPT_PIPEWAIT', 'CURLOPT_MAXAGE_CONN', 'CURLOPT_TCP_KEEPALIVE', 'CURL_HTTP_VERSION_2TLS',
            'CURLINFO_HTTP_VERSION', 'CURLINFO_PRETRANSFER_TIME_T', 'CURLINFO_REQUEST_SIZE', 'CURLOPT_SUPPRESS_CONNECT_HEADERS',
        ];
        $missing = array_values(array_filter($required, static fn (string $c): bool => ! defined($c)));

        return $detected = new self(
            extensionLoaded: true,
            libcurlVersion: $libcurl,
            libcurlVersionNumber: $number,
            sslVersion: $ssl,
            http2: defined('CURL_VERSION_HTTP2') && ($features & CURL_VERSION_HTTP2) !== 0,
            ssl: defined('CURL_VERSION_SSL') && ($features & CURL_VERSION_SSL) !== 0 && $ssl !== '',
            blobOptions: defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB') && $number >= self::MIN_LIBCURL_BLOB,
            missingConstants: $missing,
        );
    }

    /**
     * @throws TransportConfigurationException
     */
    public function assertSupports(ConnectionProfile $profile): void
    {
        if (! $this->extensionLoaded) {
            throw new TransportConfigurationException('The PHP curl extension is not loaded. Remedy: install/enable ext-curl (e.g. apt install php8.3-curl) and restart PHP.');
        }
        if ($this->missingConstants !== [] || $this->libcurlVersionNumber < self::MIN_LIBCURL) {
            throw new TransportConfigurationException(sprintf(
                'libcurl %s is too old for the push transport (missing: %s). Remedy: use PHP built against libcurl >= 7.68 (8.x recommended).',
                $this->libcurlVersion !== '' ? $this->libcurlVersion : 'unknown',
                $this->missingConstants !== [] ? implode(', ', $this->missingConstants) : 'version',
            ));
        }
        if (! $this->ssl) {
            throw new TransportConfigurationException('libcurl was built without TLS support. Remedy: install a libcurl built with OpenSSL (e.g. libcurl4-openssl-dev / the distribution php-curl package).');
        }
        if ($profile->httpVersion === HttpVersion::Http2 && ! $this->http2) {
            throw new TransportConfigurationException(sprintf(
                'libcurl %s (%s) has no HTTP/2 support, which APNs requires. Remedy: install a libcurl built with nghttp2 (curl -V must list "HTTP2"), or use the official PHP Docker images.',
                $this->libcurlVersion,
                $this->sslVersion,
            ));
        }
        if ($profile->httpVersion === HttpVersion::Http2 && ! $this->alpn()) {
            throw new TransportConfigurationException("HTTP/2 over TLS needs ALPN, which {$this->sslVersion} does not provide. Remedy: link libcurl against OpenSSL >= 1.0.2 (3.x recommended).");
        }
        if (($profile->clientCertificatePem !== null || $profile->clientKeyPem !== null) && ! $this->blobOptions) {
            throw new TransportConfigurationException(sprintf(
                'Client certificates are passed in memory (CURLOPT_SSLCERT_BLOB/SSLKEY_BLOB), which needs libcurl >= 7.71 with the OpenSSL backend; found %s (%s). Remedy: upgrade libcurl, or use token-based (.p8) authentication.',
                $this->libcurlVersion,
                $this->sslVersion,
            ));
        }
        if (($profile->clientCertificatePem === null) !== ($profile->clientKeyPem === null)) {
            throw new TransportConfigurationException('A client certificate needs both the certificate PEM and the private key PEM. Remedy: provide both, or neither.');
        }
        if ($profile->caBundlePath !== null && ! is_readable($profile->caBundlePath)) {
            throw new TransportConfigurationException("The CA bundle '{$profile->caBundlePath}' is not readable. Remedy: fix the path or permissions, or leave it null to use the system trust store.");
        }
        foreach (['window' => $profile->window, 'maxConnections' => $profile->maxConnections, 'connectTimeoutMs' => $profile->connectTimeoutMs, 'timeoutMs' => $profile->timeoutMs] as $name => $value) {
            if ($value < 1) {
                throw new TransportConfigurationException("ConnectionProfile::\${$name} must be >= 1, got {$value}.");
            }
        }
        if ($profile->maxIdleSeconds < 1) {
            throw new TransportConfigurationException("ConnectionProfile::\$maxIdleSeconds must be >= 1, got {$profile->maxIdleSeconds}.");
        }
    }

    /**
     * libcurl has no ALPN feature bit. Every TLS backend libcurl 7.68+ supports has ALPN, except OpenSSL < 1.0.2.
     */
    public function alpn(): bool
    {
        if (preg_match('#^OpenSSL/(\d+)\.(\d+)\.(\d+)#', $this->sslVersion, $m) === 1) {
            return version_compare("{$m[1]}.{$m[2]}.{$m[3]}", '1.0.2', '>=');
        }

        return $this->ssl;
    }

    /** @return array<string, mixed> for doctor/health output */
    public function toArray(): array
    {
        return [
            'extension' => $this->extensionLoaded,
            'libcurl' => $this->libcurlVersion,
            'ssl' => $this->sslVersion,
            'http2' => $this->http2,
            'alpn' => $this->alpn(),
            'tls' => $this->ssl,
            'mtls_blobs' => $this->blobOptions,
            'missing_constants' => $this->missingConstants,
        ];
    }
}
