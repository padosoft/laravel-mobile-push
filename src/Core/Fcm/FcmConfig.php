<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointOverride;

/**
 * Everything an FcmDriver needs, already resolved (no framework, no file reading). Validated at construction.
 *
 * The Firebase project is the binding's appId. Both credentials send for that project (the secondary is a second
 * service-account key for rotation); the provider identity is the Google principal.
 */
final readonly class FcmConfig
{
    public function __construct(
        public BindingRef $binding,
        public AccessTokenProvider $credential,
        public ?AccessTokenProvider $secondary = null,
        /** HTTP/2 is preferred (multiplexing); false switches to HTTP/1.1 for networks that cannot carry h2. */
        public bool $http2 = true,
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
        if ($binding->provider !== Provider::Fcm) {
            throw new ConfigurationException('An FCM driver needs an FCM binding.');
        }
        if ($binding->environment !== Environment::Production) {
            throw new ConfigurationException('FCM has a single environment: the binding environment must be production.');
        }
        if ($credential->identity() === '') {
            throw new ConfigurationException('The FCM credential has no identity (Google principal).');
        }
        if ($secondary !== null && $secondary->identity() === $credential->identity()) {
            throw new ConfigurationException('The secondary FCM credential must be a different service-account key than the primary.');
        }
        if ($window < 1 || $window > 10_000 || $maxConnections < 1) {
            throw new ConfigurationException('The FCM window must be 1..10000 and maxConnections at least 1.');
        }
        if ($connectTimeoutMs < 1 || $timeoutMs < $connectTimeoutMs || $maxIdleSeconds < 1) {
            throw new ConfigurationException('FCM timeouts must be positive, with the request timeout not below the connect timeout.');
        }
    }

    public function endpoints(): EndpointCatalog
    {
        return new EndpointCatalog($this->applicationEnvironment, override: $this->endpointOverride);
    }
}
