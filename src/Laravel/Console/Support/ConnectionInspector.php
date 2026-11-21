<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Illuminate\Contracts\Container\Container;
use Padosoft\MobilePush\Core\Auth\Secret;
use Padosoft\MobilePush\Core\Auth\SecretResolver;
use Padosoft\MobilePush\Core\Credentials\ApnsCertificateInspector;
use Padosoft\MobilePush\Core\Credentials\ApnsKeyInspector;
use Padosoft\MobilePush\Core\Credentials\CertificateInfo;
use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\CredentialIdentity;
use Padosoft\MobilePush\Core\Credentials\Finding;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInspector;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Sending\ConfigReader;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Psr\Clock\ClockInterface;

/**
 * Reads a connection's configuration and inspects its credentials WITHOUT sending anything and without keeping any
 * secret: only non-secret identities (team, key id, certificate fingerprint prefix, service-account e-mail) leave it.
 * Connections registered at runtime or supplied by a resolver are not in the config and cannot be inspected here.
 */
final class ConnectionInspector
{
    public function __construct(
        private readonly Container $container,
        private readonly ClockInterface $clock,
        private readonly PushManager $manager,
        private readonly Redactor $redactor = new Redactor,
    ) {}

    /** @return list<string> names under mobile-push.connections */
    public function names(): array
    {
        $connections = config('mobile-push.connections', []);

        return is_array($connections) ? array_map('strval', array_keys($connections)) : [];
    }

    public function inspect(string $name, ?string $scope = null): ConnectionInfo
    {
        $connections = config('mobile-push.connections', []);
        $config = is_array($connections) ? ($connections[$name] ?? null) : null;
        if (! is_array($config)) {
            return $this->failed($name, null, 'mobile-push.connections.'.$name.' is not an array. Define it as an array with at least "driver" => "apns" or "fcm".');
        }
        $c = new ConfigReader($config, 'mobile-push.connections.'.$name);

        try {
            $provider = ConnectionFactory::providerOf($config, $name);
            $info = $provider === Provider::Apns ? $this->apns($name, $c) : $this->fcm($name, $c);
        } catch (PushException $e) {
            return $this->failed($name, $this->safeProvider($config), $this->redactor->string($e->getMessage()), $this->live($c));
        }

        if ($info->hasErrors()) {
            return $info;
        }

        // Full validation exactly as a send would do it (also yields the binding generation).
        try {
            $binding = $this->manager->binding($name, $scope);

            return new ConnectionInfo(
                $info->name, $info->provider, $binding->appId, $binding->environment->value, $info->auth, $info->identity,
                $binding->generation, $info->daysLeft, $info->findings, $info->notes, null, $info->live, $info->hasSecondary,
            );
        } catch (PushException $e) {
            return new ConnectionInfo(
                $info->name, $info->provider, $info->appId, $info->environment, $info->auth, $info->identity, null, $info->daysLeft,
                $info->findings, $info->notes, $this->redactor->string($e->getMessage()), $info->live, $info->hasSecondary,
            );
        }
    }

    private function apns(string $name, ConfigReader $c): ConnectionInfo
    {
        $environment = $c->choice('environment', ['production', 'sandbox'], 'production');
        $bundle = $c->string('bundle_id');
        $team = $c->string('team_id');
        $auth = $c->choice('auth', ['token', 'certificate'], 'token');
        $check = new CredentialCheck($this->clock);
        $findings = [];
        $notes = [];
        $days = null;

        if ($bundle === null) {
            $findings[] = Finding::error('connection.bundle_id', 'mobile-push.connections.'.$name.'.bundle_id is required: set it to the bundle identifier of the iOS app (the APNs topic).');
        }
        if ($team === null) {
            $findings[] = Finding::error('connection.team_id', 'mobile-push.connections.'.$name.'.team_id is required: set it to the 10-character Apple team id.');
        }

        [$identity, $primaryIdentity, $result, $days] = $this->apnsCredential($c, '', $auth, $team, $check);
        $findings = [...$findings, ...$result->findings];

        $secondary = false;
        if ($this->hasValue($c->raw('secondary'))) {
            $secondary = true;
            $secondaryAuth = $c->choice('secondary.auth', ['token', 'certificate'], 'token');
            [, $secondaryIdentity, $secondaryResult] = $this->apnsCredential($c, 'secondary.', $secondaryAuth, $c->string('secondary.team_id') ?? $team, $check);
            $findings = [...$findings, ...$secondaryResult->findings];
            if ($primaryIdentity !== null && $secondaryIdentity !== null) {
                $findings = [...$findings, ...$check->sameIdentity($primaryIdentity, $secondaryIdentity)->findings];
            }
        }

        return new ConnectionInfo($name, Provider::Apns, $bundle, $environment, $auth === 'token' ? 'token (.p8)' : 'certificate (mTLS)', $identity, null, $days, $findings, $notes, null, $this->live($c), $secondary);
    }

