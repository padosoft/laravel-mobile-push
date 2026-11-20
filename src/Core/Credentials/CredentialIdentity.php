<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;

/**
 * The non-secret "who" of a credential: provider, team (APNs) or project (FCM) and, when it is bound to
 * one, the environment. A null environment means "not bound / unknown" and is compatible with any.
 */
final readonly class CredentialIdentity
{
    public function __construct(
        public Provider $provider,
        public ?string $owner,
        public ?Environment $environment = null,
    ) {}

    public static function apnsKey(string $teamId, ?Environment $environment = null): self
    {
        return new self(Provider::Apns, $teamId, $environment);
    }

    public static function apnsCertificate(CertificateInfo $info): self
    {
        return new self(Provider::Apns, $info->teamId, $info->environment());
    }

    public static function serviceAccount(ServiceAccountInfo $info): self
    {
        return new self(Provider::Fcm, $info->projectId, Environment::Production);
    }
}
