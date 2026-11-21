<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Ledger;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Ledger\ClientEvent;
use Padosoft\MobilePush\Core\Ledger\ClientEventRecording;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\Delivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\DeliveryEvent;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\DeliveryStateMachine;
use Padosoft\MobilePush\Core\Ledger\Effect;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\IntentResult;
use Padosoft\MobilePush\Core\Ledger\Lease;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\LedgerHealth;
use Padosoft\MobilePush\Core\Ledger\LedgerRules;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Core\Ledger\MessageVault;
use Padosoft\MobilePush\Core\Ledger\PruneResult;
use Padosoft\MobilePush\Core\Ledger\ReceiptFunnel;
use Padosoft\MobilePush\Core\Ledger\ReclaimReport;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Ledger\Row;
use Padosoft\MobilePush\Core\Ledger\Ulid;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Psr\Clock\ClockInterface;

/**
 * The durable ledger on the query builder (SQLite, MySQL, PostgreSQL).
 *
 * Concurrency model: every change a worker makes is an UPDATE guarded by the row's `version` (and, for worker
 * writes, the lease owner), computed from the shared LedgerRules. A write that lost a race updates 0 rows and is
 * reported as ignored. Bulk operations (cancel, forget) are set-based UPDATEs whose values come from the same
 * rules, one statement per source state. No method keeps a transaction open after it returns; claimDue() uses
 * one short transaction only for the SKIP LOCKED strategy.
 */
final class DatabaseLedger implements ClientReceiptStore, Ledger
{
    /** Bound parameters per INSERT statement stay below every driver's limit (SQLite 32766, PostgreSQL 65535). */
    private const MAX_BINDINGS = 30_000;

