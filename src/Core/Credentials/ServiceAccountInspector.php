<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;

/** Inspects a Google service-account JSON without network access. */
final class ServiceAccountInspector
{
    private const REQUIRED = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];

    /** @throws ConfigurationException when the text is not a JSON object */
    public function fromJson(#[\SensitiveParameter] string $json): ServiceAccountInfo
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConfigurationException('The service-account file is not valid JSON. Download the key again from the Google Cloud console (IAM > Service accounts > Keys).');
        }
        if (! is_array($data)) {
            throw new ConfigurationException('The service-account file is not a JSON object.');
        }

        return $this->fromArray($data);
    }

    /** @param  array<mixed>  $data */
    public function fromArray(#[\SensitiveParameter] array $data): ServiceAccountInfo
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        $missing = [];
        foreach (self::REQUIRED as $field) {
            if ($string($field) === null) {
                $missing[] = $field;
            }
        }

        $keyParses = false;
        $keyType = null;
        $keyBits = null;
        $pem = $string('private_key');
        if ($pem !== null) {
            try {
                $key = PrivateKey::fromPem($pem);
                $keyParses = true;
                $keyType = $key->typeName();
                $keyBits = $key->bits();
            } catch (AuthenticationException) {
                // reported by CredentialCheck as an unparseable key
            }
        }

        $tokenUri = $string('token_uri');

        return new ServiceAccountInfo(
            missingFields: $missing,
            type: $string('type'),
            clientEmail: $string('client_email'),
            projectId: $string('project_id'),
            privateKeyId: $string('private_key_id'),
            tokenUri: $tokenUri,
            tokenUriIsOfficial: $tokenUri === null || $tokenUri === ServiceAccountTokenProvider::TOKEN_ENDPOINT,
            keyParses: $keyParses,
            keyType: $keyType,
            keyBits: $keyBits,
        );
    }
}
