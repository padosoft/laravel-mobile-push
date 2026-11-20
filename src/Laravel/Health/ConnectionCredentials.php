<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Padosoft\MobilePush\Core\Credentials\ApnsKeyInfo;
use Padosoft\MobilePush\Core\Credentials\CertificateInfo;
use Padosoft\MobilePush\Core\Credentials\CheckResult;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Credentials\ServiceAccountInfo;
use Padosoft\MobilePush\Core\Identity\BindingRef;

/**
 * What one connection's credentials look like, already inspected by the Core inspectors (public facts only: no key
 * material). Built by the ConnectionCredentialsSource implementation of the integration step.
 */
final readonly class ConnectionCredentials
{
    /**
     * @param  ?string  $error  set when the credentials could not even be read or parsed (class name or short sanitized text, never key material)
     * @param  list<CheckResult>  $extra  additional results computed by the source (for instance CredentialCheck::sameIdentity)
     */
    public function __construct(
        public BindingRef $binding,
        public ?CertificateInfo $certificate = null,
        public ?ApnsKeyInfo $apnsKey = null,
        public ?ServiceAccountInfo $serviceAccount = null,
        public ?string $error = null,
        public array $extra = [],
    ) {}

    public function evaluate(CredentialCheck $check): CheckResult
    {
        $result = new CheckResult;
        if ($this->certificate !== null) {
            $result = $result->merge($check->certificate($this->certificate));
        }
        if ($this->apnsKey !== null) {
            $result = $result->merge($check->apnsKey($this->apnsKey));
        }
        if ($this->serviceAccount !== null) {
            $result = $result->merge($check->serviceAccount($this->serviceAccount));
        }
        foreach ($this->extra as $extra) {
            $result = $result->merge($extra);
        }

        return $result;
    }
}
