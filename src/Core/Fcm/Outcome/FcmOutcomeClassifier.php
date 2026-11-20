<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Outcome;

use Padosoft\MobilePush\Core\Outcome\ClassifyContext;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryAfter;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Psr\Clock\ClockInterface;

/**
 * Classifies FCM HTTP v1 `messages:send` answers.
 *
 * Pure and total: every Exchange yields one Decision, nothing is thrown, nothing is done.
 * `error.message` is never copied anywhere: it can echo the registration token. Reason::$rawReason is the
 * error code only (FcmError.errorCode, else error.status).
 */
final readonly class FcmOutcomeClassifier implements OutcomeClassifier
{
    private const FCM_ERROR_TYPE = 'type.googleapis.com/google.firebase.fcm.v1.FcmError';

    /** HTTP status => google.rpc status, for answers without a usable error body. */
    private const STATUS_BY_HTTP = [
        400 => 'INVALID_ARGUMENT',
        401 => 'UNAUTHENTICATED',
        403 => 'PERMISSION_DENIED',
        404 => 'NOT_FOUND',
        429 => 'RESOURCE_EXHAUSTED',
        500 => 'INTERNAL',
        503 => 'UNAVAILABLE',
        504 => 'DEADLINE_EXCEEDED',
    ];

    public function __construct(
        /** Clock used to resolve an HTTP-date Retry-After when the response has no Date header. */
        private ?ClockInterface $clock = null,
        /** Minimum delay after QUOTA_EXCEEDED / UNAVAILABLE (plan §4.5: "minimo iniziale 1 minuto"). */
        public int $throttledMinimumDelaySeconds = 60,
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
                Reason::of(ReasonCode::ResponseUnparseable, null, 'The FCM answer could not be interpreted; whether it was accepted is unknown.'),
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
                Reason::of(ReasonCode::TransportNotSent, null, 'The request never reached FCM (DNS, connect or TLS failed before it was written): it is safe to retry; check network, proxy and firewall to fcm.googleapis.com.'),
                retryable: true,
                meta: $meta,
            );
        }

        return new Decision(
            SendStatus::OutcomeUnknown,
            Reason::of(ReasonCode::TransportUnknown, null, 'The connection failed after the request was sent: FCM may or may not have accepted it. It is retried only if the message is replaceable (unknown_outcome policy).'),
            retryable: true,
            meta: $meta,
        );
    }

    private function classifyResponse(Response $response, ClassifyContext $context): Decision
    {
        $status = $response->status;
        $body = $response->json();

        if ($status >= 200 && $status < 300) {
            $name = isset($body['name']) && is_string($body['name']) && $body['name'] !== '' ? $body['name'] : null;

            return $context->validateOnly
                ? new Decision(SendStatus::Validated, Reason::of(ReasonCode::Validated), httpStatus: $status, providerMessageId: $name)
                : new Decision(SendStatus::Accepted, Reason::none(), httpStatus: $status, providerMessageId: $name);
        }

        [$raw, $entry] = self::lookup($body, $status);

        if ($entry === null) {
            return new Decision(
                SendStatus::OutcomeUnknown,
                Reason::of(ReasonCode::ResponseUnparseable, $raw, sprintf('FCM answered with HTTP %d, which is not a provider answer: whether it was accepted is unknown.', $status)),
                retryable: true,
                httpStatus: $status,
            );
        }

        $destinationAction = $context->destination->kind()->isIndividual()
            ? $entry->destinationAction
            : DestinationAction::None;

        return new Decision(
            $entry->status,
            Reason::of($entry->code, $raw, $entry->hint),
            retryable: $entry->retryable,
            minDelaySeconds: $this->minDelay($entry->delay, $response),
            destinationAction: $destinationAction,
            credentialAction: $entry->credentialAction,
            throttleProvider: $entry->throttleProvider,
            httpStatus: $status,
        );
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{0: ?string, 1: ?FcmReason} raw code (null when none was given), catalog entry (null: not a provider answer)
     */
    private static function lookup(?array $body, int $status): array
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        // 1. FcmError detail first.
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        foreach ($details as $detail) {
            if (is_array($detail) && ($detail['@type'] ?? null) === self::FCM_ERROR_TYPE
                && isset($detail['errorCode']) && is_string($detail['errorCode']) && $detail['errorCode'] !== '') {
                $code = $detail['errorCode'];

                return [$code, FcmReasonCatalog::findErrorCode($code) ?? self::unknown($code, $status)];
            }
        }

        // 2. google.rpc status only without an FcmError detail.
        $rpc = isset($error['status']) && is_string($error['status']) && $error['status'] !== '' ? $error['status'] : null;
        if ($rpc !== null) {
            return [$rpc, FcmReasonCatalog::findStatus($rpc) ?? self::unknown($rpc, $status)];
        }

        // 3. Nothing usable in the body: the HTTP status alone.
        $byHttp = self::STATUS_BY_HTTP[$status] ?? null;

        return [null, $byHttp !== null ? FcmReasonCatalog::findStatus($byHttp) : self::unknown(null, $status)];
    }

    /** A code this version does not know: decide by HTTP class (null for a non-error class). */
    private static function unknown(?string $raw, int $status): ?FcmReason
    {
        $hint = 'FCM answered with an error this package version does not know: see rawReason and the FCM error code reference.';

        return match (true) {
            $status === 429 => FcmReason::failed((string) $raw, 429, ReasonCode::Unknown, $hint, retryable: true, throttle: true, delay: FcmDelay::Throttled),
            $status >= 400 && $status < 500 => FcmReason::rejected((string) $raw, $status, ReasonCode::Unknown, $hint),
            $status >= 500 && $status < 600 => FcmReason::failed((string) $raw, $status, ReasonCode::Unknown, $hint, retryable: true, delay: FcmDelay::RetryAfter),
            default => null,
        };
    }

    private function minDelay(FcmDelay $delay, Response $response): ?int
    {
        if ($delay === FcmDelay::None) {
            return null;
        }

        $retryAfter = RetryAfter::seconds($response->header('retry-after'), $this->reference($response));

        return match ($delay) {
            FcmDelay::RetryAfter => $retryAfter,
            FcmDelay::Throttled => max($this->throttledMinimumDelaySeconds, $retryAfter ?? 0),
        };
    }

    private function reference(Response $response): \DateTimeImmutable
    {
        $date = $response->header('date');
        $parsed = $date === null ? null : RetryAfter::httpDate($date);

        return $parsed ?? $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
