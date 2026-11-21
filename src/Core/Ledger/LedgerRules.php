<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;

/**
 * The row-level rules shared by every ledger implementation: how rows are built and how each event changes them.
 * The stores only decide HOW to apply the changes atomically (array in memory, guarded UPDATE in SQL).
 *
 * Rows use the column names of the database tables; times are epoch milliseconds.
 *
 * @internal
 */
final readonly class LedgerRules
{
    public const LOST_LEASE_HINT = 'The worker stopped after the request may have left: the provider may have accepted it.';

    public function __construct(
        private TokenIndex $index,
        private TokenCipher $cipher,
        private MessageVault $vault,
        public LedgerSettings $settings,
    ) {}

    public static function scope(string $scope): string
    {
        return Scope::normalize($scope);
    }

    /** Dedupe key column value: a fixed-length hash of the caller's effect key. */
    public static function dedupeKey(string $effectKey): string
    {
        return hash('sha256', $effectKey);
    }

    /** Whether a stored effect fingerprint (keyed or legacy) belongs to this message in this scope. */
    public function sameMessage(string $storedFingerprint, Message $message, string $scope): bool
    {
        return PayloadFingerprint::matches($storedFingerprint, $message, $this->cipher, self::scope($scope));
    }

    /**
     * The logical destination of a delivery: `dev:<kind>:<device id>` for a registry device, otherwise the HMAC index.
     *
     * The device generation is NOT part of the key (since W5-P): one effect has at most one delivery per registry
     * device, whatever happens to the device between two writes of the same intent (a crashed campaign slice that
     * runs again after the phone was re-registered). The generation planned is kept in `device_generation` and
     * checked right before the network (RegistryResolver): a delivery planned for a previous holder of the phone is
     * skipped (generation_changed) and never reaches the new one.
     */
    public function logicalKey(Destination $destination, string $scope): string
    {
        $ref = $destination->ref();

        return $ref === null
            ? $this->index->index($destination, $scope)
            : self::deviceKey($destination->kind()->value, $ref->id);
    }

    public static function deviceKey(string $kind, string $deviceId): string
    {
        return 'dev:'.$kind.':'.$deviceId;
    }

    /**
     * The key rows written before W5-P carry for the same registry destination (`dev:<kind>:<id>:<generation>`), or
     * null for a destination without a registry reference. Writers check it so an intent replayed across the
     * upgrade does not add a second row next to an old one.
     */
    public static function legacyDeviceKey(Destination $destination): ?string
    {
        $ref = $destination->ref();

        return $ref === null ? null : 'dev:'.$destination->kind()->value.':'.$ref->id.':'.$ref->generation;
    }

    /** @return array<string, mixed> */
    public function effectRow(EffectIntent $intent, string $id, int $nowMs): array
    {
        $scope = self::scope($intent->binding->scope);
        $now = LedgerTime::date($nowMs);
        $dedupe = $intent->dedupeSeconds ?? $this->settings->dedupeSeconds;
        $key = $intent->effectKey ?? $id;

        return [
            'id' => $id,
            'scope' => $scope,
            'binding' => json_encode($intent->binding->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'binding_identity' => $intent->binding->identity(),
            'effect_key' => $key,
            'dedupe_key' => self::dedupeKey($key),
            'fingerprint' => PayloadFingerprint::keyed($intent->message, $this->cipher, $scope),
            'message' => $this->vault->seal($intent->message, $scope, $id),
            'operation' => $intent->operation->value,
            'replaceable' => $intent->isReplaceable(),
            'expires_at' => LedgerTime::msOrNull($intent->resolvedExpiry($now)),
            'policy' => $intent->resolvedPolicy(),
            'traffic_class' => $intent->trafficClass->value,
            'origin_type' => $intent->origin?->notifiableType,
            'origin_id' => $intent->origin?->notifiableId,
            'notification_class' => $intent->origin?->notificationClass,
            'campaign_id' => $intent->campaignId,
            'dedupe_until' => $nowMs + $dedupe * 1000,
            'created_at' => $nowMs,
            'updated_at' => $nowMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $effect  the effects row the delivery belongs to
     * @return array<string, mixed>
     */
    public function deliveryRow(array $effect, Destination $destination, string $logicalKey, int $nowMs): array
    {
        $scope = Row::str($effect, 'scope');
        $ref = $destination->ref();

        return [
            'id' => Ulid::generate(LedgerTime::date($nowMs)),
            'effect_id' => Row::str($effect, 'id'),
            'scope' => $scope,
            'kind' => $destination->kind()->value,
            'logical_key' => $logicalKey,
            // Registry tokens are never copied: the registry stays the only place that holds them.
            'destination' => $ref === null
                ? $this->cipher->encrypt($destination->value(), $scope, self::destinationContext($destination->kind()->value, $logicalKey))
                : null,
            'device_id' => $ref?->id,
            'device_generation' => $ref?->generation,
            'owner_type' => $ref !== null ? $ref->ownerType : Row::strOrNull($effect, 'origin_type'),
            'owner_id' => $ref !== null ? $ref->ownerId : Row::strOrNull($effect, 'origin_id'),
            'state' => DeliveryState::Pending->value,
            'attempts' => 0,
            'budget' => null,
            'unknown_seen' => false,
            'next_attempt_at' => $nowMs,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_started_at' => null,
            'version' => 0,
            'cancel_requested' => false,
            'replaceable' => Row::bool($effect, 'replaceable'),
            'expires_at' => Row::intOrNull($effect, 'expires_at'),
            'campaign_id' => Row::strOrNull($effect, 'campaign_id'),
            'provider_message_id' => null,
            // Assigned once, here: stable across every attempt (the id the request and the receipt marker carry).
            'message_id' => MessageId::generate(),
            'reason_code' => null,
            'raw_reason' => null,
            'hint' => null,
            'http_status' => null,
            'destination_action' => 'none',
            'invalidated_at_ms' => null,
            'accepted_at' => null,
            'client_received_at' => null,
            'client_opened_at' => null,
            'created_at' => $nowMs,
            'updated_at' => $nowMs,
        ];
    }

    public static function destinationContext(string $kind, string $logicalKey): string
    {
        return 'ledger-destination|'.$kind.'|'.$logicalKey;
    }

    /** The typed destination to send to, or null for registry rows / erased rows. */
    public function destinationOf(Delivery $delivery, ?string $sealed): ?Destination
    {
        if ($sealed === null) {
            return null;
        }

        $value = $this->cipher->decrypt($sealed, $delivery->scope, self::destinationContext($delivery->kind->value, $delivery->logicalKey));

        return DestinationFactory::make($delivery->kind, $value, $delivery->deviceRef);
    }

    public function vault(): MessageVault
    {
        return $this->vault;
    }

    /** @return array<string, mixed> */
    public static function claimChanges(string $owner, int $version, int $nowMs, int $leaseSeconds): array
    {
        return [
            'state' => DeliveryState::Leased->value,
            'lease_owner' => $owner,
            'lease_expires_at' => $nowMs + $leaseSeconds * 1000,
            'attempt_started_at' => null,
            'version' => $version + 1,
            'updated_at' => $nowMs,
        ];
    }

    /**
     * Changes for recording `$result` on `$row`, or null when the state machine ignores it.
     *
     * @param  array<string, mixed>  $row
     * @param  (\Closure(string): bool)|null  $markerIssued  receipts: was a marker issued for this sender message id
     * @return array<string, mixed>|null
     */
    public static function recordChanges(array $row, SendResult $result, ?RetryBudget $budget, int $nowMs, ?\Closure $markerIssued = null): ?array
    {
        $unknownSeen = $budget !== null ? $budget->unknownOutcomeSeen : Row::bool($row, 'unknown_seen');
        $transition = DeliveryStateMachine::apply(
            DeliveryState::from(Row::str($row, 'state')),
            DeliveryEvent::Record,
            $result->status,
            Row::bool($row, 'cancel_requested'),
            $unknownSeen,
        );
        if (! $transition->allowed) {
            return null;
        }

        $to = $transition->to;
        $reason = $result->reason;
        $candidate = $result->meta['message_id'] ?? null;
        $senderId = is_string($candidate) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $candidate) === 1 ? $candidate : null;
        if ($to === DeliveryState::Cancelled) {
            $reason = Reason::of(ReasonCode::Cancelled, null, 'Cancelled while an attempt was planned.');
        }

        return [
            'state' => $to->value,
            'attempts' => max($budget !== null ? $budget->attempts : 0, $result->attempts, Row::int($row, 'attempts')),
            'budget' => $budget !== null ? self::encodeBudget($budget) : ($row['budget'] ?? null),
            'unknown_seen' => $unknownSeen,
            'next_attempt_at' => $to === DeliveryState::Deferred ? LedgerTime::msOrNull($result->nextAttemptAt) ?? $nowMs : null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_started_at' => null,
            'version' => Row::int($row, 'version') + 1,
            'provider_message_id' => $result->providerMessageId ?? ($row['provider_message_id'] ?? null),
            // Receipts: the message id is assigned at insert and never replaced (legacy rows without one take the sender's);
            // `receipt_marker` says whether a marker was embedded.
            'message_id' => Row::strOrNull($row, 'message_id') ?? $senderId,
            'receipt_marker' => Row::bool($row, 'receipt_marker')
                || ($result->meta['receipt_marker'] ?? null) === '1'
                || ($senderId !== null && $markerIssued !== null && $markerIssued($senderId)),
            'reason_code' => $reason->code === ReasonCode::None ? null : $reason->code->value,
            'raw_reason' => $reason->rawReason,
            'hint' => $reason->hint,
            'http_status' => $result->httpStatus,
            'destination_action' => $result->destinationAction->value,
            'invalidated_at_ms' => $result->invalidatedAtMs,
            'accepted_at' => $to === DeliveryState::Accepted ? $nowMs : null,
            'updated_at' => $nowMs,
        ];
    }

    /**
     * Changes for an expired lease, or null when the row is not in flight.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: DeliveryEvent, 1: array<string, mixed>}|null
     */
    public function leaseLostChanges(array $row, int $nowMs): ?array
    {
        $state = DeliveryState::from(Row::str($row, 'state'));
        $base = [
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_started_at' => null,
            'version' => Row::int($row, 'version') + 1,
            'updated_at' => $nowMs,
        ];

        if ($state === DeliveryState::Leased) {
            // No request left: simply due again, attempts and budget untouched.
            return [DeliveryEvent::LeaseLostBeforeSend, $base + [
                'state' => DeliveryState::Pending->value,
                'next_attempt_at' => min(Row::intOrNull($row, 'next_attempt_at') ?? $nowMs, $nowMs),
            ]];
        }

        if ($state !== DeliveryState::Sending) {
            return null;
        }

        $policy = $this->settings->retry;
        $budget = self::budgetOf($row, $policy)->withAttempt()->withUnknownOutcome();
        $now = LedgerTime::date($nowMs);
        $retry = $policy->unknownOutcome->allows(Row::bool($row, 'replaceable'))
            && $budget->attempts < $policy->maxAttempts
            && $now < $budget->deadline();

        $event = $retry ? DeliveryEvent::LeaseLostRetry : DeliveryEvent::LeaseLostFinal;
        $to = DeliveryStateMachine::apply($state, $event, null, Row::bool($row, 'cancel_requested'), true)->to;

        return [$event, $base + [
            'state' => $to->value,
            'attempts' => $budget->attempts,
            'budget' => self::encodeBudget($budget),
            'unknown_seen' => true,
            'next_attempt_at' => $to === DeliveryState::Deferred ? $nowMs : null,
            'reason_code' => ReasonCode::TransportUnknown->value,
            'raw_reason' => null,
            'hint' => self::LOST_LEASE_HINT,
            'http_status' => null,
        ]];
    }

    /**
     * Changes for a waiting row whose effect expired.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function expireChanges(array $row, int $nowMs): ?array
    {
        $transition = DeliveryStateMachine::apply(DeliveryState::from(Row::str($row, 'state')), DeliveryEvent::Expire, unknownSeen: Row::bool($row, 'unknown_seen'));
        if (! $transition->allowed) {
            return null;
        }

        return [
            'state' => $transition->to->value,
            'next_attempt_at' => null,
            'version' => Row::int($row, 'version') + 1,
            'reason_code' => $transition->to === DeliveryState::Expired ? ReasonCode::Expired->value : ReasonCode::TransportUnknown->value,
            'hint' => $transition->to === DeliveryState::Expired ? 'The logical expiry passed before an attempt.' : self::LOST_LEASE_HINT,
            'updated_at' => $nowMs,
        ];
    }

    /**
     * Changes for a cancel, or null when the row is already final.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function cancelChanges(array $row, int $nowMs, string $hint = 'Cancelled.'): ?array
    {
        $transition = DeliveryStateMachine::apply(DeliveryState::from(Row::str($row, 'state')), DeliveryEvent::Cancel, unknownSeen: Row::bool($row, 'unknown_seen'));
        if (! $transition->allowed) {
            return null;
        }

        if ($transition->requestsCancel) {
            // No version bump: the worker's lease stays valid, so the real outcome of its request is still recorded
            // (a Deferred one then becomes Cancelled). Writers guard on cancel_requested to not lose this flag.
            return ['cancel_requested' => true, 'updated_at' => $nowMs];
        }

        $cancelled = $transition->to === DeliveryState::Cancelled;

        return [
            'state' => $transition->to->value,
            'cancel_requested' => true,
            'next_attempt_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_started_at' => null,
            'version' => Row::int($row, 'version') + 1,
            'reason_code' => $cancelled ? ReasonCode::Cancelled->value : ReasonCode::TransportUnknown->value,
            'hint' => $cancelled ? $hint : self::LOST_LEASE_HINT,
            'updated_at' => $nowMs,
        ];
    }

    /**
     * A claimed raw destination that no longer decrypts (key removed, row tampered with): it cannot be sent, so it
     * ends as Skipped before any request instead of being handed to a worker.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function unreadableChanges(array $row, int $nowMs): array
    {
        return [
            'state' => DeliveryState::Skipped->value,
            'next_attempt_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'version' => Row::int($row, 'version') + 1,
            'reason_code' => ReasonCode::Unknown->value,
            'raw_reason' => 'destination_unreadable',
            'hint' => 'The stored destination could not be decrypted (encryption key removed or value altered).',
            'updated_at' => $nowMs,
        ];
    }

    /** @return array<string, mixed> personal data of a delivery row, erased */
    public static function forgetDeliveryChanges(int $nowMs): array
    {
        return ['destination' => null, 'owner_type' => null, 'owner_id' => null, 'device_id' => null, 'device_generation' => null, 'updated_at' => $nowMs];
    }

    /** @return array<string, mixed> personal data of an effect row, erased */
    public static function forgetEffectChanges(int $nowMs): array
    {
        return ['message' => null, 'origin_id' => null, 'updated_at' => $nowMs];
    }

    public static function encodeBudget(RetryBudget $budget): string
    {
        return json_encode($budget->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $row */
    private static function budgetOf(array $row, RetryPolicy $policy): RetryBudget
    {
        $stored = Row::json($row, 'budget');
        if ($stored !== null) {
            return RetryBudget::fromArray($stored);
        }

        $started = LedgerTime::date(Row::intOrNull($row, 'attempt_started_at') ?? Row::int($row, 'updated_at'));

        return RetryBudget::start($policy, $started, LedgerTime::dateOrNull(Row::intOrNull($row, 'expires_at')));
    }
}
