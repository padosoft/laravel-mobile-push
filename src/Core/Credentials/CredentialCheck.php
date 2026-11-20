<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Psr\Clock\ClockInterface;

/**
 * Turns inspection results into findings. Deterministic: the only clock is the injected one.
 * Findings never contain key material (they are built from public facts only).
 */
final class CredentialCheck
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $warnDays = 30,
    ) {}

    public function certificate(CertificateInfo $info): CheckResult
    {
        $now = $this->clock->now();
        $findings = [];

        if ($info->isNotYetValid($now)) {
            $findings[] = Finding::error('apns.certificate.not_yet_valid', sprintf('The APNs certificate is not valid before %s. Check the server clock or use the certificate that is already active.', $info->notBefore->format(DATE_ATOM)));
        } elseif ($info->isExpired($now)) {
            $findings[] = Finding::error('apns.certificate.expired', sprintf('The APNs certificate expired on %s. Create a new certificate in the Apple developer portal (or switch to a token-based .p8 key, which does not expire) and deploy it.', $info->notAfter->format(DATE_ATOM)));
        } else {
            $days = $info->daysRemaining($now);
            if ($days <= $this->warnDays) {
                $findings[] = Finding::warning('apns.certificate.expiring', sprintf('The APNs certificate expires in %d day(s), on %s. Renew it now: generate a new certificate in the Apple developer portal, install it as the secondary credential, then promote it (or switch to a .p8 key).', $days, $info->notAfter->format(DATE_ATOM)));
            } else {
                $findings[] = Finding::ok('apns.certificate.valid', sprintf('The APNs certificate is valid for %d more day(s).', $days));
            }
        }

        if ($info->keyMatches === false) {
            $findings[] = Finding::error('apns.certificate.key_mismatch', 'The private key does not belong to this certificate (it is probably the key of another app or certificate). Export the certificate together with its own private key from the keychain where it was created.');
        } elseif ($info->keyMatches === true) {
            $findings[] = Finding::ok('apns.certificate.key_match', 'The private key matches the certificate.');
        }

        if ($info->kind === ApnsCertificateKind::Unknown) {
            $findings[] = Finding::warning('apns.certificate.environment_unknown', 'The certificate does not say whether it is sandbox, production or universal. Declare the environment explicitly in the binding: it is never guessed.');
        }

        return new CheckResult($findings);
    }

    public function apnsKey(ApnsKeyInfo $info): CheckResult
    {
        if (! $info->isEcP256) {
            return new CheckResult([Finding::error('apns.key.not_p256', sprintf('The APNs key must be an EC P-256 key (ES256); this one is %s%s. Download the .p8 key again from the Apple developer portal.', $info->keyType, $info->curve !== null ? ' ('.$info->curve.')' : ''))]);
        }

        return new CheckResult([Finding::ok('apns.key.valid', 'The APNs key parses and is EC P-256.')]);
    }

    public function serviceAccount(ServiceAccountInfo $info): CheckResult
    {
        $findings = [];

        if ($info->missingFields !== []) {
            $findings[] = Finding::error('fcm.service_account.missing_fields', 'The service-account file lacks: '.implode(', ', $info->missingFields).'. Download a complete JSON key from the Google Cloud console.');
        }
        if ($info->type !== null && ! $info->typeIsServiceAccount()) {
            $findings[] = Finding::error('fcm.service_account.wrong_type', 'The credentials file has type "'.self::safe($info->type).'", not "service_account". Authorized-user and external-account files need the google/auth adapter (GoogleAuthLibraryProvider).');
        }
        if (! $info->keyParses) {
            if (! in_array('private_key', $info->missingFields, true)) {
                $findings[] = Finding::error('fcm.service_account.key_unreadable', 'The private_key in the file cannot be parsed. Download the key again; do not edit the file.');
            }
        } elseif (! $info->keyIsStrongRsa()) {
            $findings[] = Finding::error('fcm.service_account.key_weak', sprintf('The service-account key must be RSA of at least 2048 bits; this one is %s %d bits.', (string) $info->keyType, $info->keyBits ?? 0));
        }
        if (! $info->tokenUriIsOfficial) {
            $findings[] = Finding::warning('fcm.service_account.token_uri_ignored', sprintf('The file points token_uri to "%s". The package ignores it and always uses %s. If you really need another endpoint, use the google/auth adapter.', self::safe((string) $info->tokenUri), ServiceAccountTokenProvider::TOKEN_ENDPOINT));
        }
        if ($findings === []) {
            $findings[] = Finding::ok('fcm.service_account.valid', 'The service-account file is complete and its key is RSA >= 2048.');
        }

        return new CheckResult($findings);
    }

    /** Primary and secondary credentials must belong to the same identity: there is no failover across identities. */
    public function sameIdentity(CredentialIdentity $primary, CredentialIdentity $secondary): CheckResult
    {
        $findings = [];

        if ($primary->provider !== $secondary->provider) {
            $findings[] = Finding::error('credentials.identity.provider_mismatch', 'The primary and secondary credentials are for different providers. A secondary is a rotation twin of the primary, never a fallback to another provider.');
        } else {
            if ($primary->owner !== null && $secondary->owner !== null && $primary->owner !== $secondary->owner) {
                $what = $primary->provider->value === 'apns' ? 'team' : 'project';
                $findings[] = Finding::error('credentials.identity.owner_mismatch', sprintf('The primary and secondary credentials belong to different %ss ("%s" vs "%s"). The package never fails over across identities: use a secondary from the same %s, or remove it.', $what, self::safe($primary->owner), self::safe($secondary->owner), $what));
            }
            if ($primary->environment !== null && $secondary->environment !== null && $primary->environment !== $secondary->environment) {
                $findings[] = Finding::error('credentials.identity.environment_mismatch', sprintf('The primary credential is for %s and the secondary for %s. Use credentials of the same environment.', $primary->environment->value, $secondary->environment->value));
            }
        }

        return new CheckResult($findings === [] ? [Finding::ok('credentials.identity.consistent', 'Primary and secondary credentials belong to the same identity.')] : $findings);
    }

    /** Values from files are echoed in messages: keep them short and printable. */
    private static function safe(string $value): string
    {
        $clean = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return strlen($clean) > 120 ? substr($clean, 0, 117).'...' : $clean;
    }
}