    /** @return array{0: string|null, 1: CredentialIdentity|null, 2: CheckResult, 3: int|null} */
    private function apnsCredential(ConfigReader $c, string $prefix, string $auth, ?string $team, CredentialCheck $check): array
    {
        $label = $prefix === '' ? '' : 'secondary ';
        try {
            if ($auth === 'token') {
                $keyId = $c->string($prefix.'key_id');
                if ($keyId === null) {
                    return [null, null, new CheckResult([Finding::error('connection.key_id', 'The '.$label.'key_id is required: set it to the 10-character id of the .p8 key.')]), null];
                }
                $pem = $this->secret($c, $prefix.'key');
                $result = $check->apnsKey((new ApnsKeyInspector)->inspect($pem));

                return [sprintf('%steam %s, key id %s', $label, $team ?? '?', $keyId), $team === null ? null : CredentialIdentity::apnsKey($team), $result, null];
            }

            $certificate = $this->secret($c, $prefix.'certificate');
            $keyRaw = $c->raw($prefix.'certificate_key');
            $key = $this->hasValue($keyRaw) ? $this->secret($c, $prefix.'certificate_key') : null;
            $passphrase = $c->raw($prefix.'passphrase');
            $passphrase = is_string($passphrase) && $passphrase !== '' ? $passphrase : null;
            $info = (new ApnsCertificateInspector)->fromPem($certificate, $key, $passphrase);
            $result = $check->certificate($info);

            return [
                sprintf('%scertificate %s, sha256 %s', $label, $info->commonName ?? 'without CN', substr($info->fingerprintSha256, 0, 16)),
                CredentialIdentity::apnsCertificate($info),
                $result,
                self::daysLeft($info, $this->clock),
            ];
        } catch (PushException $e) {
            return [null, null, new CheckResult([Finding::error('connection.credential', $this->redactor->string($e->getMessage()))]), null];
        }
    }

    private function fcm(string $name, ConfigReader $c): ConnectionInfo
    {
        $auth = $c->choice('auth', ['service_account', 'adc'], 'service_account');
        $project = $c->string('project_id');
        $check = new CredentialCheck($this->clock);
        $findings = [];
        $notes = [];
        $identity = null;
        $secondary = false;

        if ($auth === 'adc') {
            $notes[] = 'Application Default Credentials are resolved by google/auth at send time; they cannot be inspected from the configuration.';
            $identity = 'Application Default Credentials';
            if ($project === null) {
                $findings[] = Finding::error('connection.project_id', 'mobile-push.connections.'.$name.'.project_id is required with auth "adc": set it to the Firebase project id.');
            }
        } else {
            [$identity, $project, $result, $primaryIdentity] = $this->serviceAccount($c, '', $project);
            $findings = [...$findings, ...$result->findings];
            if ($this->hasValue($c->raw('secondary'))) {
                $secondary = true;
                [, , $secondaryResult, $secondaryIdentity] = $this->serviceAccount($c, 'secondary.', null);
                $findings = [...$findings, ...$secondaryResult->findings];
                if ($primaryIdentity !== null && $secondaryIdentity !== null) {
                    $findings = [...$findings, ...$check->sameIdentity($primaryIdentity, $secondaryIdentity)->findings];
                }
            }
        }

        return new ConnectionInfo($name, Provider::Fcm, $project, Environment::Production->value, $auth === 'adc' ? 'Application Default Credentials' : 'service account', $identity, null, null, $findings, $notes, null, $this->live($c), $secondary);
    }

