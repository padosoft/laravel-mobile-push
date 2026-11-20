<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\PoolKey;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmOutcomeClassifier;
use Padosoft\MobilePush\Core\Guard\AccountKey;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
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
use Psr\Clock\ClockInterface;

/**
 * FCM HTTP v1 `projects/{project}/messages:send` for one app binding (project = BindingRef::$appId).
 */
final class FcmDriver implements Driver
{
    private readonly EndpointCatalog $endpoints;

    private readonly MessageCompiler $compiler;

    private readonly OutcomeClassifier $classifier;

    private readonly string $url;

    private readonly string $host;

    private readonly int $port;

    /** @var array<int, ConnectionProfile> */
    private array $profiles = [];

    /**
     * @param  ClockInterface|null  $clock  used by the classifier to resolve an HTTP-date Retry-After
     */
    public function __construct(
        private readonly FcmConfig $config,
        ?ClockInterface $clock = null,
        ?MessageCompiler $compiler = null,
        ?OutcomeClassifier $classifier = null,
    ) {
        $this->endpoints = $config->endpoints();
        $this->compiler = $compiler ?? new FcmCompiler;
        $this->classifier = $classifier ?? new FcmOutcomeClassifier($clock);
        $this->url = $this->endpoints->fcmSendUrl($config->binding->appId);

        $parts = parse_url($this->url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new ConfigurationException('The endpoint catalog produced an unusable URL.');
        }
        $this->host = strtolower($parts['host']);
        $this->port = $parts['port'] ?? 443;
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

    public function tokenProvider(int $generation): AccessTokenProvider
    {
        return $this->credential($generation);
    }

    public function hasSecondaryCredential(): bool
    {
        return $this->config->secondary !== null;
    }

    public function credentialIdentity(): string
    {
        return 'fcm:'.hash('sha256', $this->config->credential->identity().'|'.($this->config->secondary?->identity() ?? ''));
    }

    public function credentialIdentityFor(int $generation): string
    {
        return $this->credential($generation)->identity();
    }

    public function accountKey(): string
    {
        return AccountKey::of(Provider::Fcm, $this->config->binding->appId);
    }

    public function supportsValidateOnly(): bool
    {
        return true;
    }

    public function operationKind(Message $message, Destination $destination, bool $validateOnly): OperationKind
    {
        return $validateOnly ? OperationKind::Validate : OperationKind::Send;
    }

    public function isReplaceable(Message $message): bool
    {
        return $message instanceof PushMessage && ($message->replaceable || $message->silent);
    }

    public function endpointRole(CompiledMessage $compiled): EndpointRole
    {
        return EndpointRole::FcmApi;
    }

    public function connectionProfile(EndpointRole $role, int $generation): ConnectionProfile
    {
        if ($role !== EndpointRole::FcmApi) {
            throw new \InvalidArgumentException('The FCM driver does not send to the '.$role->value.' role.');
        }
        if (isset($this->profiles[$generation])) {
            return $this->profiles[$generation];
        }
        $version = $this->config->http2 ? HttpVersion::Http2 : HttpVersion::Http11;
        $binding = $this->config->binding;

        return $this->profiles[$generation] = new ConnectionProfile(
            poolKey: PoolKey::derive(
                Provider::Fcm, $role, $this->host, $this->port, $this->credential($generation)->identity(), $binding->environment,
                $version, $this->config->proxy, $this->config->caBundlePath, $generation, $binding->generation,
            ),
            httpVersion: $version,
            requireHttp2: false,   // preferred, not required: FCM also speaks HTTP/1.1
            window: $this->config->window,
            maxConnections: $this->config->maxConnections,
            connectTimeoutMs: $this->config->connectTimeoutMs,
            timeoutMs: $this->config->timeoutMs,
            maxIdleSeconds: $this->config->maxIdleSeconds,
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
        $headers = [];
        foreach ($compiled->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        unset($headers['authorization']);
        $headers['content-type'] ??= 'application/json';
        if ($token !== null) {
            $headers['authorization'] = 'Bearer '.$token->value;
        }

        return new Request($requestId, 'POST', $this->url, $headers, $compiled->body);
    }

    private function credential(int $generation): AccessTokenProvider
    {
        return match ($generation) {
            0 => $this->config->credential,
            1 => $this->config->secondary ?? throw new \InvalidArgumentException('This FCM binding has no secondary credential.'),
            default => throw new \InvalidArgumentException("Unknown credential generation {$generation}."),
        };
    }
}
