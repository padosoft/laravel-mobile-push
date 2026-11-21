<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Psr\Clock\ClockInterface;

/**
 * In-process ledger with the same rules as the database one (tests, single-process tools). Not durable: it is the
 * reference implementation of the contract, not a production store. Rows are kept in the database column layout.
 */
final class InMemoryLedger implements ClientReceiptStore, DeliveryBatches, Ledger, SettlementProbe
{
    /** @var array<string, array<string, mixed>> */
    private array $effects = [];

    /** @var array<string, string> scope|identity|dedupe key => effect id (active keys only) */
    private array $effectKeys = [];

    /** @var array<string, array<string, mixed>> */
    private array $deliveries = [];

    /** @var array<string, string> effect id|logical key => delivery id */
    private array $deliveryKeys = [];

    private readonly LedgerRules $rules;

    public function __construct(
        TokenIndex $index,
        TokenCipher $cipher,
        private readonly ClockInterface $clock,
        LedgerSettings $settings = new LedgerSettings,
    ) {
        $this->rules = new LedgerRules($index, $cipher, new MessageVault($cipher), $settings);
    }

    public function recordIntent(EffectIntent $intent, iterable $destinations): IntentResult
    {
        $now = $this->nowMs();
        $scope = LedgerRules::scope($intent->binding->scope);
        $identity = $intent->binding->identity();
        $created = false;
        $effect = null;
        $previous = null;

        if ($intent->effectKey !== null) {
            $slot = $scope.'|'.$identity.'|'.LedgerRules::dedupeKey($intent->effectKey);
            $existingId = $this->effectKeys[$slot] ?? null;
            if ($existingId !== null && Row::int($this->effects[$existingId], 'dedupe_until') > $now) {
                $effect = $this->effects[$existingId];
                if (! $this->rules->sameMessage(Row::str($effect, 'fingerprint'), $intent->message, $scope)) {
                    throw new IdempotencyConflictException('The effect key was already used with a different message.');
                }
            } elseif ($existingId !== null) {
                $this->effects[$existingId]['dedupe_key'] = null;
                unset($this->effectKeys[$slot]);
                $previous = $existingId;
            }
        }

        if ($effect === null) {
            $effect = $this->rules->effectRow($intent, Ulid::generate($this->clock->now()), $now);
            $this->effects[Row::str($effect, 'id')] = $effect;
            $this->effectKeys[$scope.'|'.$identity.'|'.Row::str($effect, 'dedupe_key')] = Row::str($effect, 'id');
            $created = true;
        }

        $effectId = Row::str($effect, 'id');
        $added = $existing = 0;
        foreach ($destinations as $destination) {
            $key = $this->rules->logicalKey($destination, $scope);
            $legacy = LedgerRules::legacyDeviceKey($destination);
            if (isset($this->deliveryKeys[$effectId.'|'.$key]) || ($legacy !== null && isset($this->deliveryKeys[$effectId.'|'.$legacy]))) {
                $existing++;

                continue;
            }
            $row = $this->rules->deliveryRow($effect, $destination, $key, $now);
            $this->deliveries[Row::str($row, 'id')] = $row;
            $this->deliveryKeys[$effectId.'|'.$key] = Row::str($row, 'id');
            $added++;
        }

        return new IntentResult(Effect::fromRow($effect), $created, $added, $existing, $created ? $previous : null);
    }

    public function effect(string $scope, string $effectId): ?Effect
    {
        $row = $this->effectRow($scope, $effectId);

        return $row === null ? null : Effect::fromRow($row);
    }

    public function message(string $scope, string $effectId): ?Message
    {
        $row = $this->effectRow($scope, $effectId);
        $sealed = $row === null ? null : Row::strOrNull($row, 'message');

        return $sealed === null ? null : $this->rules->vault()->open($sealed, Row::str($row, 'scope'), $effectId);
    }

    public function cancelEffect(string $scope, string $effectId): int
    {
        return $this->cancelWhere($scope, static fn (array $row): bool => $row['effect_id'] === $effectId);
    }

    public function cancelCampaign(string $scope, string $campaignId): int
    {
        return $this->cancelWhere($scope, static fn (array $row): bool => $row['campaign_id'] === $campaignId);
    }

