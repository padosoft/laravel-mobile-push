<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\Finding;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentials;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Sending\InspectedCredential;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * The credentials of the push manager's connections in the current scope, for the health module
 * (HealthInspector readiness, CredentialMonitor / `mobile-push:credentials:check`).
 *
 * Each connection is resolved the way a send resolves it (ConnectionFactory: secrets read, keys parsed, credentials
 * inspected); what is handed over are the public facts ConnectionFactory already collected (certificate subject,
 * validity and fingerprint, key type and public fingerprint, service-account principal and key id), never key
 * material. A connection that cannot be resolved becomes an entry with `error` set to the factory's message, which
 * names config keys, never values. The primary credential fills the certificate / key / service-account slots; the
 * secondary one is evaluated into `extra`, its findings prefixed with "secondary". No request is sent to any provider.
 *
 * Connections supplied only by MobilePush::resolveConnectionUsing() cannot be listed, so they are not reported.
 */
final readonly class ManagerCredentialsSource implements ConnectionCredentialsSource
{
    /**
     * @param  \Closure(): PushManager  $manager
     * @param  \Closure(): CredentialCheck  $check
     */
    public function __construct(
        private \Closure $manager,
        private \Closure $check,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function connections(): array
    {
        $manager = ($this->manager)();
        $scope = $manager->currentScope();
        $out = [];
        foreach ($manager->connectionNames($scope) as $name) {
            try {
                $connection = $manager->resolve($scope, $name);
            } catch (PushException $e) {
                $out[] = new ConnectionCredentials(self::unresolved($manager, $scope, $name), error: $e->getMessage());

                continue;
            }
            $out[] = $this->describe($connection->binding, $connection->credentials);
        }

        return $out;
    }

    /** @param  list<InspectedCredential>  $credentials */
    private function describe(BindingRef $binding, array $credentials): ConnectionCredentials
    {
        $primary = null;
        $extra = [];
        foreach ($credentials as $credential) {
            if ($primary === null && $credential->role === 'primary') {
                $primary = $credential;

                continue;
            }
            $extra[] = self::prefixed($this->evaluate($credential), $credential->role);
        }
        if ($primary?->applicationDefault === true) {
            $extra[] = new CheckResult([Finding::ok('credentials.adc', 'Application Default Credentials: checked by Google at the first token exchange, not inspectable locally.')]);
        }

        return new ConnectionCredentials(
            $binding,
            certificate: $primary?->certificate,
            apnsKey: $primary?->apnsKey,
            serviceAccount: $primary?->serviceAccount,
            extra: $extra,
        );
    }

    private function evaluate(InspectedCredential $credential): CheckResult
    {
        $check = ($this->check)();
        $result = new CheckResult;
        if ($credential->certificate !== null) {
            $result = $result->merge($check->certificate($credential->certificate));
        }
        if ($credential->apnsKey !== null) {
            $result = $result->merge($check->apnsKey($credential->apnsKey));
        }
        if ($credential->serviceAccount !== null) {
            $result = $result->merge($check->serviceAccount($credential->serviceAccount));
        }

        return $result;
    }

    private static function prefixed(CheckResult $result, string $role): CheckResult
    {
        $findings = [];
        foreach ($result->findings as $finding) {
            $findings[] = new Finding($finding->severity, $role.'.'.$finding->code, ucfirst($role).' credential: '.$finding->message);
        }

        return new CheckResult($findings);
    }

    /** A best-effort reference for a connection that does not resolve (its app id is not known without resolving). */
    private static function unresolved(PushManager $manager, string $scope, string $name): BindingRef
    {
        try {
            $provider = $manager->providerOf($scope, $name);
        } catch (PushException) {
            $provider = Provider::Apns;
        }

        return new BindingRef($scope, $name, $provider, 'unresolved', Environment::Production, 'unresolved');
    }
}
