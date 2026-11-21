<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsDelay;
use Padosoft\MobilePush\Core\Apns\Outcome\ApnsReasonCatalog;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmDelay;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmReasonCatalog;
use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * Single list behind `mobile-push:explain`: the APNs and FCM catalogs (the same objects the classifiers use) plus a text
 * for every package ReasonCode. Order is fixed (APNs, FCM error codes, FCM statuses, package codes) so the
 * `--all` output is stable enough to be embedded in the documentation.
 */
final class ExplainCatalog
{
    /** @var list<ExplainEntry>|null */
    private static ?array $entries = null;

    /** @return list<ExplainEntry> */
    public static function all(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $entries = [];
        foreach (ApnsReasonCatalog::all() as $reason) {
            $behaviour = self::behaviour($reason->retryable, $reason->destinationAction, $reason->credentialAction, $reason->throttleProvider, match ($reason->delay) {
                ApnsDelay::ServerError => 'waits at least the 5xx delay Apple recommends (apns.server_error_retry_delay, default 15 minutes)',
                ApnsDelay::DeviceRateLimit => 'waits at least apns.device_rate_limit_delay (default 60 seconds)',
                ApnsDelay::None => null,
            }, $reason->status);
            $entries[] = new ExplainEntry('apns', 'APNs reason', $reason->raw, $reason->documentedStatus, $reason->code, $reason->status, $reason->hint, $behaviour);
        }
        foreach ([['FCM error code', FcmReasonCatalog::errorCodes()], ['FCM status', FcmReasonCatalog::statuses()]] as [$source, $list]) {
            foreach ($list as $reason) {
                $behaviour = self::behaviour($reason->retryable, $reason->destinationAction, $reason->credentialAction, $reason->throttleProvider, match ($reason->delay) {
                    FcmDelay::RetryAfter => 'honours the Retry-After header',
                    FcmDelay::Throttled => 'waits at least fcm.throttled_min_delay (default 60 seconds)',
                    FcmDelay::None => null,
                }, $reason->status);
                $entries[] = new ExplainEntry('fcm', $source, $reason->raw, $reason->typicalStatus, $reason->code, $reason->status, $reason->hint, $behaviour);
            }
        }
        foreach (ReasonCode::cases() as $code) {
            [$meaning, $behaviour] = self::package($code);
            $entries[] = new ExplainEntry('package', 'Package reason code', $code->value, null, $code, null, $meaning, $behaviour);
        }

        return self::$entries = $entries;
    }

    /**
     * Entries whose key matches (ignoring case, spaces, dashes and underscores).
     *
     * @return list<ExplainEntry>
     */
    public static function find(string $query, ?string $provider = null): array
    {
        $needle = self::normalize($query);

        return array_values(array_filter(
            self::all(),
            static fn (ExplainEntry $e): bool => ($provider === null || $e->provider === $provider) && self::normalize($e->key) === $needle,
        ));
    }

