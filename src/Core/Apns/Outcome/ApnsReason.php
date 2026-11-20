<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Outcome;

use Padosoft\MobilePush\Core\Outcome\CredentialAction;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/**
 * One entry of the APNs reason catalog: what a `reason` string means and how the package reacts to it.
 */
final readonly class ApnsReason
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
        public ApnsDelay $delay = ApnsDelay::None,
        /** HTTP status Apple documents for this reason (informative; null when undocumented). */
        public ?int $documentedStatus = null,
    ) {}

    public static function rejected(string $raw, int $status, ReasonCode $code, string $hint, DestinationAction $action = DestinationAction::None): self
    {
        return new self($raw, $code, SendStatus::Rejected, $hint, destinationAction: $action, documentedStatus: $status);
    }

    public static function failed(
        string $raw,
        int $status,
        ReasonCode $code,
        string $hint,
        bool $retryable = false,
        CredentialAction $credential = CredentialAction::None,
        bool $throttle = false,
        ApnsDelay $delay = ApnsDelay::None,
    ): self {
        return new self($raw, $code, SendStatus::Failed, $hint, $retryable, DestinationAction::None, $credential, $throttle, $delay, $status);
    }
}
