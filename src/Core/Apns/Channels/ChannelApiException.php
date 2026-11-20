<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Channels;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;

/**
 * A channel-management request failed (or was not allowed to start).
 *
 * The message never contains the bearer token or the provider's free text: only the HTTP status and the sanitized
 * reason string. `$status` is 0 when no HTTP response exists (transport failure, simulate or blocked by safe mode).
 */
class ChannelApiException extends PushException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly Reason $reason,
        /** The `apns-request-id` echoed by APNs, or the one we generated when no response arrived. */
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(int $status, Reason $reason, ?string $requestId): self
    {
        return new self(
            sprintf('APNs channel management failed with HTTP %d (%s): %s.', $status, $reason->rawReason ?? 'no reason', $reason->code->value),
            $status, $reason, $requestId,
        );
    }

    public static function transport(Reason $reason, string $requestId): self
    {
        return new self('APNs channel management request did not complete: '.$reason->code->value.'.', 0, $reason, $requestId);
    }

    /** Safe mode said no (simulate or blocked): nothing touched the network. */
    public static function refused(SafeModeDecision $decision): self
    {
        return new self(
            'Channel management was not performed ('.$decision->reasonCode->value.'): '.($decision->hint ?? 'refused by the safe-mode guard.'),
            0, $decision->reason(),
        );
    }

    public function isRefusedBeforeNetwork(): bool
    {
        return $this->reason->code === ReasonCode::Simulated || $this->reason->code === ReasonCode::PolicySafeMode;
    }
}