    /**
     * The closest keys to an unknown query.
     *
     * @return list<ExplainEntry>
     */
    public static function suggest(string $query, ?string $provider = null, int $limit = 5): array
    {
        $needle = self::normalize($query);
        $scored = [];
        foreach (self::all() as $index => $entry) {
            if ($provider !== null && $entry->provider !== $provider) {
                continue;
            }
            $key = self::normalize($entry->key);
            $distance = levenshtein(substr($needle, 0, 60), substr($key, 0, 60));
            if ($needle !== '' && (str_contains($key, $needle) || str_contains($needle, $key))) {
                $distance = min($distance, 1);
            }
            $scored[] = [$distance, $index, $entry];
        }
        usort($scored, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $out = [];
        $seen = [];
        foreach ($scored as [, , $entry]) {
            $id = $entry->provider.'|'.$entry->key;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /** @return list<string> */
    private static function behaviour(bool $retryable, DestinationAction $destination, CredentialAction $credential, bool $throttle, ?string $delay, SendStatus $status): array
    {
        $lines = [];
        if ($status === SendStatus::OutcomeUnknown) {
            $lines[] = 'Reports the outcome as unknown: the message may have been processed. It is retried only when it is replaceable (or by explicit opt-in), and never triggers a fallback or an invalidation.';
        } elseif ($retryable) {
            $lines[] = 'Retries within the retry budget'.($delay !== null ? ' and '.$delay : ' with exponential backoff').'.';
        } else {
            $lines[] = 'Does not retry: the same request would fail again.';
        }
        $lines[] = 'Final status: '.$status->value.'.';

        $lines = [...$lines, ...match ($destination) {
            DestinationAction::Quarantine => ['Quarantines the registration (kept, but not used until it is probed or re-registered).'],
            DestinationAction::Invalidate => ['Invalidates the registration (the device is no longer sent to).'],
            DestinationAction::None => [],
        }];
        $lines = [...$lines, ...match ($credential) {
            CredentialAction::RefreshToken => ['Refreshes the provider token once and retries.'],
            CredentialAction::SlowTokenRefresh => ['Slows provider token renewals (never switches credential).'],
            CredentialAction::ReportAuthFailure => ['Reports an authentication failure to the circuit breaker (it opens after repeated failures and may fail over to the secondary credential).'],
            CredentialAction::None => [],
        }];
        if ($throttle) {
            $lines[] = 'Pauses sending to this provider account for a while (throttle gate).';
        }

        return $lines;
    }

    /** @return array{0: string, 1: list<string>} */
    private static function package(ReasonCode $code): array
    {
        return match ($code) {
            ReasonCode::None => ['No reason: the destination was accepted by the provider.', ['Nothing to do.']],
            ReasonCode::DestinationUnregistered => ['The provider says the device token is no longer valid (app uninstalled, token expired or rotated). The device registry should forget it.', ['Invalidates the registration; never retries.']],
            ReasonCode::DestinationRejected => ['The provider refused the token: malformed, or it belongs to the other environment (APNs sandbox vs production). Check the environment the app declared at registration.', ['Quarantines the registration instead of deleting it; no retry.']],
            ReasonCode::DestinationMismatch => ['The token belongs to another app, topic or Firebase project than the connection. Check the bundle id or project bound to this token.', ['Quarantines the registration; no retry.']],
            ReasonCode::DestinationRateLimited => ['Too many pushes to the same device in a short time. Send less often to this device.', ['Retries later with a minimum delay.']],
            ReasonCode::ChannelUnknown => ['The APNs broadcast channel does not exist (deleted, other environment, wrong id). Sync the channels and recreate it if needed.', ['No retry.']],
            ReasonCode::PayloadInvalid => ['The provider could not accept the payload (unknown fields, values out of range, bad JSON). Fix the message.', ['No retry: the registration is not touched.']],
            ReasonCode::PayloadTooLarge => ['The payload is bigger than the provider limit (APNs 4 KB, FCM 4096 bytes, 2048 for topics). Shorten it, or opt in to truncation of the visible text.', ['No retry.']],
            ReasonCode::RequestInvalid => ['A header, path or method was refused. Usually a package bug or an invalid collapse id / priority / expiration: report it with the raw reason.', ['No retry.']],
            ReasonCode::TopicInvalid => ['The APNs topic (bundle id and its suffix) is wrong for this push type or credential. Check the bundle id of the connection.', ['No retry.']],
            ReasonCode::FeatureNotEnabled => ['The capability (for example broadcast push) is not enabled for this app in the provider account. Enable it in the Apple developer account.', ['No retry.']],
            ReasonCode::AuthTokenExpired => ['The provider token is stale. Check the server clock if it repeats.', ['Refreshes the token once and retries the push.']],
            ReasonCode::AuthTokenInvalid => ['The provider token or key was refused: wrong key id, team id, environment or revoked key. Fix the connection credentials.', ['Reports a failure to the authentication breaker; it opens after repeated failures and may fail over to the secondary credential.']],
            ReasonCode::AuthTokenThrottled => ['The provider token was renewed too often (Apple: at most once every 20 minutes).', ['Slows token renewals; never switches credential.']],
            ReasonCode::AuthCertificateInvalid => ['The client certificate was refused: expired, revoked, or for the other environment. Renew it or use a .p8 key.', ['Reports a failure to the authentication breaker.']],
            ReasonCode::AuthForbidden => ['The credential is not allowed to do this (service account without the FCM permission, key without the capability).', ['Reports a failure to the authentication breaker; no retry.']],
            ReasonCode::AuthThirdParty => ['FCM could not authenticate to Apple or the browser vendor with the credential configured in the Firebase project. Fix it in the Firebase console (not the package credential).', ['No retry.']],
            ReasonCode::ChannelLimitReached => ['The app reached the maximum number of APNs broadcast channels for this environment (about 10,000, or the configured cap). Delete channels you no longer use before creating new ones.', ['No retry: creating the channel again fails the same way.']],
            ReasonCode::QuotaExceeded => ['The provider quota was exceeded (per project, device or topic; or the channel limit for APNs broadcast).', ['Retries after at least a minute and pauses sending to the account.']],
            ReasonCode::ProviderUnavailable => ['The provider is temporarily unavailable.', ['Retries with backoff, honouring the provider delay, and throttles the account meanwhile.']],
            ReasonCode::ProviderInternal => ['The provider had an internal error.', ['Retries with backoff.']],
            ReasonCode::ProviderShutdown => ['The provider server is shutting down.', ['Retries on a new connection after the provider delay.']],
            ReasonCode::TransportNotSent => ['The request provably never left: DNS, connect or TLS failed before any request byte was written.', ['Safe to retry; counted as a known failure when the budget ends.']],
            ReasonCode::TransportUnknown => ['The request was written but the answer was lost (reset, timeout, GOAWAY): the provider may or may not have processed it.', ['Status outcome_unknown. Retried only for replaceable messages (unknown_outcome.retry); no fallback, no invalidation.']],
            ReasonCode::ResponseUnparseable => ['The provider answered with something the package cannot read, so the outcome is not known.', ['Status outcome_unknown; treated like a lost answer.']],
            ReasonCode::Expired => ['The logical expiry of the message passed before an attempt could be made. Nothing was sent.', ['Final: no more attempts.']],
            ReasonCode::Simulated => ['Simulation mode: no request was sent. Set MOBILE_PUSH_MODE=live (outside production also live.allow=true and the device in live.only_to) to send for real.', ['Nothing is sent; the result is final.']],
            ReasonCode::Validated => ['FCM validate_only: Google validated the request and did not deliver it. It is not a delivery test.', ['Final: nothing is delivered.']],
            ReasonCode::PolicyIneligible => ['The application consent or eligibility check said this destination must not receive the push now. Nothing was sent.', ['Skipped: no attempt, no fallback.']],
            ReasonCode::PolicyFrequencyCapped => ['The frequency cap of the message policy was reached for this person. Nothing was sent.', ['Skipped: no attempt.']],
            ReasonCode::PolicySafeMode => ['Safe mode blocked a live send outside production (live.allow is off, the destination is not in live.only_to, or topics/broadcast need explicit authorisation). Nothing was sent.', ['Skipped: no connection was opened.']],
            ReasonCode::CircuitOpen => ['A circuit breaker is open (repeated credential failures, or too many bad destinations in a bulk send). Run mobile-push:circuit to see it.', ['Skipped or deferred until the cooldown ends, then probed with a few requests.']],
            ReasonCode::BindingMismatch => ['The connection name no longer points to the app, provider or environment the push was prepared for (for example after a reconfiguration). Nothing was sent and no other connection was used.', ['Skipped: re-create the push for a configured connection.']],
            ReasonCode::Cancelled => ['The send was cancelled before an attempt was made.', ['Final: no more attempts.']],
            ReasonCode::RetryBudgetExhausted => ['The retry budget (attempts or time) ended while the provider was still failing.', ['Final: the destination is reported as failed.']],
            ReasonCode::Unknown => ['The provider answered with something this version does not know. Read the raw reason shown next to it.', ['No automatic action beyond the status; please report the raw reason.']],
        };
    }
}
