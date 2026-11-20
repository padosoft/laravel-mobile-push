<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Illuminate\Contracts\Container\Container;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelClient;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;

/**
 * The default factory: the credential (token provider or mTLS certificate) and the connection settings come from
 * the binding's driver (PushDispatcher::driver()); the transport and the endpoint catalog from the container; the
 * execution mode is asked to SafeModeGuard (OperationKind::ChannelManagement) with the binding's default options,
 * so a simulated or blocked environment never reaches APNs.
 */
final readonly class DispatcherChannelClientFactory implements ChannelClientFactory
{
    public function __construct(
        private PushDispatcher $dispatcher,
        private Container $container,
    ) {}

    public function for(BindingRef $binding): ChannelClient
    {
        $driver = $this->dispatcher->driver($binding);
        $options = $this->dispatcher->defaultOptions($binding);
        $guard = new SafeModeGuard($this->container->make(TokenIndex::class));
        $endpoints = $this->container->bound(EndpointCatalog::class)
            ? $this->container->make(EndpointCatalog::class)
            : new EndpointCatalog($options->applicationEnvironment ?? '');

        return new ChannelClient(
            $this->container->make(Transport::class),
            self::profile($driver),
            $endpoints,
            $binding,
            $driver->tokenProvider(0),
            static fn (OperationKind $operation): SafeModeDecision => $guard->decide(
                $options->effectiveMode,
                $options->applicationEnvironment ?? '',
                $binding,
                null,
                $operation,
                $options->live,
            ),
        );
    }

    /**
     * The channel-management profile of the primary credential. A driver that does not expose that role yet gets a
     * profile derived from its send profile with its own pool key, so management never shares the send pool.
     */
    private static function profile(Driver $driver): ConnectionProfile
    {
        try {
            return $driver->connectionProfile(EndpointRole::ApnsChannelManagement, 0);
        } catch (\InvalidArgumentException) {
            $send = $driver->connectionProfile(EndpointRole::ApnsSend, 0);

            return new ConnectionProfile(
                hash('sha256', EndpointRole::ApnsChannelManagement->value.'|'.$send->poolKey),
                $send->httpVersion,
                $send->requireHttp2,
                $send->window,
                $send->maxConnections,
                $send->connectTimeoutMs,
                $send->timeoutMs,
                $send->maxIdleSeconds,
                $send->clientCertificatePem,
                $send->clientKeyPem,
                $send->clientKeyPassphrase,
                $send->proxy,
                $send->caBundlePath,
            );
        }
    }
}
