<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\ApnsBroadcastHost;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;

/**
 * Everything an ApnsDriver needs, already resolved (no framework, no file reading). Validated at construction.
 *
 * The secondary credential (key rotation) must belong to the same team and suit the same environment as the
 * primary; which one is active is decided by the authentication breaker.
 */
final readonly class ApnsConfig
{
    public function __construct(
        public BindingRef $binding,
        public ApnsCredential $credential,
        public ?ApnsCredential $secondary = null,
        /** 443 or 2197. */
        public int $port = 443,
        public ApnsBroadcastHost $broadcastHost = ApnsBroadcastHost::Standard,
        /** Requests multiplexed per window. */
        public int $window = 100,
        public int $maxConnections = 1,
        public int $connectTimeoutMs = 5000,
        public int $timeoutMs = 30000,
        public int $maxIdleSeconds = 3600,
        public ?string $proxy = null,
        public ?string $caBundlePath = null,
        /** Test-only origins (local mock server); rejected in production and without test credentials. */
        public ?EndpointOverride $endpointOverride = null,
        /** Only used to refuse endpoint overrides in production. */
        public string $applicationEnvironment = 'production',
    ) {
        if ($binding->provider !== Provider::Apns) {
            throw new ConfigurationException('An APNs driver needs an APNs binding.');
        }
        foreach (array_filter([$credential, $secondary]) as $c) {
            if ($c->environment !== null && $c->environment !== $binding->environment) {
                throw new ConfigurationException(sprintf(
                    'The APNs credential %s is restricted to the %s environment, but the binding "%s" is %s.',
                    $c->identity(), $c->environment->value, $binding->name, $binding->environment->value,
                ));
            }
        }
        if ($secondary !== null) {
            if ($secondary->teamId !== $credential->teamId) {
                throw new ConfigurationException('The secondary APNs credential must belong to the same team as the primary.');
            }
            if ($secondary->identity() === $credential->identity()) {
                throw new ConfigurationException('The secondary APNs credential must be a different key or certificate than the primary.');
            }
        }
        if (! in_array($port, EndpointCatalog::APNS_PORTS, true)) {
            throw new ConfigurationException('The APNs port must be 443 or 2197.');
        }
        if ($window < 1 || $window > 10_000 || $maxConnections < 1) {
            throw new ConfigurationException('The APNs window must be 1..10000 and maxConnections at least 1.');
        }
        if ($connectTimeoutMs < 1 || $timeoutMs < $connectTimeoutMs || $maxIdleSeconds < 1) {
            throw new ConfigurationException('APNs timeouts must be positive, with the request timeout not below the connect timeout.');
        }
    }

    public function endpoints(): EndpointCatalog
    {
        return new EndpointCatalog($this->applicationEnvironment, $this->broadcastHost, $this->port, $this->endpointOverride);
    }
}
