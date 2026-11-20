<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\TransportConfigurationException;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\CurlCapabilities;
use Padosoft\MobilePush\Core\Transport\HttpVersion;

function transportFakeCapabilities(array $overrides = []): CurlCapabilities
{
    /** @phpstan-ignore argument.type */
    return new CurlCapabilities(...array_merge([
        'extensionLoaded' => true,
        'libcurlVersion' => '8.5.0',
        'libcurlVersionNumber' => 0x080500,
        'sslVersion' => 'OpenSSL/3.0.13',
        'http2' => true,
        'ssl' => true,
        'blobOptions' => true,
        'missingConstants' => [],
    ], $overrides));
}

it('detects this runtime as able to run the transport', function (): void {
    $detected = CurlCapabilities::detect();

    expect($detected->http2)->toBeTrue()
        ->and($detected->alpn())->toBeTrue()
        ->and($detected->blobOptions)->toBeTrue()
        ->and($detected->missingConstants)->toBe([]);
    $detected->assertSupports(new ConnectionProfile('p', clientCertificatePem: 'c', clientKeyPem: 'k'));
});

it('rejects a runtime that cannot serve the profile, with a remedy', function (array $overrides, ConnectionProfile $profile, string $remedy): void {
    expect(fn () => transportFakeCapabilities($overrides)->assertSupports($profile))
        ->toThrow(TransportConfigurationException::class, $remedy);
})->with([
    'no curl extension' => [['extensionLoaded' => false], new ConnectionProfile('p'), 'enable ext-curl'],
    'libcurl too old' => [['libcurlVersion' => '7.58.0', 'libcurlVersionNumber' => 0x073A00], new ConnectionProfile('p'), 'libcurl >= 7.68'],
    'missing constants' => [['missingConstants' => ['CURLOPT_PIPEWAIT']], new ConnectionProfile('p'), 'CURLOPT_PIPEWAIT'],
    'no TLS' => [['ssl' => false], new ConnectionProfile('p'), 'built with OpenSSL'],
    'no HTTP/2' => [['http2' => false], new ConnectionProfile('p'), 'nghttp2'],
    'OpenSSL without ALPN' => [['sslVersion' => 'OpenSSL/1.0.1f'], new ConnectionProfile('p'), 'ALPN'],
    'no blob support for mTLS' => [['blobOptions' => false], new ConnectionProfile('p', clientCertificatePem: 'c', clientKeyPem: 'k'), 'CURLOPT_SSLCERT_BLOB'],
    'certificate without key' => [[], new ConnectionProfile('p', clientCertificatePem: 'c'), 'both the certificate PEM and the private key'],
    'unreadable CA bundle' => [[], new ConnectionProfile('p', caBundlePath: '/nonexistent/ca.pem'), 'not readable'],
    'zero window' => [[], new ConnectionProfile('p', window: 0), '$window must be >= 1'],
    'zero timeout' => [[], new ConnectionProfile('p', timeoutMs: 0), '$timeoutMs must be >= 1'],
]);

it('does not require HTTP/2 or blobs for a plain HTTP/1.1 profile', function (): void {
    transportFakeCapabilities(['http2' => false, 'blobOptions' => false, 'sslVersion' => 'OpenSSL/1.0.1f'])
        ->assertSupports(new ConnectionProfile('oauth', httpVersion: HttpVersion::Http11));

    expect(true)->toBeTrue();
});