    public function cancelOwner(string $scope, string $ownerType, string $ownerId): int
    {
        return $this->cancelWhere($scope, static fn (array $row): bool => $row['owner_type'] === $ownerType && $row['owner_id'] === $ownerId);
    }

    public function forgetOwner(string $scope, string $ownerType, string $ownerId): int
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        $this->cancelWhere($scope, static fn (array $row): bool => $row['owner_type'] === $ownerType && $row['owner_id'] === $ownerId, 'Forgotten.');

        // The effects addressed to this owner and to nobody else lose their message snapshot too (an effect that also
        // addresses another owner, a raw token or a topic keeps it: the others still need it to be sent).
        $mine = $others = [];
        foreach ($this->deliveries as $row) {
            if ($row['scope'] !== $scope) {
                continue;
            }
            $effectId = Row::str($row, 'effect_id');
            if ($row['owner_type'] === $ownerType && $row['owner_id'] === $ownerId) {
                $mine[$effectId] = true;
            } else {
                $others[$effectId] = true;
            }
        }
        $ownEffects = array_diff_key($mine, $others);

        $erased = 0;
        foreach ($this->deliveries as $id => $row) {
            if ($row['scope'] === $scope && $row['owner_type'] === $ownerType && $row['owner_id'] === $ownerId) {
                $this->deliveries[$id] = array_replace($row, LedgerRules::forgetDeliveryChanges($now));
                $erased++;
            }
        }
        foreach ($this->effects as $id => $row) {
            if ($row['scope'] !== $scope) {
                continue;
            }
            if (($row['origin_type'] === $ownerType && $row['origin_id'] === $ownerId) || isset($ownEffects[$id])) {
                $this->effects[$id] = array_replace($row, LedgerRules::forgetEffectChanges($now));
            }
        }

