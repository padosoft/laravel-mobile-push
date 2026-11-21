<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Credentials\ApnsKeyInfo;
use Padosoft\MobilePush\Core\Credentials\CertificateInfo;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInfo;

/**
 * What ConnectionFactory learned about one credential of a connection while validating it: public facts only (key
 * type and public fingerprint, certificate subject/validity/fingerprint, service-account principal and key id). No
 * key material, so it can be handed to the health and credential-monitoring code as it is.
 */
final readonly class InspectedCredential
{
    public function __construct(
        /** primary | secondary */
        public string $role,
        public ?CertificateInfo $certificate = null,
        public ?ApnsKeyInfo $apnsKey = null,
        public ?ServiceAccountInfo $serviceAccount = null,
        /** FCM Application Default Credentials: verified by Google at the first token exchange, not inspectable here. */
        public bool $applicationDefault = false,
        /** An external Signer (KMS / HSM): the key is never seen, so its type and expiry cannot be verified here. */
        public bool $externalSigner = false,
    ) {}
}