    private readonly LedgerRules $rules;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        TokenIndex $index,
        TokenCipher $cipher,
        private readonly ClockInterface $clock,
        LedgerSettings $settings = new LedgerSettings,
        private readonly ?string $connectionName = null,
        private readonly string $effectsTable = 'mobile_push_effects',
        private readonly string $deliveriesTable = 'mobile_push_deliveries',
        private readonly ClaimStrategy $strategy = ClaimStrategy::Auto,
    ) {
        $this->rules = new LedgerRules($index, $cipher, new MessageVault($cipher), $settings);
    }

    public function connection(): Connection
    {
        $connection = $this->db->connection($this->connectionName);
        if (! $connection instanceof Connection) {
            throw new \LogicException('The ledger needs an Illuminate\Database\Connection.');
        }

        return $connection;
    }

    public function claimStrategy(): ClaimStrategy
    {
        return $this->strategy->resolve($this->connection());
    }

    // ---------------------------------------------------------------- intent (outbox)

    public function recordIntent(EffectIntent $intent, iterable $destinations): IntentResult
    {
        // transaction() joins the caller's open transaction on this connection as a savepoint: the intent then
        // commits or rolls back with the business data. Standalone, the whole intent is atomic.
        return $this->connection()->transaction(fn (): IntentResult => $this->writeIntent($intent, $destinations));
    }

    /** @param iterable<Destination> $destinations */
    private function writeIntent(EffectIntent $intent, iterable $destinations): IntentResult
    {
        $now = $this->nowMs();
        $scope = LedgerRules::scope($intent->binding->scope);
        $effect = $intent->effectKey === null ? null : $this->activeEffect($intent, $scope, $now);
        $created = false;

        if ($effect === null) {
            $row = $this->rules->effectRow($intent, Ulid::generate($this->clock->now()), $now);
            if ($this->effects()->insertOrIgnore($row) === 1) {
                $effect = $row;
                $created = true;
            } else {
                // A concurrent call with the same key won the unique index: behave as the replay we are.
                $effect = $this->activeEffect($intent, $scope, $now)
                    ?? throw new \RuntimeException('The effect could not be recorded and no concurrent effect was found.');
            }
        }

        [$added, $existing] = $this->insertDeliveries($effect, $destinations, $now);

        return new IntentResult(Effect::fromRow($effect), $created, $added, $existing);
    }

    /**
     * The effect currently owning (scope, binding identity, key), or null. An effect past its dedupe window releases
     * its key (dedupe_key = NULL; NULLs never collide in a unique index) so a new effect can take it.
     *
     * @return array<string, mixed>|null
     */
    private function activeEffect(EffectIntent $intent, string $scope, int $now): ?array
    {
        $key = LedgerRules::dedupeKey($intent->effectKey ?? '');
        $found = $this->effects()
            ->where('scope', $scope)
            ->where('binding_identity', $intent->binding->identity())
            ->where('dedupe_key', $key)
            ->first();
        if ($found === null) {
            return null;
        }
        $found = Row::of($found);

        if (Row::int($found, 'dedupe_until') <= $now) {
            $this->effects()->where('id', Row::str($found, 'id'))->update(['dedupe_key' => null, 'updated_at' => $now]);

            return null;
        }

        if (! $this->rules->sameMessage(Row::str($found, 'fingerprint'), $intent->message, $scope)) {
            throw new IdempotencyConflictException('The effect key was already used with a different message.');
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  iterable<Destination>  $destinations
     * @return array{0: int, 1: int}
     */
    private function insertDeliveries(array $effect, iterable $destinations, int $now): array
    {
        $scope = Row::str($effect, 'scope');
        $chunkSize = $this->rules->settings->chunkSize;
        $added = $seen = 0;
        $chunk = [];

        foreach ($destinations as $destination) {
            $seen++;
            $key = $this->rules->logicalKey($destination, $scope);
            if (isset($chunk[$key])) {
                continue;
            }
            $chunk[$key] = $this->rules->deliveryRow($effect, $destination, $key, $now);
            if (count($chunk) >= $chunkSize) {
                $added += $this->flushDeliveries($chunk);
                $chunk = [];
            }
        }
        $added += $this->flushDeliveries($chunk);

        // Rows the unique index refused were already there (earlier call, or an earlier chunk of this one).
        return [$added, $seen - $added];
    }

    /**
     * Insert one chunk, ignoring rows that already exist. Returns the rows actually written.
     *
     * @param  array<string, array<string, mixed>>  $chunk
     */
    private function flushDeliveries(array $chunk): int
    {
        if ($chunk === []) {
            return 0;
        }
        $rows = array_values($chunk);
        $inserted = 0;
        foreach (array_chunk($rows, max(1, intdiv(self::MAX_BINDINGS, count($rows[0])))) as $batch) {
            $inserted += $this->deliveries()->insertOrIgnore($batch);
        }

        return $inserted;
    }

    // ---------------------------------------------------------------- effects

    public function effect(string $scope, string $effectId): ?Effect
    {
        $row = $this->effects()->where('scope', LedgerRules::scope($scope))->where('id', $effectId)->first();

        return $row === null ? null : Effect::fromRow($row);
    }

    public function message(string $scope, string $effectId): ?Message
    {
        $scope = LedgerRules::scope($scope);
        $sealed = $this->effects()->where('scope', $scope)->where('id', $effectId)->value('message');

        return is_string($sealed) ? $this->rules->vault()->open($sealed, $scope, $effectId) : null;
    }

    public function cancelEffect(string $scope, string $effectId): int
    {
        return $this->cancelWhere($scope, static fn (Builder $q): Builder => $q->where('effect_id', $effectId));
    }

    public function cancelCampaign(string $scope, string $campaignId): int
    {
        return $this->cancelWhere($scope, static fn (Builder $q): Builder => $q->where('campaign_id', $campaignId));
    }

    public function cancelOwner(string $scope, string $ownerType, string $ownerId): int
    {
        return $this->cancelWhere($scope, static fn (Builder $q): Builder => $q->where('owner_type', $ownerType)->where('owner_id', $ownerId));
    }

    public function forgetOwner(string $scope, string $ownerType, string $ownerId): int
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();

        return $this->connection()->transaction(function () use ($scope, $ownerType, $ownerId, $now): int {
            $this->cancelWhere($scope, static fn (Builder $q): Builder => $q->where('owner_type', $ownerType)->where('owner_id', $ownerId), 'Forgotten.');

            $erased = $this->deliveries()->where('scope', $scope)->where('owner_type', $ownerType)->where('owner_id', $ownerId)
                ->update(LedgerRules::forgetDeliveryChanges($now));
            $this->effects()->where('scope', $scope)->where('origin_type', $ownerType)->where('origin_id', $ownerId)
                ->update(LedgerRules::forgetEffectChanges($now));

            return $erased;
        });
    }

    public function prune(int $retentionSeconds, ?string $scope = null): PruneResult
    {
        $scope = $scope === null ? null : LedgerRules::scope($scope);
        $now = $this->nowMs();
        $cutoff = $now - $retentionSeconds * 1000;
        $chunk = $this->rules->settings->chunkSize;
        $terminal = array_values(array_filter(DeliveryState::cases(), static fn (DeliveryState $s): bool => $s->isTerminal()));
        $deliveries = $effects = 0;

        do {
            $ids = $this->deliveries()
                ->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', $scope))
                ->whereIn('state', DeliveryState::values(...$terminal))
                ->where('updated_at', '<', $cutoff)
                ->whereIn('effect_id', fn (Builder $q): Builder => $q->select('id')->from($this->effectsTable)->where('dedupe_until', '<=', $now))
                ->limit($chunk)
                ->pluck('id')->all();
            $deliveries += $ids === [] ? 0 : $this->deliveries()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        do {
            $ids = $this->effects()
                ->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', $scope))
                ->where('dedupe_until', '<=', $now)
                ->whereNotExists(fn (Builder $q): Builder => $q->selectRaw('1')->from($this->deliveriesTable)
                    ->whereColumn($this->deliveriesTable.'.effect_id', $this->effectsTable.'.id'))
                ->limit($chunk)
                ->pluck('id')->all();
            $effects += $ids === [] ? 0 : $this->effects()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return new PruneResult($deliveries, $effects);
    }

    // ---------------------------------------------------------------- deliveries

    public function claimDue(string $scope, string $owner, int $limit, ?string $effectId = null, ?int $leaseSeconds = null): array
    {
        $scope = LedgerRules::scope($scope);
        if ($limit < 1) {
            return [];
        }
        $this->reclaimExpiredLeases($scope, $limit * 4);
        $this->expireDue($scope, $limit * 4);

        $now = $this->nowMs();
        $lease = $leaseSeconds ?? $this->rules->settings->leaseSeconds;
        $ids = $this->claimStrategy() === ClaimStrategy::SkipLocked
            ? $this->claimSkipLocked($scope, $owner, $limit, $effectId, $now, $lease)
            : $this->claimOptimistic($scope, $owner, $limit, $effectId, $now, $lease);

        if ($ids === []) {
            return [];
        }

        $claimed = [];
        $rows = $this->deliveries()->whereIn('id', $ids)->where('scope', $scope)->where('lease_owner', $owner)
            ->where('state', DeliveryState::Leased->value)->orderBy('next_attempt_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $row = Row::of($row);
            $delivery = Delivery::fromRow($row);
            try {
                $destination = $this->rules->destinationOf($delivery, Row::strOrNull($row, 'destination'));
            } catch (PrivacyException) {
                $this->deliveries()->where('id', $delivery->id)->where('version', $delivery->version)
                    ->update(LedgerRules::unreadableChanges($row, $now));

                continue;
            }
            $claimed[] = new ClaimedDelivery(
                new Lease($delivery->id, $scope, $owner, $delivery->version, $delivery->leaseExpiresAt ?? LedgerTime::date($now)),
                $delivery,
                $destination,
            );
        }

        return $claimed;
    }

    private function dueQuery(string $scope, ?string $effectId, int $now): Builder
    {
        return $this->deliveries()
            ->where('scope', $scope)
            ->whereIn('state', DeliveryState::values(...DeliveryStateMachine::sourcesOf(DeliveryEvent::Claim)))
            ->where('next_attempt_at', '<=', $now)
            ->where(static fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->when($effectId !== null, static fn (Builder $q): Builder => $q->where('effect_id', $effectId))
            ->orderBy('next_attempt_at')
            ->orderBy('id');
    }

    /** @return list<string> */
    private function claimSkipLocked(string $scope, string $owner, int $limit, ?string $effectId, int $now, int $lease): array
    {
        return $this->connection()->transaction(function () use ($scope, $owner, $limit, $effectId, $now, $lease): array {
            $ids = self::strings($this->dueQuery($scope, $effectId, $now)
                ->limit($limit)->lock('for update skip locked')->pluck('id')->all());
            if ($ids === []) {
                return [];
            }

            $changes = LedgerRules::claimChanges($owner, 0, $now, $lease);
            $changes['version'] = new Expression('version + 1');
            $this->deliveries()->whereIn('id', $ids)
                ->whereIn('state', DeliveryState::values(...DeliveryStateMachine::sourcesOf(DeliveryEvent::Claim)))
                ->update($changes);

            return $ids;
        });
    }

    /** @return list<string> */
    private function claimOptimistic(string $scope, string $owner, int $limit, ?string $effectId, int $now, int $lease): array
    {
        $claimed = [];
        for ($round = 0; $round < 3 && count($claimed) < $limit; $round++) {
            $candidates = $this->dueQuery($scope, $effectId, $now)->limit($limit - count($claimed))->get(['id', 'version']);
            if ($candidates->isEmpty()) {
                break;
            }
            foreach ($candidates as $candidate) {
                $candidate = Row::of($candidate);
                $version = Row::int($candidate, 'version');
                $won = $this->deliveries()
                    ->where('id', Row::str($candidate, 'id'))
                    ->where('version', $version)
                    ->whereIn('state', DeliveryState::values(...DeliveryStateMachine::sourcesOf(DeliveryEvent::Claim)))
                    ->update(LedgerRules::claimChanges($owner, $version, $now, $lease));
                if ($won === 1) {
                    $claimed[] = Row::str($candidate, 'id');
                }
            }
        }

        return $claimed;
    }

    public function markAttemptStarted(Lease $lease): bool
    {
        $now = $this->nowMs();

        return $this->deliveries()
            ->where('id', $lease->deliveryId)
            ->where('scope', LedgerRules::scope($lease->scope))
            ->where('lease_owner', $lease->owner)
            ->where('version', $lease->version)
            ->where('lease_expires_at', '>', $now)
            ->whereIn('state', DeliveryState::values(...DeliveryStateMachine::sourcesOf(DeliveryEvent::StartAttempt)))
            ->update(['state' => DeliveryState::Sending->value, 'attempt_started_at' => $now, 'updated_at' => $now]) === 1;
    }

    public function record(Lease $lease, SendResult $result, ?RetryBudget $budget = null): Recording
    {
        $scope = LedgerRules::scope($lease->scope);
        $now = $this->nowMs();

        // A cancel may flip cancel_requested on an in-flight row without moving its version (so this lease stays
        // valid). The UPDATE is therefore also guarded on the flag as read; if it changed, read again and redo.
        for ($try = 0; $try < 3; $try++) {
            $row = $this->deliveries()->where('id', $lease->deliveryId)->where('scope', $scope)->first();
            if ($row === null) {
                return Recording::NotFound;
            }
            $row = Row::of($row);
            $verdict = self::verdict($row, $lease);
            if ($verdict !== null) {
                return $verdict;
            }

            $changes = LedgerRules::recordChanges($row, $result, $budget, $now, $this->rules->settings->markerIssued);
            if ($changes === null) {
                return Recording::IgnoredStaleLease;
            }

            $updated = $this->deliveries()
                ->where('id', $lease->deliveryId)
                ->where('scope', $scope)
                ->where('lease_owner', $lease->owner)
                ->where('version', $lease->version)
                ->where('cancel_requested', Row::bool($row, 'cancel_requested'))
                ->update($changes);
            if ($updated === 1) {
                return Recording::Recorded;
            }
        }

        return Recording::IgnoredStaleLease;
    }

    /** @param array<string, mixed> $row */
    private static function verdict(array $row, Lease $lease): ?Recording
    {
        if (DeliveryState::from(Row::str($row, 'state'))->isTerminal()) {
            return Recording::IgnoredFinal;
        }
        if (Row::strOrNull($row, 'lease_owner') !== $lease->owner || Row::int($row, 'version') !== $lease->version) {
            return Recording::IgnoredStaleLease;
        }

        return null;
    }

    public function reclaimExpiredLeases(string $scope, int $limit = 500): ReclaimReport
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        $counts = [];

        $rows = $this->deliveries()
            ->where('scope', $scope)
            ->whereIn('state', [DeliveryState::Leased->value, DeliveryState::Sending->value])
            ->where('lease_expires_at', '<=', $now)
            ->orderBy('lease_expires_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $row = Row::of($row);
            $lost = $this->rules->leaseLostChanges($row, $now);
            if ($lost === null) {
                continue;
            }
            $updated = $this->deliveries()->where('id', Row::str($row, 'id'))->where('version', Row::int($row, 'version'))
                ->where('cancel_requested', Row::bool($row, 'cancel_requested'))->update($lost[1]);
            if ($updated === 1) {
                $counts[$lost[0]->value] = ($counts[$lost[0]->value] ?? 0) + 1;
            }
        }

        return new ReclaimReport(
            $counts[DeliveryEvent::LeaseLostBeforeSend->value] ?? 0,
            $counts[DeliveryEvent::LeaseLostRetry->value] ?? 0,
            $counts[DeliveryEvent::LeaseLostFinal->value] ?? 0,
        );
    }

    public function expireDue(string $scope, int $limit = 500): int
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        $changed = 0;

        $rows = $this->deliveries()
            ->where('scope', $scope)
            ->whereIn('state', DeliveryState::values(...DeliveryStateMachine::sourcesOf(DeliveryEvent::Expire)))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->limit($limit)
            ->get(['id', 'state', 'version', 'unknown_seen']);

        foreach ($rows as $row) {
            $row = Row::of($row);
            $changes = LedgerRules::expireChanges($row, $now);
            if ($changes !== null) {
                $changed += $this->deliveries()->where('id', Row::str($row, 'id'))->where('version', Row::int($row, 'version'))->update($changes);
            }
        }

        return $changed;
    }

    public function delivery(string $scope, string $deliveryId): ?Delivery
    {
        $row = $this->deliveries()->where('scope', LedgerRules::scope($scope))->where('id', $deliveryId)->first();

        return $row === null ? null : Delivery::fromRow($row);
    }

    public function counts(string $scope, string $effectId): DeliveryCounts
    {
        return $this->countWhere($scope, static fn (Builder $q): Builder => $q->where('effect_id', $effectId));
    }

    public function campaignCounts(string $scope, string $campaignId): DeliveryCounts
    {
        return $this->countWhere($scope, static fn (Builder $q): Builder => $q->where('campaign_id', $campaignId));
    }

    public function results(string $scope, string $effectId): \Generator
    {
        $scope = LedgerRules::scope($scope);
        $after = '';
        $chunk = $this->rules->settings->chunkSize;

        do {
            $rows = $this->deliveries()->where('scope', $scope)->where('effect_id', $effectId)
                ->where('id', '>', $after)->orderBy('id')->limit($chunk)->get();
            foreach ($rows as $row) {
                $row = Row::of($row);
                $delivery = Delivery::fromRow($row);
                $after = $delivery->id;
                try {
                    $destination = $this->rules->destinationOf($delivery, Row::strOrNull($row, 'destination'));
                } catch (PrivacyException) {
                    $destination = null;
                }
                yield $delivery->toSendResult($destination);
            }
        } while ($rows->count() === $chunk);
    }

    public function health(?string $scope = null): LedgerHealth
    {
        $scope = $scope === null ? null : LedgerRules::scope($scope);
        $now = $this->nowMs();
        $scoped = fn (): Builder => $this->deliveries()->when($scope !== null, static fn (Builder $q): Builder => $q->where('scope', $scope));

        $byState = [];
        foreach ($scoped()->whereIn('state', DeliveryState::values(DeliveryState::Pending, DeliveryState::Deferred, DeliveryState::Leased, DeliveryState::Sending, DeliveryState::OutcomeUnknown))
            ->groupBy('state')->selectRaw('state, count(*) as aggregate')->get() as $row) {
            $row = Row::of($row);
            $byState[Row::str($row, 'state')] = Row::int($row, 'aggregate');
        }

        $due = $scoped()->whereIn('state', DeliveryState::values(DeliveryState::Pending, DeliveryState::Deferred))->where('next_attempt_at', '<=', $now);
        $dueCount = (clone $due)->count();
        $oldest = $dueCount === 0 ? null : $due->min('next_attempt_at');

        return new LedgerHealth(
            $dueCount,
            $byState[DeliveryState::Pending->value] ?? 0,
            $byState[DeliveryState::Deferred->value] ?? 0,
            ($byState[DeliveryState::Leased->value] ?? 0) + ($byState[DeliveryState::Sending->value] ?? 0),
            $byState[DeliveryState::OutcomeUnknown->value] ?? 0,
            is_numeric($oldest) ? $now - (int) $oldest : null,
        );
    }

    public function scopesWithWork(int $limit = 100): array
    {
        $now = $this->nowMs();

        return self::strings($this->deliveries()
            ->where(static fn (Builder $q): Builder => $q
                ->where(static fn (Builder $q): Builder => $q->whereIn('state', DeliveryState::values(DeliveryState::Pending, DeliveryState::Deferred))->where('next_attempt_at', '<=', $now))
                ->orWhere(static fn (Builder $q): Builder => $q->whereIn('state', DeliveryState::values(DeliveryState::Leased, DeliveryState::Sending))->where('lease_expires_at', '<=', $now)))
            ->distinct()->orderBy('scope')->limit($limit)->pluck('scope')->all());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Set-based cancel: one UPDATE per (source state, unknown flag), with the values the shared rules produce for
     * that combination, so a million-row campaign is cancelled without loading it.
     *
     * @param  \Closure(Builder): Builder  $match
     */
    private function cancelWhere(string $scope, \Closure $match, string $hint = 'Cancelled.'): int
    {
        $scope = LedgerRules::scope($scope);
        $now = $this->nowMs();
        $changed = 0;
        $version = new Expression('version + 1');

        foreach (DeliveryStateMachine::sourcesOf(DeliveryEvent::Cancel) as $state) {
            foreach ([false, true] as $unknownSeen) {
                $changes = LedgerRules::cancelChanges(['state' => $state->value, 'unknown_seen' => $unknownSeen, 'version' => 0], $now, $hint);
                if ($changes === null) {
                    continue;
                }
                if (array_key_exists('version', $changes)) {
                    $changes['version'] = $version;
                }
                $changed += $match($this->deliveries()->where('scope', $scope))
                    ->where('state', $state->value)
                    ->where('unknown_seen', $unknownSeen)
                    ->when($state === DeliveryState::Sending, static fn (Builder $q): Builder => $q->where('cancel_requested', false))
                    ->update($changes);
            }
        }

        return $changed;
    }

    // ---------------------------------------------------------------- client receipts

    public function deliveryByMessageId(string $scope, string $messageId): ?Delivery
    {
        $row = $this->deliveries()
            ->where('scope', LedgerRules::scope($scope))
            ->where('message_id', $messageId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : Delivery::fromRow($row);
    }

    public function recordClientEvent(string $scope, string $deliveryId, ClientEvent $event, \DateTimeImmutable $at): ClientEventRecording
    {
        $scope = LedgerRules::scope($scope);
        $column = $event->column();

        // First write wins: the UPDATE only matches while the column is still empty, so two concurrent reports
        // cannot both be recorded. State, version and updated_at stay as they are.
        $updated = $this->deliveries()
            ->where('id', $deliveryId)
            ->where('scope', $scope)
            ->whereNull($column)
            ->whereIn('state', DeliveryState::values(DeliveryState::Accepted, DeliveryState::OutcomeUnknown))
            ->update([$column => LedgerTime::ms($at), 'receipt_marker' => true]);
        if ($updated === 1) {
            return ClientEventRecording::Recorded;
        }

        $row = $this->deliveries()->where('id', $deliveryId)->where('scope', $scope)->first();
        if ($row === null) {
            return ClientEventRecording::NotFound;
        }
        $row = Row::of($row);
        $state = DeliveryState::from(Row::str($row, 'state'));

        if ($state !== DeliveryState::Accepted && $state !== DeliveryState::OutcomeUnknown) {
            return ClientEventRecording::NotEligible;
        }

        return ClientEventRecording::Duplicate;
    }

    public function effectFunnel(string $scope, string $effectId): ReceiptFunnel
    {
        return $this->funnel($this->deliveries()->where('scope', LedgerRules::scope($scope))->where('effect_id', $effectId));
    }

    public function campaignFunnel(string $scope, string $campaignId): ReceiptFunnel
    {
        return $this->funnel($this->deliveries()->where('scope', LedgerRules::scope($scope))->where('campaign_id', $campaignId));
    }

    private function funnel(Builder $query): ReceiptFunnel
    {
        $rows = $query
            ->whereIn('state', DeliveryState::values(DeliveryState::Accepted, DeliveryState::OutcomeUnknown))
            ->groupBy('state')
            ->selectRaw(
                'state, count(*) as n,'
                .' sum(case when receipt_marker or client_received_at is not null or client_opened_at is not null then 1 else 0 end) as marker,'
                .' sum(case when client_received_at is not null then 1 else 0 end) as received,'
                .' sum(case when client_opened_at is not null then 1 else 0 end) as opened,'
                .' sum(case when client_received_at is not null or client_opened_at is not null then 1 else 0 end) as confirmed',
            )
            ->get();

        $funnel = array_fill_keys(['a', 'm', 'r', 'o', 'u', 'ur', 'uo', 'uc'], 0);
        foreach ($rows as $row) {
            $row = Row::of($row);
            if (Row::str($row, 'state') === DeliveryState::Accepted->value) {
                [$funnel['a'], $funnel['m'], $funnel['r'], $funnel['o']] = [Row::int($row, 'n'), Row::int($row, 'marker'), Row::int($row, 'received'), Row::int($row, 'opened')];
            } else {
                [$funnel['u'], $funnel['ur'], $funnel['uo'], $funnel['uc']] = [Row::int($row, 'n'), Row::int($row, 'received'), Row::int($row, 'opened'), Row::int($row, 'confirmed')];
            }
        }

        return new ReceiptFunnel($funnel['a'], $funnel['m'], $funnel['r'], $funnel['o'], $funnel['u'], $funnel['ur'], $funnel['uo'], $funnel['uc']);
    }

    /** @param \Closure(Builder): Builder $match */
    private function countWhere(string $scope, \Closure $match): DeliveryCounts
    {
        $counts = [];
        foreach ($match($this->deliveries()->where('scope', LedgerRules::scope($scope)))->groupBy('state')->selectRaw('state, count(*) as aggregate')->get() as $row) {
            $row = Row::of($row);
            $counts[Row::str($row, 'state')] = Row::int($row, 'aggregate');
        }

        return new DeliveryCounts($counts);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }

    private function effects(): Builder
    {
        return $this->connection()->table($this->effectsTable);
    }

    private function deliveries(): Builder
    {
        return $this->connection()->table($this->deliveriesTable);
    }

    private function nowMs(): int
    {
        return LedgerTime::ms($this->clock->now());
    }
}