        return $erased;
    }

    public function prune(int $retentionSeconds, ?string $scope = null): PruneResult
    {
        $scope = $scope === null ? null : LedgerRules::scope($scope);
        $now = $this->nowMs();
        $cutoff = $now - $retentionSeconds * 1000;
        $deleted = 0;

        foreach ($this->deliveries as $id => $row) {
            $effect = $this->effects[Row::str($row, 'effect_id')] ?? null;
            if (($scope === null || $row['scope'] === $scope)
                && DeliveryState::from(Row::str($row, 'state'))->isTerminal()
                && Row::int($row, 'updated_at') < $cutoff
                && ($effect === null || Row::int($effect, 'dedupe_until') <= $now)) {
                unset($this->deliveries[$id], $this->deliveryKeys[Row::str($row, 'effect_id').'|'.Row::str($row, 'logical_key')]);
                $deleted++;
            }
        }

        $used = [];
        foreach ($this->deliveries as $row) {
            $used[Row::str($row, 'effect_id')] = true;
        }
        $effects = 0;
        foreach ($this->effects as $id => $row) {
            if (($scope === null || $row['scope'] === $scope) && ! isset($used[$id]) && Row::int($row, 'dedupe_until') <= $now) {
                unset($this->effects[$id]);
                $this->effectKeys = array_filter($this->effectKeys, static fn (string $effectId): bool => $effectId !== $id);
                $effects++;
            }
        }

        return new PruneResult($deleted, $effects);
    }

    public function claimDue(string $scope, string $owner, int $limit, ?string $effectId = null, ?int $leaseSeconds = null): array
    {
        $scope = LedgerRules::scope($scope);
        if ($limit <= 0) {
            return [];   // nothing asked for: no sweep either (as the database ledger)
        }
        $this->reclaimExpiredLeases($scope, $limit * 4);
        $this->expireDue($scope, $limit * 4);
        $now = $this->nowMs();

        $due = array_filter($this->deliveries, static fn (array $row): bool => $row['scope'] === $scope
            && DeliveryState::from(Row::str($row, 'state'))->isWaiting()
            && Row::int($row, 'next_attempt_at') <= $now
            && (Row::intOrNull($row, 'expires_at') === null || Row::int($row, 'expires_at') > $now)
            && ($effectId === null || $row['effect_id'] === $effectId));
        uasort($due, static fn (array $a, array $b): int => [$a['next_attempt_at'], $a['id']] <=> [$b['next_attempt_at'], $b['id']]);

        $claimed = [];
        foreach (array_slice($due, 0, max($limit, 0), true) as $id => $row) {
            $row = array_replace($row, LedgerRules::claimChanges($owner, Row::int($row, 'version'), $now, $leaseSeconds ?? $this->rules->settings->leaseSeconds));
            $this->deliveries[$id] = $row;
            $delivery = Delivery::fromRow($row);
            try {
                $destination = $this->rules->destinationOf($delivery, Row::strOrNull($row, 'destination'));
            } catch (PrivacyException) {
                $this->deliveries[$id] = array_replace($row, LedgerRules::unreadableChanges($row, $now));

                continue;
            }
            $claimed[] = new ClaimedDelivery(
                new Lease((string) $id, $scope, $owner, $delivery->version, $delivery->leaseExpiresAt ?? LedgerTime::date($now)),
                $delivery,
                $destination,
            );
        }

        return $claimed;
    }

    public function markAttemptStarted(Lease $lease): bool
    {
        $row = $this->deliveryRow($lease->scope, $lease->deliveryId);
        $now = $this->nowMs();
        if ($row === null || ! $this->holds($row, $lease) || Row::int($row, 'lease_expires_at') <= $now
            || ! DeliveryStateMachine::apply(DeliveryState::from(Row::str($row, 'state')), DeliveryEvent::StartAttempt)->allowed) {
            return false;
        }

        $this->deliveries[$lease->deliveryId] = array_replace($row, [
            'state' => DeliveryState::Sending->value,
            'attempt_started_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    public function markAttemptsStarted(array $leases): array
    {
        $started = [];
        foreach ($leases as $lease) {
            if ($this->markAttemptStarted($lease)) {
                $started[] = $lease->deliveryId;
            }
        }

        return $started;
    }

    public function recordMany(array $records): array
    {
        $out = [];
        foreach ($records as [$lease, $result, $budget]) {
            $out[$lease->deliveryId] = $this->record($lease, $result, $budget);
        }

        return $out;
    }

    public function hasOpenDeliveries(string $scope, string $effectId): bool
    {
        return $this->anyOpen($scope, static fn (array $row): bool => $row['effect_id'] === $effectId);
    }

    public function campaignHasOpenDeliveries(string $scope, string $campaignId): bool
    {
        return $this->anyOpen($scope, static fn (array $row): bool => $row['campaign_id'] === $campaignId);
    }

    public function record(Lease $lease, SendResult $result, ?RetryBudget $budget = null): Recording
    {
        $row = $this->deliveryRow($lease->scope, $lease->deliveryId);
        if ($row === null) {
            return Recording::NotFound;
        }
        if (DeliveryState::from(Row::str($row, 'state'))->isTerminal()) {
            return Recording::IgnoredFinal;
        }
        if (! $this->holds($row, $lease)) {
            return Recording::IgnoredStaleLease;
        }

        $changes = LedgerRules::recordChanges($row, $result, $budget, $this->nowMs(), $this->rules->settings->markerIssued);
        if ($changes === null) {
            return Recording::IgnoredStaleLease;
        }
        $this->deliveries[$lease->deliveryId] = array_replace($row, $changes);

        return Recording::Recorded;
    }

    public function reclaimExpiredLeases(string $scope, int $limit = 500): ReclaimReport
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        /** @var array<string, int> $counts */
        $counts = [DeliveryEvent::LeaseLostBeforeSend->value => 0, DeliveryEvent::LeaseLostRetry->value => 0, DeliveryEvent::LeaseLostFinal->value => 0];

        // The leases that expired first are reclaimed first (as the database ledger), not the rows inserted first.
        $expired = array_filter($this->deliveries, static fn (array $row): bool => $row['scope'] === $scope
            && DeliveryState::from(Row::str($row, 'state'))->isInFlight() && Row::int($row, 'lease_expires_at') <= $now);
        uasort($expired, static fn (array $a, array $b): int => [Row::int($a, 'lease_expires_at'), Row::str($a, 'id')] <=> [Row::int($b, 'lease_expires_at'), Row::str($b, 'id')]);

        foreach (array_slice($expired, 0, max(0, $limit), true) as $id => $row) {
            $lost = $this->rules->leaseLostChanges($row, $now);
            if ($lost !== null) {
                $this->deliveries[$id] = array_replace($row, $lost[1]);
                $counts[$lost[0]->value] = ($counts[$lost[0]->value] ?? 0) + 1;
            }
        }

        return new ReclaimReport($counts[DeliveryEvent::LeaseLostBeforeSend->value] ?? 0, $counts[DeliveryEvent::LeaseLostRetry->value] ?? 0, $counts[DeliveryEvent::LeaseLostFinal->value] ?? 0);
    }

    public function expireDue(string $scope, int $limit = 500): int
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        $changed = 0;

        foreach ($this->deliveries as $id => $row) {
            if ($changed >= $limit) {
                break;
            }
            $expires = Row::intOrNull($row, 'expires_at');
            if ($row['scope'] !== $scope || $expires === null || $expires > $now) {
                continue;
            }
            $changes = LedgerRules::expireChanges($row, $now);
            if ($changes !== null) {
                $this->deliveries[$id] = array_replace($row, $changes);
                $changed++;
            }
        }

        return $changed;
    }

    public function delivery(string $scope, string $deliveryId): ?Delivery
    {
        $row = $this->deliveryRow($scope, $deliveryId);

        return $row === null ? null : Delivery::fromRow($row);
    }

    public function counts(string $scope, string $effectId): DeliveryCounts
    {
        return $this->countWhere($scope, static fn (array $row): bool => $row['effect_id'] === $effectId);
    }

    public function campaignCounts(string $scope, string $campaignId): DeliveryCounts
    {
        return $this->countWhere($scope, static fn (array $row): bool => $row['campaign_id'] === $campaignId);
    }

    public function results(string $scope, string $effectId): \Generator
    {
        $scope = LedgerRules::scope($scope);
        $rows = array_filter($this->deliveries, static fn (array $row): bool => $row['scope'] === $scope && $row['effect_id'] === $effectId);
        ksort($rows, SORT_STRING);

        foreach ($rows as $row) {
            $delivery = Delivery::fromRow($row);
            try {
                $destination = $this->rules->destinationOf($delivery, Row::strOrNull($row, 'destination'));
            } catch (PrivacyException) {
                $destination = null;
            }
            yield $delivery->toSendResult($destination);
        }
    }

    public function health(?string $scope = null): LedgerHealth
    {
        $scope = $scope === null ? null : LedgerRules::scope($scope);
        $now = $this->nowMs();
        $due = $pending = $deferred = $inFlight = $unknown = 0;
        $oldest = null;

        foreach ($this->deliveries as $row) {
            if ($scope !== null && $row['scope'] !== $scope) {
                continue;
            }
            $state = DeliveryState::from(Row::str($row, 'state'));
            $pending += (int) ($state === DeliveryState::Pending);
            $deferred += (int) ($state === DeliveryState::Deferred);
            $inFlight += (int) $state->isInFlight();
            $unknown += (int) ($state === DeliveryState::OutcomeUnknown);
            if ($state->isWaiting() && Row::int($row, 'next_attempt_at') <= $now) {
                $due++;
                $oldest = min($oldest ?? PHP_INT_MAX, Row::int($row, 'next_attempt_at'));
            }
        }

        return new LedgerHealth($due, $pending, $deferred, $inFlight, $unknown, $oldest === null ? null : $now - $oldest);
    }

    public function scopesWithWork(int $limit = 100): array
    {
        $now = $this->nowMs();
        $scopes = [];
        foreach ($this->deliveries as $row) {
            $state = DeliveryState::from(Row::str($row, 'state'));
            if (($state->isWaiting() && Row::int($row, 'next_attempt_at') <= $now) || ($state->isInFlight() && Row::int($row, 'lease_expires_at') <= $now)) {
                $scopes[Row::str($row, 'scope')] = true;
            }
        }
        // PHP turns a numeric scope name used as an array key into an integer: back to strings before sorting, so
        // that the order is the database's (byte order: "10" before "9").
        $scopes = array_map('strval', array_keys($scopes));
        sort($scopes, SORT_STRING);

        return array_slice($scopes, 0, $limit);
    }

    public function deliveryByMessageId(string $scope, string $messageId): ?Delivery
    {
        $scope = LedgerRules::scope($scope);
        $best = null;
        foreach ($this->deliveries as $row) {
            if ($row['scope'] === $scope && ($row['message_id'] ?? null) === $messageId
                && ($best === null || [Row::int($row, 'created_at'), Row::str($row, 'id')] > [Row::int($best, 'created_at'), Row::str($best, 'id')])) {
                $best = $row;
            }
        }

        return $best === null ? null : Delivery::fromRow($best);
    }

    public function recordClientEvent(string $scope, string $deliveryId, ClientEvent $event, \DateTimeImmutable $at): ClientEventRecording
    {
        $row = $this->deliveryRow($scope, $deliveryId);
        if ($row === null) {
            return ClientEventRecording::NotFound;
        }
        $state = DeliveryState::from(Row::str($row, 'state'));
        if ($state !== DeliveryState::Accepted && $state !== DeliveryState::OutcomeUnknown) {
            return ClientEventRecording::NotEligible;
        }
        if (Row::intOrNull($row, $event->column()) !== null) {
            return ClientEventRecording::Duplicate;
        }
        // Neither the state, the version nor updated_at change: a report never moves the delivery's own timeline.
        $this->deliveries[$deliveryId][$event->column()] = LedgerTime::ms($at);
        $this->deliveries[$deliveryId]['receipt_marker'] = true;

        return ClientEventRecording::Recorded;
    }

    public function effectFunnel(string $scope, string $effectId): ReceiptFunnel
    {
        $scope = LedgerRules::scope($scope);

        return ReceiptFunnel::fromRows(array_filter($this->deliveries, static fn (array $row): bool => $row['scope'] === $scope && $row['effect_id'] === $effectId));
    }

    public function campaignFunnel(string $scope, string $campaignId): ReceiptFunnel
    {
        $scope = LedgerRules::scope($scope);

        return ReceiptFunnel::fromRows(array_filter($this->deliveries, static fn (array $row): bool => $row['scope'] === $scope && $row['campaign_id'] === $campaignId));
    }

    /**
     * Test helper: the raw stored rows (column layout of the database tables).
     *
     * @return array{effects: list<array<string, mixed>>, deliveries: list<array<string, mixed>>}
     */
    public function dumpRows(): array
    {
        return ['effects' => array_values($this->effects), 'deliveries' => array_values($this->deliveries)];
    }

    /** @param \Closure(array<string, mixed>): bool $match */
    private function cancelWhere(string $scope, \Closure $match, string $hint = 'Cancelled.'): int
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        $changed = 0;
        foreach ($this->deliveries as $id => $row) {
            if ($row['scope'] !== $scope || ! $match($row)) {
                continue;
            }
            $changes = LedgerRules::cancelChanges($row, $now, $hint);
            if ($changes !== null && ! isset($changes['state']) && Row::bool($row, 'cancel_requested')) {
                continue;   // an in-flight row that already carries the request: nothing changes, nothing is counted
            }
            if ($changes !== null) {
                $this->deliveries[$id] = array_replace($row, $changes);
                $changed++;
            }
        }

        return $changed;
    }

    /** @param \Closure(array<string, mixed>): bool $match */
    private function anyOpen(string $scope, \Closure $match): bool
    {
        $scope = LedgerRules::scope($scope);
        foreach ($this->deliveries as $row) {
            $state = DeliveryState::from(Row::str($row, 'state'));
            if ($row['scope'] === $scope && ($state->isWaiting() || $state->isInFlight()) && $match($row)) {
                return true;
            }
        }

        return false;
    }

    /** @param \Closure(array<string, mixed>): bool $match */
    private function countWhere(string $scope, \Closure $match): DeliveryCounts
    {
        $scope = LedgerRules::scope($scope);
        $counts = [];
        foreach ($this->deliveries as $row) {
            if ($row['scope'] === $scope && $match($row)) {
                $state = Row::str($row, 'state');
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        }

        return new DeliveryCounts($counts);
    }

    /** @return array<string, mixed>|null */
    private function effectRow(string $scope, string $effectId): ?array
    {
        $row = $this->effects[$effectId] ?? null;

        return $row !== null && $row['scope'] === LedgerRules::scope($scope) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function deliveryRow(string $scope, string $deliveryId): ?array
    {
        $row = $this->deliveries[$deliveryId] ?? null;

        return $row !== null && $row['scope'] === LedgerRules::scope($scope) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function holds(array $row, Lease $lease): bool
    {
        return $row['lease_owner'] === $lease->owner && Row::int($row, 'version') === $lease->version;
    }

    private function nowMs(): int
    {
        return LedgerTime::ms($this->clock->now());
    }
}
