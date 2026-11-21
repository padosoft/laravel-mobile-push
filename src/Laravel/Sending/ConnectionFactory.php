<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Apns\ApnsConfig;
use Padosoft\MobilePush\Core\Apns\ApnsCredential;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Apns\Outcome\ApnsOutcomeClassifier;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Auth\Secret;
use Padosoft\MobilePush\Core\Auth\SecretResolver;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Credentials\ApnsCertificateInspector;
use Padosoft\MobilePush\Core\Credentials\ApnsKeyInspector;
use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\CredentialIdentity;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInfo;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInspector;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Fcm\Auth\GoogleAuthLibraryProvider;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountCredentials;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Padosoft\MobilePush\Core\Fcm\FcmConfig;
use Padosoft\MobilePush\Core\Fcm\FcmDriver;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmOutcomeClassifier;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Policy\ApnsBroadcastHost;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Psr\Clock\ClockInterface;

/**
 * Turns one connection's config array into a validated binding + driver.
 *
 * Secrets are resolved through Core\Auth\Secret (path | inline | base64 | resolver); credentials are inspected with
 * Core\Credentials and every error finding becomes a ConfigurationException carrying the finding's remedy, before any
 * request. The binding generation is a short hash of the NON-secret credential identities (team + key id,
 * certificate fingerprint, Google principal + key id), so rotating a key changes the generation while the binding
 * identity (scope, provider, app, environment) stays the same.
 *
 * An APNs token credential can instead name an external Signer (`signer` = container abstract or class name, for a
 * KMS / HSM that keeps the key): no key material is read, the key id comes from the signer.
 *
 * Exception messages never contain secret material: they name keys, never values.
 */
