<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Outcome;

use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * One entry of the FCM reason catalog: what an error code means and how the package reacts to it.
 */
final readonly class FcmReason
{
    public function __construct(
        public string $raw,
        public ReasonCode $code,
        public SendStatus $status,
        public string $hint,
        public bool $retryable = false,
        public DestinationAction $destinationAction = DestinationAction::None,
        public CredentialAction $credentialAction = CredentialAction::None,
        public bool $throttleProvider = false,
        public FcmDelay $delay = FcmDelay::None,
        /** HTTP status the code usually comes with (informative). */
        public ?int $typicalStatus = null,
    ) {}

    public static function rejected(string $raw, ?int $status, ReasonCode $code, string $hint, DestinationAction $action = DestinationAction::None): self
    {
        return new self($raw, $code, SendStatus::Rejected, $hint, destinationAction: $action, typicalStatus: $status);
    }

    public static function failed(
        string $raw,
        ?int $status,
        ReasonCode $code,
        string $hint,
        bool $retryable = false,
        CredentialAction $credential = CredentialAction::None,
        bool $throttle = false,
        FcmDelay $delay = FcmDelay::None,
        SendStatus $outcome = SendStatus::Failed,
    ): self {
        return new self($raw, $code, $outcome, $hint, $retryable, DestinationAction::None, $credential, $throttle, $delay, $status);
    }
}
