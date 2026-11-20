<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Identity;

/**
 * Immutable reference to an app binding: the identity an operation was created for.
 *
 * Jobs and ledger rows carry a BindingRef (never a secret and never a bare connection name). At consume
 * time the current credential is resolved FOR THIS IDENTITY; if the live configuration no longer matches,
 * the operation stops with ReasonCode::BindingMismatch instead of falling back to a default.
 */
final readonly class BindingRef
{
    public function __construct(
        public string $scope,
        /** Connection name as configured by the host app. */
        public string $name,
        public Provider $provider,
        /** APNs: bundle id. FCM: Firebase project id. */
        public string $appId,
        public Environment $environment,
        /** Bumped on every credential rotation or reconfiguration. */
        public string $generation = '1',
    ) {}

    /** Stable identity of the binding, generation excluded: used to scope caches, limits and ledger rows. */
    public function identity(): string
    {
        return hash('sha256', implode('|', [$this->scope, $this->provider->value, $this->appId, $this->environment->value]));
    }

    /** Identity including name and generation: two refs with the same fingerprint are interchangeable. */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [$this->identity(), $this->name, $this->generation]));
    }

    public function sameIdentityAs(self $other): bool
    {
        return $this->identity() === $other->identity();
    }

    /** @return array{scope: string, name: string, provider: string, app_id: string, environment: string, generation: string} */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'app_id' => $this->appId,
            'environment' => $this->environment->value,
            'generation' => $this->generation,
        ];
    }

    /** @param array{scope: string, name: string, provider: string, app_id: string, environment: string, generation: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['scope'], $data['name'], Provider::from($data['provider']),
            $data['app_id'], Environment::from($data['environment']), $data['generation'],
        );
    }
}