final readonly class ConnectionFactory
{
    /**
     * @param  (\Closure(class-string<SecretResolver>): SecretResolver)|null  $resolverFactory  builds SecretResolver classes (container)
     * @param  (\Closure(string): mixed)|null  $signerFactory  resolves the `signer` value of a connection (container abstract / class name)
     */
    public function __construct(
        private TokenStore $tokens,
        private ClockInterface $clock,
        /** Used only by the native Google token exchange. */
        private Transport $oauthTransport,
        private TokenIndex $index,
        private SendingSettings $settings,
        private string $applicationEnvironment,
        private ?\Closure $resolverFactory = null,
        private ?\Closure $signerFactory = null,
    ) {}

    /**
     * The provider of a connection config, read without resolving any secret.
     *
     * @param  array<mixed>  $config
     */
    public static function providerOf(#[\SensitiveParameter] array $config, string $name): Provider
    {
        $driver = (new ConfigReader($config, 'mobile-push.connections.'.$name))->choice('driver', ['apns', 'fcm'], '');

        return match ($driver) {
            'apns' => Provider::Apns,
            'fcm' => Provider::Fcm,
            default => throw new ConfigurationException("mobile-push.connections.{$name}.driver is required: set it to \"apns\" or \"fcm\"."),
        };
    }

    /**
     * Short, stable, non-secret hash of the credential identities of a connection.
     */
    public static function generation(string ...$identities): string
    {
        return substr(hash('sha256', 'mobile-push-generation|'.implode("\n", $identities)), 0, 12);
    }

    /**
     * @param  array<mixed>  $config
     *
     * @throws ConfigurationException
     */
    public function make(string $scope, string $name, #[\SensitiveParameter] array $config): ResolvedConnection
    {
        $scope = Scope::normalize($scope);
        $label = sprintf('mobile-push connection "%s"%s', $name, $scope === Scope::DEFAULT ? '' : sprintf(' (scope "%s")', $scope));
        $c = new ConfigReader($config, 'mobile-push.connections.'.$name);

        try {
            return match (self::providerOf($config, $name)) {
                Provider::Apns => $this->apns($scope, $name, $c),
                Provider::Fcm => $this->fcm($scope, $name, $c),
            };
        } catch (ConfigurationException $e) {
            throw str_starts_with($e->getMessage(), 'mobile-push.') ? $e : new ConfigurationException($label.': '.$e->getMessage());
        } catch (PushException $e) {
            // AuthenticationException / ValidationException from key parsing or token building: generic, key-free texts.
            throw new ConfigurationException($label.': '.$e->getMessage());
        }
    }

    private function apns(string $scope, string $name, ConfigReader $c): ResolvedConnection
    {
        $environment = Environment::from($c->choice('environment', ['production', 'sandbox'], 'production'));
        $bundle = $c->requiredString('bundle_id', 'Set it to the bundle identifier of the iOS app (the APNs topic)');
        $team = $c->requiredString('team_id', 'Set it to the 10-character Apple team id shown in the developer account');

        $trackers = [];
        $inspected = [];
        [$primary, $primaryIdentity] = $this->apnsCredential($c, '', $scope, $environment, $team, $trackers, $inspected);

        $secondary = null;
        if ($this->present($c, 'secondary')) {
            [$secondary, $secondaryIdentity] = $this->apnsCredential($c, 'secondary.', $scope, $environment, $team, $trackers, $inspected);
            $this->assertPasses($this->check()->sameIdentity($primaryIdentity, $secondaryIdentity), $c->prefix().'.secondary');
        }

        $binding = new BindingRef(
            $scope, $name, Provider::Apns, $bundle, $environment,
            self::generation($primary->identity(), $secondary?->identity() ?? ''),
        );

        $port = $c->int('port', 443);
        $transport = $this->transport($c);
        $config = new ApnsConfig(
            $binding,
            $primary,
            $secondary,
            port: $port,
            broadcastHost: ApnsBroadcastHost::from($c->choice('broadcast_host', ['standard', 'api-broadcast'], 'standard')),
            window: $transport['window'],
            maxConnections: $transport['max_connections'],
            connectTimeoutMs: $transport['connect_timeout_ms'],
            timeoutMs: $transport['timeout_ms'],
            maxIdleSeconds: $transport['max_idle_seconds'],
            proxy: $transport['proxy'],
            caBundlePath: $transport['ca_bundle'],
            applicationEnvironment: $this->applicationEnvironment,
        );
        $driver = new ApnsDriver($config, classifier: new ApnsOutcomeClassifier(
            $this->settings->apnsServerErrorRetryDelay,
            $this->settings->apnsDeviceRateLimitDelay,
        ));

        $roles = [EndpointRole::ApnsSend, EndpointRole::ApnsBroadcastPublish, EndpointRole::ApnsChannelManagement];

        return new ResolvedConnection(
            $binding, $driver, $this->live($c, $binding),
            self::poolKeys($driver, $roles, $secondary !== null), $trackers, $inspected,
        );
    }

    /**
     * @param  list<TrackingTokenProvider>  $trackers
     * @param  list<InspectedCredential>  $inspected
     * @return array{0: ApnsCredential, 1: CredentialIdentity}
     */
    private function apnsCredential(ConfigReader $c, string $prefix, string $scope, Environment $environment, string $primaryTeam, array &$trackers, array &$inspected): array
    {
        $role = $prefix === '' ? 'primary' : 'secondary';
        $where = $c->prefix().($prefix === '' ? '' : '.'.rtrim($prefix, '.'));
        $team = $c->string($prefix.'team_id') ?? $primaryTeam;
        $auth = $c->choice($prefix.'auth', ['token', 'certificate'], 'token');

        if ($auth === 'token' && $this->hasSigner($c, $prefix)) {
            $signer = $this->signer($c, $prefix, 'ES256');
            $keyId = $signer->keyId();
            if ($keyId === null || $keyId === '') {
                throw $c->error($prefix.'signer', 'must report a key id (Signer::keyId()): APNs needs it in the token header');
            }
            $configured = $c->string($prefix.'key_id');
            if ($configured !== null && $configured !== $keyId) {
                throw $c->error($prefix.'key_id', 'differs from the key id of the signer. Remove key_id (the signer provides it) or point the signer to the key this id belongs to');
            }
            $inspected[] = new InspectedCredential($role, externalSigner: true);

            $tracker = new TrackingTokenProvider(new ApnsTokenProvider(
                $signer, $team, $this->tokens, $this->clock, $scope, $environment->value,
            ));
            $trackers[] = $tracker;

            return [ApnsCredential::token($tracker, $team, $keyId), CredentialIdentity::apnsKey($team)];
        }

        if ($auth === 'token') {
            $keyId = $c->requiredString($prefix.'key_id', 'Set it to the 10-character id of the .p8 key (shown next to the key in the Apple developer account)');
            $pem = $this->secret($c, $prefix.'key', true, 'the .p8 key file downloaded from the Apple developer account');
            $info = (new ApnsKeyInspector)->inspect($pem);
            $this->assertPasses($this->check()->apnsKey($info), $where);
            $inspected[] = new InspectedCredential($role, apnsKey: $info);

            $tracker = new TrackingTokenProvider(new ApnsTokenProvider(
                new OpenSslEs256Signer(PrivateKey::fromPem($pem), $keyId),
                $team, $this->tokens, $this->clock, $scope, $environment->value,
            ));
            $trackers[] = $tracker;

            return [ApnsCredential::token($tracker, $team, $keyId), CredentialIdentity::apnsKey($team)];
        }

        $certificate = $this->secret($c, $prefix.'certificate', true, 'the PEM file of the Apple push certificate (export it from the keychain together with its key)');
        $key = $this->optionalSecret($c, $prefix.'certificate_key');
        $passphrase = $this->passphrase($c, $prefix.'passphrase');

        $info = (new ApnsCertificateInspector)->fromPem($certificate, $key, $passphrase);
        $this->assertPasses($this->check()->certificate($info), $where);
        $inspected[] = new InspectedCredential($role, certificate: $info);
        if ($info->teamId !== null && $info->teamId !== $team) {
            throw new ConfigurationException(sprintf(
                '%s: the certificate belongs to team "%s" but team_id is "%s". Use the certificate of this team or correct team_id.',
                $where, $info->teamId, $team,
            ));
        }

        return [
            ApnsCredential::certificate($team, $certificate, $key, $passphrase, $info->environment()),
            new CredentialIdentity(Provider::Apns, $info->teamId ?? $team, $info->environment()),
        ];
    }

    /** Whether a credential block names an external signer (`signer`), which replaces `key` / `key_id` material. */
    private function hasSigner(ConfigReader $c, string $prefix): bool
    {
        $raw = $c->raw($prefix.'signer');

        return $raw !== null && $raw !== '';
    }

    /**
     * The external Signer (KMS / HSM) named by `signer`: a container abstract or class name. Errors name the key,
     * never the value of anything secret (the signer holds no secret the package can see).
     */
    private function signer(ConfigReader $c, string $prefix, string $algorithm): Signer
    {
        $key = $prefix.'signer';
        $name = $c->raw($key);
        if (! is_string($name) || trim($name) === '') {
            throw $c->error($key, 'must be the class or container binding name of a Padosoft\\MobilePush\\Core\\Auth\\Signer');
        }
        $name = trim($name);
        if ($this->signerFactory === null) {
            throw $c->error($key, 'cannot be resolved here: no container is available to build the signer');
        }

        try {
            $signer = ($this->signerFactory)($name);
        } catch (\Throwable $e) {
            throw $c->error($key, sprintf('could not be resolved from the container (%s)', $e::class));
        }
        if (! $signer instanceof Signer) {
            throw $c->error($key, sprintf('resolved to %s, which does not implement Padosoft\\MobilePush\\Core\\Auth\\Signer', get_debug_type($signer)));
        }
        if ($signer->algorithm() !== $algorithm) {
            throw $c->error($key, sprintf('signs with %s but this credential needs %s', $signer->algorithm(), $algorithm));
        }

        return $signer;
    }

    private function fcm(string $scope, string $name, ConfigReader $c): ResolvedConnection
    {
        $auth = $c->choice('auth', ['service_account', 'adc'], 'service_account');
        $project = $c->string('project_id');

        if ($auth === 'adc') {
            if ($project === null) {
                throw $c->error('project_id', 'is required with auth "adc": set it to the Firebase project id');
            }
            $primary = new TrackingTokenProvider(GoogleAuthLibraryProvider::applicationDefault($this->tokens, $this->clock, $scope));
            $primaryIdentity = new CredentialIdentity(Provider::Fcm, $project, Environment::Production);
            $inspected = [new InspectedCredential('primary', applicationDefault: true)];
        } else {
            [$primary, $primaryIdentity, $fileProject, $info] = $this->serviceAccount($c, '', $scope);
            $inspected = [new InspectedCredential('primary', serviceAccount: $info)];
            $project ??= $fileProject;
            if ($project === null) {
                throw $c->error('project_id', 'is required: the service-account JSON has no project_id, set the Firebase project id');
            }
        }
        $trackers = [$primary];

        $secondary = null;
        if ($this->present($c, 'secondary')) {
            [$secondary, $secondaryIdentity, , $secondaryInfo] = $this->serviceAccount($c, 'secondary.', $scope);
            $trackers[] = $secondary;
            $inspected[] = new InspectedCredential('secondary', serviceAccount: $secondaryInfo);
            $this->assertPasses($this->check()->sameIdentity($primaryIdentity, $secondaryIdentity), $c->prefix().'.secondary');
        }

        $binding = new BindingRef(
            $scope, $name, Provider::Fcm, $project, Environment::Production,
            self::generation($primary->identity(), $secondary?->identity() ?? ''),
        );

        $transport = $this->transport($c);
        $config = new FcmConfig(
            $binding,
            $primary,
            $secondary,
            http2: $c->choice('http_version', ['2', '1.1'], '2') === '2',
            window: $transport['window'],
            maxConnections: $transport['max_connections'],
            connectTimeoutMs: $transport['connect_timeout_ms'],
            timeoutMs: $transport['timeout_ms'],
            maxIdleSeconds: $transport['max_idle_seconds'],
            proxy: $transport['proxy'],
            caBundlePath: $transport['ca_bundle'],
            applicationEnvironment: $this->applicationEnvironment,
        );
        $driver = new FcmDriver($config, $this->clock, classifier: new FcmOutcomeClassifier($this->clock, $this->settings->fcmThrottledMinDelay));

        return new ResolvedConnection(
            $binding, $driver, $this->live($c, $binding),
            self::poolKeys($driver, [EndpointRole::FcmApi], $secondary !== null), $trackers, $inspected,
        );
    }

    /**
     * @return array{0: TrackingTokenProvider, 1: CredentialIdentity, 2: string|null, 3: ServiceAccountInfo}
     */
    private function serviceAccount(ConfigReader $c, string $prefix, string $scope): array
    {
        $where = $c->prefix().($prefix === '' ? '' : '.'.rtrim($prefix, '.'));
        $json = $this->secret($c, $prefix.'credentials', true, 'the service-account JSON key downloaded from the Firebase console (Project settings, Service accounts)');
        $info = (new ServiceAccountInspector)->fromJson($json);
        $this->assertPasses($this->check()->serviceAccount($info), $where);
        $credentials = ServiceAccountCredentials::fromJson($json);

        return [
            new TrackingTokenProvider(new ServiceAccountTokenProvider($credentials, $this->oauthTransport, $this->tokens, $this->clock, $scope)),
            CredentialIdentity::serviceAccount($info),
            $credentials->projectId,
            $info,
        ];
    }

    private function live(ConfigReader $c, BindingRef $binding): LiveSettings
    {
        $onlyTo = [];
        $raw = $c->raw('live.only_to');
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if ($raw !== null && ! is_array($raw)) {
            throw $c->error('live.only_to', 'must be a list of test device tokens');
        }
        $default = $binding->provider === Provider::Apns ? DestinationKind::ApnsDeviceToken : DestinationKind::FcmToken;

        foreach ($raw ?? [] as $i => $entry) {
            $kind = $default;
            if (is_array($entry)) {
                $kindValue = $entry['kind'] ?? null;
                $entry = $entry['token'] ?? null;
                $kind = is_string($kindValue) ? DestinationKind::tryFrom($kindValue) : null;
                if ($kind === null || $kind->provider() !== $binding->provider || ! $kind->isIndividual()) {
                    throw $c->error("live.only_to.{$i}.kind", 'must be an individual destination kind of this provider (for example "'.$default->value.'")');
                }
            }
            if (! is_string($entry)) {
                throw $c->error("live.only_to.{$i}", 'must be a raw test device token (string) or [\'kind\' => ..., \'token\' => ...]');
            }
            if (trim($entry) === '') {
                continue;
            }
            try {
                $onlyTo[] = $this->index->index(PushDevice::build($kind, $entry), $binding->scope);
            } catch (PushException) {
                throw $c->error("live.only_to.{$i}", 'is not a valid '.$kind->value.' (the value is not shown)');
            }
        }

        return new LiveSettings(
            allow: $c->bool('live.allow', false),
            onlyTo: $onlyTo,
            allowTopics: $c->bool('live.allow_topics', false),
            allowBroadcast: $c->bool('live.allow_broadcast', false),
            allowChannelManagement: $c->bool('live.allow_channel_management', false),
        );
    }

    /**
     * @return array{window: int, max_connections: int, connect_timeout_ms: int, timeout_ms: int, max_idle_seconds: int, proxy: string|null, ca_bundle: string|null}
     */
    private function transport(ConfigReader $c): array
    {
        $d = $this->settings->transport;

        return [
            'window' => $c->int('window', $d['window'], 1, 10_000),
            'max_connections' => $c->int('max_connections', $d['max_connections'], 1),
            'connect_timeout_ms' => $c->int('connect_timeout_ms', $d['connect_timeout_ms'], 1),
            'timeout_ms' => $c->int('timeout_ms', $d['timeout_ms'], 1),
            'max_idle_seconds' => $c->int('max_idle_seconds', $d['max_idle_seconds'], 1),
            'proxy' => $c->string('proxy') ?? $d['proxy'],
            'ca_bundle' => $c->string('ca_bundle') ?? $d['ca_bundle'],
        ];
    }

    /** Whether an optional nested block is set to something other than empty / all-null values. */
    private function present(ConfigReader $c, string $key): bool
    {
        $value = $c->raw($key);
        if ($value === null) {
            return false;
        }
        if (! is_array($value)) {
            throw $c->error($key, 'must be an array');
        }

        return self::hasValue($value);
    }

    /** @param  array<mixed>  $value */
    private static function hasValue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) ? self::hasValue($item) : ($item !== null && $item !== '' && $item !== false)) {
                return true;
            }
        }

        return false;
    }

    private function secret(ConfigReader $c, string $key, bool $required, string $what): string
    {
        return $this->optionalSecret($c, $key, $required, $what)
            ?? throw $c->error($key, "is required: point it to {$what} with one of 'path', 'inline', 'base64' or 'resolver' + 'reference'");
    }

    private function optionalSecret(ConfigReader $c, string $key, bool $required = false, string $what = 'the secret'): ?string
    {
        $source = $c->raw($key);
        if ($source !== null && ! is_array($source)) {
            throw $c->error($key, "must be a secret source: ['path' => ..., 'inline' => ..., 'base64' => ... or 'resolver' => class, 'reference' => ...]");
        }
        $given = [];
        foreach ($source ?? [] as $name => $value) {
            if ($value !== null && $value !== '') {
                $given[(string) $name] = $value;
            }
        }
        $source = $given;

        if ($source === []) {
            if ($required) {
                throw $c->error($key, "is required: point it to {$what} with one of 'path', 'inline', 'base64' or 'resolver' + 'reference'");
            }

            return null;
        }

        try {
            return Secret::resolve($source, $this->resolverFactory);
        } catch (PushException $e) {
            throw $c->error($key, 'could not be read ('.rtrim($e->getMessage(), '.').')');
        }
    }

    private function passphrase(ConfigReader $c, string $key): ?string
    {
        $raw = $c->raw($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            return $raw;
        }

        return $this->optionalSecret($c, $key);
    }

    private function check(): CredentialCheck
    {
        return new CredentialCheck($this->clock);
    }

    private function assertPasses(CheckResult $result, string $where): void
    {
        $errors = [];
        foreach ($result->findings as $finding) {
            if ($finding->severity === Severity::Error) {
                $errors[] = $finding->message;
            }
        }
        if ($errors !== []) {
            throw new ConfigurationException($where.': '.implode(' ', $errors));
        }
    }

    /**
     * @param  list<EndpointRole>  $roles
     * @return list<string>
     */
    private static function poolKeys(Driver $driver, array $roles, bool $secondary): array
    {
        $keys = [];
        foreach ($roles as $role) {
            foreach ($secondary ? [0, 1] : [0] as $generation) {
                $keys[] = $driver->connectionProfile($role, $generation)->poolKey;
            }
        }

        return array_values(array_unique($keys));
    }
}
