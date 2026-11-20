<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/** Inspects a `.p8` key. The fingerprint is of the PUBLIC key, so equal keys are recognised without comparing secrets. */
final class ApnsKeyInspector
{
    /** @throws ConfigurationException */
    public function inspect(#[\SensitiveParameter] string $p8Pem): ApnsKeyInfo
    {
        try {
            $key = PrivateKey::fromPem($p8Pem);
        } catch (AuthenticationException) {
            throw new ConfigurationException('The APNs key could not be parsed. Provide the .p8 file downloaded from the Apple developer portal (PKCS#8 PEM).');
        }

        $details = openssl_pkey_get_details($key->handle());
        $public = is_array($details) ? ($details['key'] ?? null) : null;
        $body = is_string($public) ? preg_replace('/-----(?:BEGIN|END) PUBLIC KEY-----|\s+/', '', $public) : null;
        $der = is_string($body) ? base64_decode($body, true) : false;
        if ($der === false || $der === '') {
            throw new ConfigurationException('The public part of the APNs key could not be derived.');
        }

        return new ApnsKeyInfo(
            keyType: $key->typeName(),
            curve: $key->curve(),
            publicFingerprintSha256: hash('sha256', $der),
            isEcP256: $key->isEc() && $key->curve() === 'prime256v1',
        );
    }
}
