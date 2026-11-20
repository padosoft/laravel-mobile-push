<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Transport\HttpVersion;

/**
 * Derives ConnectionProfile::$poolKey, the connection isolation boundary (PLAN §6.1, docs/contracts/identity.md).
 *
 * Inputs: provider, endpoint role, host and port, the EFFECTIVE credential identity of the generation in use
 * (APNs token: team id + key id; mTLS: SHA-256 certificate fingerprint; FCM: Google principal), environment,
 * HTTP version, proxy, CA bundle, credential generation (0/1) and binding generation.
 *
 * The scope is deliberately NOT an input: two tenants that share one credential may share a connection, but two
 * different credentials never do. The readable prefix carries no secret (the proxy, which may hold a password, is
 * only hashed).
 */
final class PoolKey
{
    public static function derive(
        Provider $provider,
        EndpointRole $role,
        string $host,
        int $port,
        string $credentialIdentity,
        Environment $environment,
        HttpVersion $httpVersion,
        ?string $proxy,
        ?string $caBundlePath,
        int $credentialGeneration,
        string $bindingGeneration,
    ): string {
        $parts = [
            'v1',
            $provider->value,
            $role->value,
            strtolower($host),
            (string) $port,
            $credentialIdentity,
            $environment->value,
            $httpVersion->value,
            $proxy ?? '',
            $caBundlePath ?? '',
            (string) $credentialGeneration,
            $bindingGeneration,
        ];

        $material = '';
        foreach ($parts as $part) {
            $material .= pack('N', strlen($part)).$part;
        }

        return $provider->value.':'.$role->value.':'.substr(hash('sha256', $material), 0, 40);
    }
}
