<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\PoolKey;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\AccountKey;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\HttpVersion;
use Padosoft\MobilePush\Core\Transport\Request;

/**
 * APNs HTTP/2 provider API for one app binding: per-device pushes (/3/device/<token>) and broadcast
 * publications (/4/broadcasts/apps/<bundle id>), token (JWT) or certificate (mTLS) authentication.
 */
final class ApnsDriver implements Driver
{
    private readonly EndpointCatalog $endpoints;

    private readonly MessageCompiler $compiler;

    private readonly OutcomeClassifier $classifier;

    /** @var array<string, ConnectionProfile> */
    private array $profiles = [];

    /** @var array<string, array{0: string, 1: int}> role => host, port */
    private array $origins = [];

    public function __construct(
        private readonly ApnsConfig $config,
        ?MessageCompiler $compiler = null,
        ?OutcomeClassifier $classifier = null,
    ) {
        $this->endpoints = $config->endpoints();
        $this->compiler = $compiler ?? new ApnsCompiler;
        $this->classifier = $classifier ?? new ApnsOutcomeClassifier;

        $environment = $config->binding->environment;
        $this->origins[EndpointRole::ApnsSend->value] = self::origin($this->endpoints->apnsSendUrl($environment, 'origin'));
        $this->origins[EndpointRole::ApnsBroadcastPublish->value] = self::origin($this->endpoints->apnsBroadcastPublishUrl($environment, $config->binding->appId));
    }

    public function binding(): BindingRef
    {
        return $this->config->binding;
    }

    public function compiler(): MessageCompiler
    {
        return $this->compiler;
    }

    public function classifier(): OutcomeClassifier
    {
        return $this->classifier;
    }

    public function tokenProvider(int $generation): ?AccessTokenProvider
    {
        return $this->credential($generation)->tokenProvider;
    }

    public function hasSecondaryCredential(): bool
    {
        return $this->config->secondary !== null;
    }

    public function credentialIdentity(): string
    {
        return 'apns:'.hash('sha256', $this->config->credential->identity().'|'.($this->config->secondary?->identity() ?? ''));
    }

    public function credentialIdentityFor(int $generation): string
    {
        return $this->credential($generation)->identity();
    }

    public function accountKey(): string
    {
        return AccountKey::of(Provider::Apns, $this->config->credential->teamId);
    }

    public function supportsValidateOnly(): bool
    {
        return false;
    }

    public function operationKind(Message $message, Destination $destination, bool $validateOnly): OperationKind
    {
        return $destination->kind() === DestinationKind::ApnsBroadcastChannel ? OperationKind::BroadcastPublish : OperationKind::Send;
    }

    public function isReplaceable(Message $message): bool
    {
        return match (true) {
            $message instanceof LiveActivityMessage => $message->event !== LiveActivityEvent::Start,
            $message instanceof ApnsMessage => in_array($message->pushType, [PushType::Background, PushType::Widgets, PushType::Controls], true),
            $message instanceof PushMessage => $message->replaceable || $message->silent
                || in_array($message->apnsOverlay?->pushType, [PushType::Background, PushType::Widgets, PushType::Controls], true),
            default => false,
        };
    }

    public function endpointRole(CompiledMessage $compiled): EndpointRole
    {
        return ($compiled->meta['route'] ?? 'device') === 'broadcast' ? EndpointRole::ApnsBroadcastPublish : EndpointRole::ApnsSend;
    }

    public function connectionProfile(EndpointRole $role, int $generation): ConnectionProfile
    {
        $cacheKey = $role->value.'|'.$generation;
        if (isset($this->profiles[$cacheKey])) {
            return $this->profiles[$cacheKey];
        }
        [$host, $port] = $this->origins[$role->value]
            ?? throw new \InvalidArgumentException('The APNs driver does not send to the '.$role->value.' role.');
        $credential = $this->credential($generation);
        $binding = $this->config->binding;

        return $this->profiles[$cacheKey] = new ConnectionProfile(
            poolKey: PoolKey::derive(
                Provider::Apns, $role, $host, $port, $credential->identity(), $binding->environment, HttpVersion::Http2,
                $this->config->proxy, $this->config->caBundlePath, $generation, $binding->generation,
            ),
            httpVersion: HttpVersion::Http2,
            requireHttp2: true,
            window: $this->config->window,
            maxConnections: $this->config->maxConnections,
            connectTimeoutMs: $this->config->connectTimeoutMs,
            timeoutMs: $this->config->timeoutMs,
            maxIdleSeconds: $this->config->maxIdleSeconds,
            clientCertificatePem: $credential->certificatePem(),
            clientKeyPem: $credential->privateKeyPem(),
            clientKeyPassphrase: $credential->passphrase(),
            proxy: $this->config->proxy,
            caBundlePath: $this->config->caBundlePath,
        );
    }

    public function window(): int
    {
        return $this->config->window;
    }

    public function buildRequest(string $requestId, CompiledMessage $compiled, Destination $destination, ?AuthToken $token): Request
    {
        $binding = $this->config->binding;
        $url = $this->endpointRole($compiled) === EndpointRole::ApnsBroadcastPublish
            ? $this->endpoints->apnsBroadcastPublishUrl($binding->environment, $binding->appId)
            : $this->endpoints->apnsSendUrl($binding->environment, $destination->value());

        $headers = [];
        foreach ($compiled->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        unset($headers['authorization']);
        if ($token !== null) {
            $headers['authorization'] = 'bearer '.$token->value;
        }

        return new Request($requestId, 'POST', $url, $headers, $compiled->body);
    }

    private function credential(int $generation): ApnsCredential
    {
        return match ($generation) {
            0 => $this->config->credential,
            1 => $this->config->secondary ?? throw new \InvalidArgumentException('This APNs binding has no secondary credential.'),
            default => throw new \InvalidArgumentException("Unknown credential generation {$generation}."),
        };
    }

    /** @return array{0: string, 1: int} */
    private static function origin(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new ConfigurationException('The endpoint catalog produced an unusable URL.');
        }

        return [strtolower($parts['host']), $parts['port'] ?? 443];
    }
}
