<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/** One logical destination of one effect, as stored. Never holds a clear token. */
final readonly class Delivery
{
    public function __construct(
        public string $id,
        public string $effectId,
        public string $scope,
        public DestinationKind $kind,
        /** Registry device (`dev:<kind>:<id>`; rows written before W5-P: `dev:<kind>:<id>:<generation>`) or the TokenIndex value. */
        public string $logicalKey,
        /** Registry snapshot (device id, generation, owner) when the destination came from the registry. */
        public ?DestinationRef $deviceRef,
        public ?string $ownerType,
        public ?string $ownerId,
        public DeliveryState $state,
        public int $attempts,
        public ?RetryBudget $budget,
        public ?\DateTimeImmutable $nextAttemptAt,
        public ?string $leaseOwner,
        public ?\DateTimeImmutable $leaseExpiresAt,
        public ?\DateTimeImmutable $attemptStartedAt,
        public int $version,
        public bool $cancelRequested,
        public bool $replaceable,
        public ?\DateTimeImmutable $expiresAt,
        public ?string $campaignId,
        public ?string $providerMessageId,
        public Reason $reason,
        public ?int $httpStatus,
        public DestinationAction $destinationAction,
        public ?int $invalidatedAtMs,
        public ?\DateTimeImmutable $acceptedAt,
        public ?\DateTimeImmutable $clientReceivedAt,
        public ?\DateTimeImmutable $clientOpenedAt,
        public bool $hasStoredDestination,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        /** The ledger-assigned UUIDv4 sent as the request's id on every attempt; what a receipt marker names. */
        public ?string $messageId = null,
    ) {}

    /** @param array<string, mixed>|object $row a stored deliveries row */
    public static function fromRow(array|object $row): self
    {
        $row = Row::of($row);
        $deviceId = Row::strOrNull($row, 'device_id');
        $ownerType = Row::strOrNull($row, 'owner_type');
        $ownerId = Row::strOrNull($row, 'owner_id');
        $budget = Row::json($row, 'budget');
        $code = Row::strOrNull($row, 'reason_code');

        return new self(
            Row::str($row, 'id'),
            Row::str($row, 'effect_id'),
            Row::str($row, 'scope'),
            DestinationKind::from(Row::str($row, 'kind')),
            Row::str($row, 'logical_key'),
            $deviceId === null ? null : new DestinationRef($deviceId, Row::intOrNull($row, 'device_generation') ?? 1, $ownerType, $ownerId),
            $ownerType,
            $ownerId,
            DeliveryState::from(Row::str($row, 'state')),
            Row::int($row, 'attempts'),
            $budget === null ? null : RetryBudget::fromArray($budget),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'next_attempt_at')),
            Row::strOrNull($row, 'lease_owner'),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'lease_expires_at')),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'attempt_started_at')),
            Row::int($row, 'version'),
            Row::bool($row, 'cancel_requested'),
            Row::bool($row, 'replaceable'),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'expires_at')),
            Row::strOrNull($row, 'campaign_id'),
            Row::strOrNull($row, 'provider_message_id'),
            new Reason(
                $code === null ? ReasonCode::None : (ReasonCode::tryFrom($code) ?? ReasonCode::Unknown),
                Row::strOrNull($row, 'raw_reason'),
                Row::strOrNull($row, 'hint'),
            ),
            Row::intOrNull($row, 'http_status'),
            DestinationAction::tryFrom(Row::strOrNull($row, 'destination_action') ?? 'none') ?? DestinationAction::None,
            Row::intOrNull($row, 'invalidated_at_ms'),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'accepted_at')),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'client_received_at')),
            LedgerTime::dateOrNull(Row::intOrNull($row, 'client_opened_at')),
            Row::strOrNull($row, 'destination') !== null,
            LedgerTime::date(Row::int($row, 'created_at')),
            LedgerTime::date(Row::int($row, 'updated_at')),
            Row::strOrNull($row, 'message_id'),
        );
    }

    /**
     * The public view of this row. Waiting and in-flight rows read as Deferred; a cancelled row reads as Skipped
     * with ReasonCode::Cancelled.
     */
    public function toSendResult(?Destination $destination = null): SendResult
    {
        $reason = $this->state === DeliveryState::Cancelled && $this->reason->code === ReasonCode::None
            ? Reason::of(ReasonCode::Cancelled)
            : $this->reason;

        return new SendResult(
            $destination ?? new RecordedDestination($this->kind, $this->logicalKey, $this->deviceRef),
            $this->state->toStatus(),
            $reason,
            $this->attempts,
            $this->providerMessageId,
            $this->httpStatus,
            $this->state->isTerminal() ? null : $this->nextAttemptAt,
            $this->destinationAction,
            $this->invalidatedAtMs,
            $this->id,
        );
    }
}
