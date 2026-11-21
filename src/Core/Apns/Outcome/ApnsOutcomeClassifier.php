<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Outcome;

use Padosoft\MobilePush\Core\Outcome\ClassifyContext;
use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;

/**
 * Classifies APNs answers (device pushes, broadcast publications, channel management).
 *
 * Pure and total: every Exchange yields one Decision, nothing is thrown, nothing is done.
 */
final readonly class ApnsOutcomeClassifier implements OutcomeClassifier
{
    public function __construct(
        /**
         * Minimum delay before retrying a 5xx. Apple, "Handling notification responses from APNs":
         * "After 15 minutes, you can retry JSON payloads that receive response status codes that begin with 5XX.
         * While retrying, you may employ a back-off technique."
         */
        public int $serverErrorRetryDelaySeconds = 900,
        /** Minimum delay after 429 TooManyRequests (Apple: "You can retry with a delay"; no figure given). */
        public int $deviceRateLimitDelaySeconds = 60,
    ) {}

    public function classify(Exchange $exchange, ClassifyContext $context): Decision
    {
        try {
            return $exchange->response !== null
                ? $this->classifyResponse($exchange->response, $context)
                : $this->classifyFailure($exchange->failure ?? new TransportFailure(TransportEvidence::Unknown, 0, ''));
        } catch (\Throwable) {
            // Defensive: classification must be total. Not knowing how to read an answer is not a failure.
            return new Decision(
                SendStatus::OutcomeUnknown,
                Reason::of(ReasonCode::ResponseUnparseable, null, 'The APNs answer could not be interpreted; whether it was accepted is unknown.'),
                retryable: true,
                httpStatus: $exchange->response?->status,
            );
        }
    }

    private function classifyFailure(TransportFailure $failure): Decision
    {
        $meta = ['transport_errno' => (string) $failure->errno];

        if ($failure->evidence === TransportEvidence::NotSent) {
            return new Decision(
                SendStatus::Failed,
                Reason::of(ReasonCode::TransportNotSent, null, 'The request never reached APNs (DNS, connect or TLS failed before it was written): it is safe to retry; check network, proxy and firewall to api.push.apple.com.'),
                retryable: true,
                meta: $meta,
            );
        }

        return new Decision(
            SendStatus::OutcomeUnknown,
            Reason::of(ReasonCode::TransportUnknown, null, 'The connection failed after the request was sent: APNs may or may not have accepted it. It is retried only if the message is replaceable (unknown_outcome policy).'),
            retryable: true,
            meta: $meta,
        );
    }

    private function classifyResponse(Response $response, ClassifyContext $context): Decision
    {
        $status = $response->status;
        $messageId = $response->header('apns-id') ?? $response->header('apns-request-id');
        $meta = [];
        $uniqueId = $response->header('apns-unique-id');
        if ($uniqueId !== null && $uniqueId !== '') {
            $meta['apns-unique-id'] = $uniqueId;
        }

        if ($status >= 200 && $status < 300) {
            return new Decision(SendStatus::Accepted, Reason::none(), httpStatus: $status, providerMessageId: $messageId, meta: $meta);
        }

        $body = $response->json();
        $raw = isset($body['reason']) && is_string($body['reason']) && $body['reason'] !== '' ? $body['reason'] : null;
        $entry = $raw === null ? null : ApnsReasonCatalog::find($raw);

        if ($entry === null) {
            $entry = $raw === null ? self::byStatus($status) : self::unknownReason($raw, $status);
        }

        if ($entry === null) {
            return new Decision(
                SendStatus::OutcomeUnknown,
                Reason::of(ReasonCode::ResponseUnparseable, $raw, sprintf('APNs answered with HTTP %d, which is not a provider answer: whether it was accepted is unknown.', $status)),
                retryable: true,
                httpStatus: $status,
                providerMessageId: $messageId,
                meta: $meta,
            );
        }

        $destinationAction = $entry->destinationAction;
        if (! $context->destination->kind()->isIndividual()) {
            $destinationAction = DestinationAction::None;
        }
        // Invalidation needs Apple's explicit statement: the 410 status.
        if ($destinationAction === DestinationAction::Invalidate && $status !== 410) {
            $destinationAction = DestinationAction::None;
        }

        $invalidatedAtMs = null;
        if ($status === 410) {
            $invalidatedAtMs = self::timestampMs($body['timestamp'] ?? null);
        }

        return new Decision(
            $entry->status,
            Reason::of($entry->code, $raw, $entry->hint),
            retryable: $entry->retryable,
            minDelaySeconds: match ($entry->delay) {
                ApnsDelay::None => null,
                ApnsDelay::ServerError => $this->serverErrorRetryDelaySeconds,
                ApnsDelay::DeviceRateLimit => $this->deviceRateLimitDelaySeconds,
            },
            destinationAction: $destinationAction,
            credentialAction: $entry->credentialAction,
            throttleProvider: $entry->throttleProvider,
            invalidatedAtMs: $destinationAction === DestinationAction::Invalidate ? $invalidatedAtMs : null,
            httpStatus: $status,
            providerMessageId: $messageId,
            meta: $meta,
        );
    }

    /**
     * No usable reason string: classify by the documented meaning of the status alone. A bare 410 does not
     * invalidate (no reason, no timestamp: not enough proof); null = not a status APNs documents for errors.
     */
    private static function byStatus(int $status): ?ApnsReason
    {
        $f = ApnsReason::failed(...);
        $r = ApnsReason::rejected(...);

        return match (true) {
            $status === 403 => $f('', 403, ReasonCode::AuthForbidden, 'APNs refused the credential (HTTP 403 without reason): check the key or certificate.', credential: CredentialAction::ReportAuthFailure),
            $status === 410 => $r('', 410, ReasonCode::DestinationUnregistered, 'APNs says the token is no longer active (HTTP 410) but gave no reason or timestamp: the registration is kept; it is invalidated only on an explicit Unregistered/ExpiredToken.'),
            $status === 413 => $r('', 413, ReasonCode::PayloadTooLarge, 'The payload exceeds the APNs limit: shorten it.'),
            $status === 429 => $f('', 429, ReasonCode::DestinationRateLimited, 'APNs throttled the request (HTTP 429): retried later.', retryable: true, delay: ApnsDelay::DeviceRateLimit),
            $status === 500 => $f('', 500, ReasonCode::ProviderInternal, 'APNs internal error (HTTP 500): retried after the 5xx delay.', retryable: true, delay: ApnsDelay::ServerError),
            $status === 503 => $f('', 503, ReasonCode::ProviderUnavailable, 'APNs is unavailable (HTTP 503): retried after the 5xx delay; sends are throttled meanwhile.', retryable: true, throttle: true, delay: ApnsDelay::ServerError),
            in_array($status, [400, 404, 405], true) => $r('', $status, ReasonCode::RequestInvalid, sprintf('APNs refused the request (HTTP %d without reason): inspect the headers and path.', $status)),
            $status >= 400 && $status < 600 => self::unknownReason(null, $status),
            default => null,
        };
    }

    /** A reason this version does not know (or an undocumented status): decide by HTTP class. */
    private static function unknownReason(?string $raw, int $status): ?ApnsReason
    {
        $hint = 'APNs answered with a reason this package version does not know: see rawReason and Apple\'s documentation.';

        return match (true) {
            $status === 429 => ApnsReason::failed((string) $raw, 429, ReasonCode::Unknown, $hint, retryable: true, delay: ApnsDelay::DeviceRateLimit),
            $status >= 400 && $status < 500 => ApnsReason::rejected((string) $raw, $status, ReasonCode::Unknown, $hint),
            $status >= 500 && $status < 600 => ApnsReason::failed((string) $raw, $status, ReasonCode::Unknown, $hint, retryable: true, delay: ApnsDelay::ServerError),
            default => null,
        };
    }

    /** The 410 "timestamp": Unix time in MILLISECONDS (Apple: "represented in milliseconds since Epoch"). */
    private static function timestampMs(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value) && is_finite($value) && $value >= 1 && $value < 9.2e18) {
            return (int) $value;   // a fraction of a millisecond is "no timestamp", as 0 is for the other shapes
        }
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }
}
