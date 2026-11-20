<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport;

/**
 * Everything the transport needs to pick (or open) a connection pool.
 *
 * `$poolKey` is the isolation boundary: requests with different pool keys never share a connection.
 * Callers build it from the effective TLS/authorization identity, the endpoint role, the environment,
 * the proxy/TLS profile and the credential generation (see docs/contracts/identity.md).
 */
final readonly class ConnectionProfile
{
    public function __construct(
        public string $poolKey,
        public HttpVersion $httpVersion = HttpVersion::Http2,
        /** Fail instead of silently downgrading when HTTP/2 was not negotiated. */
        public bool $requireHttp2 = true,
        /** Maximum in-flight requests held by the transport for this pool. */
        public int $window = 100,
        public int $maxConnections = 1,
        public int $connectTimeoutMs = 5000,
        public int $timeoutMs = 30000,
        public int $maxIdleSeconds = 3600,
        #[\SensitiveParameter] public ?string $clientCertificatePem = null,
        #[\SensitiveParameter] public ?string $clientKeyPem = null,
        #[\SensitiveParameter] public ?string $clientKeyPassphrase = null,
        public ?string $proxy = null,
        public ?string $caBundlePath = null,
    ) {}
}