    /** @return array{0: string|null, 1: string|null, 2: CheckResult, 3: CredentialIdentity|null} */
    private function serviceAccount(ConfigReader $c, string $prefix, ?string $project): array
    {
        $check = new CredentialCheck($this->clock);
        try {
            $json = $this->secret($c, $prefix.'credentials');
            $info = (new ServiceAccountInspector)->fromJson($json);

            return [
                sprintf('%sservice account %s, key id %s', $prefix === '' ? '' : 'secondary ', $info->clientEmail ?? '?', $info->privateKeyId ?? '?'),
                $project ?? $info->projectId,
                $check->serviceAccount($info),
                CredentialIdentity::serviceAccount($info),
            ];
        } catch (PushException $e) {
            return [null, $project, new CheckResult([Finding::error('connection.credential', $this->redactor->string($e->getMessage()))]), null];
        }
    }

    private function secret(ConfigReader $c, string $key): string
    {
        $source = $c->raw($key);
        if (! is_array($source)) {
            throw new ConfigurationException(sprintf('%s.%s is required: point it to the secret with one of "path", "inline", "base64" or "resolver" + "reference".', $c->prefix(), $key));
        }
        $given = [];
        foreach ($source as $part => $value) {
            if ($value !== null && $value !== '') {
                $given[(string) $part] = $value;
            }
        }
        if ($given === []) {
            throw new ConfigurationException(sprintf('%s.%s is empty: set one of "path", "inline", "base64" or "resolver" + "reference" (check the MOBILE_PUSH_* environment variables).', $c->prefix(), $key));
        }
        $container = $this->container;

        try {
            return Secret::resolve($given, static function (string $class) use ($container): SecretResolver {
                $resolver = $container->make($class);
                if (! $resolver instanceof SecretResolver) {
                    throw new ConfigurationException('The configured secret resolver does not implement SecretResolver.');
                }

                return $resolver;
            });
        } catch (PushException $e) {
            throw new ConfigurationException(sprintf('%s.%s could not be read (%s).', $c->prefix(), $key, rtrim($e->getMessage(), '.')));
        }
    }

    /** @return array{allow: bool, only_to: int, allow_topics: bool, allow_broadcast: bool, allow_channel_management: bool} */
    private function live(ConfigReader $c): array
    {
        $onlyTo = $c->raw('live.only_to');
        $count = is_array($onlyTo) ? count($onlyTo) : (is_string($onlyTo) && $onlyTo !== '' ? count(array_filter(explode(',', $onlyTo))) : 0);
        $flag = static function (mixed $value): bool {
            return $value === true || $value === 1 || (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true));
        };

        return [
            'allow' => $flag($c->raw('live.allow')),
            'only_to' => $count,
            'allow_topics' => $flag($c->raw('live.allow_topics')),
            'allow_broadcast' => $flag($c->raw('live.allow_broadcast')),
            'allow_channel_management' => $flag($c->raw('live.allow_channel_management')),
        ];
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasValue($item)) {
                    return true;
                }
            }

            return false;
        }

        return $value !== null && $value !== '' && $value !== false;
    }

    /** @param  array<mixed>  $config */
    private function safeProvider(array $config): ?Provider
    {
        return match ($config['driver'] ?? null) {
            'apns' => Provider::Apns,
            'fcm' => Provider::Fcm,
            default => null,
        };
    }

    /** @param  array{allow: bool, only_to: int, allow_topics: bool, allow_broadcast: bool, allow_channel_management: bool}|null  $live */
    private function failed(string $name, ?Provider $provider, string $message, ?array $live = null): ConnectionInfo
    {
        return new ConnectionInfo($name, $provider, null, null, null, null, null, null, [], [], $message, $live ?? [
            'allow' => false, 'only_to' => 0, 'allow_topics' => false, 'allow_broadcast' => false, 'allow_channel_management' => false,
        ], false);
    }

    private static function daysLeft(CertificateInfo $info, ClockInterface $clock): int
    {
        return $info->daysRemaining($clock->now());
    }
}
