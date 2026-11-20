<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Topics;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;

/**
 * A topic-subscription request failed or was not allowed to start.
 *
 * Google's `error.message` can echo the registration token, so it is never copied: only the HTTP status and the
 * sanitized status/errorCode enum (for example NOT_FOUND, UNREGISTERED) are kept. `$status` is 0 when no HTTP response exists.
 */
final class TopicApiException extends PushException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly Reason $reason,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, Reason $reason): self
    {
        return new self(
            sprintf('FCM topic subscription request failed with HTTP %d (%s): %s.', $status, $reason->rawReason ?? 'no status', $reason->code->value),
            $status, $reason,
        );
    }

    public static function transport(Reason $reason): self
    {
        return new self('FCM topic subscription request did not complete: '.$reason->code->value.'.', 0, $reason);
    }

    public static function refused(SafeModeDecision $decision): self
    {
        return new self(
            'Topic subscription was not performed ('.$decision->reasonCode->value.'): '.($decision->hint ?? 'refused by the safe-mode guard.'),
            0, $decision->reason(),
        );
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isRefusedBeforeNetwork(): bool
    {
        return $this->reason->code === ReasonCode::Simulated || $this->reason->code === ReasonCode::PolicySafeMode;
    }
}
