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
use Padosoft\MobilePush\Core\Ledger\DeliveryBatches;
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
use Padosoft\MobilePush\Core\Ledger\SettlementProbe;
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
final class DatabaseLedger implements ClientReceiptStore, DeliveryBatches, Ledger, SettlementProbe
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
        $previous = null;
        $effect = $intent->effectKey === null ? null : $this->activeEffect($intent, $scope, $now, $previous);
        $created = false;

        if ($effect === null) {
            $row = $this->rules->effectRow($intent, Ulid::generate($this->clock->now()), $now);
            if ($this->effects()->insertOrIgnore($row) === 1) {
                $effect = $row;
                $created = true;
            } else {
                // A concurrent call with the same key won the unique index: behave as the replay we are. The winner
                // committed after this transaction's first read, so the row is read with a lock: at REPEATABLE READ
                // (MySQL's default) a plain read answers from the transaction's snapshot and does not see it.
                $effect = $this->activeEffect($intent, $scope, $now, $previous, current: true)
                    ?? throw new \RuntimeException('The effect could not be recorded and no concurrent effect was found.');
                $previous = null;
            }
        }

        // A new effect cannot hold rows written before W5-P (dev:<kind>:<id>:<generation>): only a replay checks them.
        [$added, $existing] = $this->insertDeliveries($effect, $destinations, $now, ! $created);

        return new IntentResult(Effect::fromRow($effect), $created, $added, $existing, $created ? $previous : null);
    }

    /**
     * The effect currently owning (scope, binding identity, key), or null. An effect past its dedupe window releases
     * its key (dedupe_key = NULL; NULLs never collide in a unique index) so a new effect can take it, and its id is
     * reported in `$previous` (see docs/contracts/ledger.md, "Deduplication window").
     *
     * @return array<string, mixed>|null
     */
    private function activeEffect(EffectIntent $intent, string $scope, int $now, ?string &$previous = null, bool $current = false): ?array
    {
        $key = LedgerRules::dedupeKey($intent->effectKey ?? '');
        $found = $this->effects()
            ->where('scope', $scope)
            ->where('binding_identity', $intent->binding->identity())
            ->where('dedupe_key', $key)
            // A locking read returns the latest committed row whatever the isolation level (SQLite has no clause).
            ->when($current, static fn (Builder $q): Builder => $q->sharedLock())
            ->first();
        if ($found === null) {
            return null;
        }
        $found = Row::of($found);

        if (Row::int($found, 'dedupe_until') <= $now) {
            $this->effects()->where('id', Row::str($found, 'id'))->update(['dedupe_key' => null, 'updated_at' => $now]);
            $previous = Row::str($found, 'id');

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
    private function insertDeliveries(array $effect, iterable $destinations, int $now, bool $replay): array
    {
        $scope = Row::str($effect, 'scope');
        $chunkSize = $this->rules->settings->chunkSize;
        $added = $seen = 0;
        $chunk = $legacy = [];

        foreach ($destinations as $destination) {
            $seen++;
            $key = $this->rules->logicalKey($destination, $scope);
            if (isset($chunk[$key])) {
                continue;
            }
            $chunk[$key] = $this->rules->deliveryRow($effect, $destination, $key, $now);
            $old = $replay ? LedgerRules::legacyDeviceKey($destination) : null;
            if ($old !== null) {
                $legacy[$old] = $key;
            }
            if (count($chunk) >= $chunkSize) {
                $added += $this->flushDeliveries($this->withoutLegacy(Row::str($effect, 'id'), $chunk, $legacy));
                $chunk = $legacy = [];
            }
        }
        $added += $this->flushDeliveries($this->withoutLegacy(Row::str($effect, 'id'), $chunk, $legacy));

        // Rows the unique index refused were already there (earlier call, or an earlier chunk of this one).
        return [$added, $seen - $added];
    }

    /**
     * Drop from a chunk the registry destinations the effect already holds under their pre-W5-P key (one indexed
     * query per chunk, on the unique (effect_id, logical_key) index; only for a replay of an existing effect).
     *
     * @param  array<string, array<string, mixed>>  $chunk
     * @param  array<string, string>  $legacy  old key => new key
     * @return array<string, array<string, mixed>>
     */
    private function withoutLegacy(string $effectId, array $chunk, array $legacy): array
    {
        if ($legacy === []) {
            return $chunk;
        }
        $found = $this->deliveries()->where('effect_id', $effectId)->whereIn('logical_key', array_keys($legacy))->pluck('logical_key')->all();
        foreach (self::strings($found) as $old) {
            unset($chunk[$legacy[$old]]);
        }

        return $chunk;
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

            // Read before the owner columns are erased: the effects addressed to this owner and to nobody else.
            $ownEffects = $this->effectsAddressedOnlyTo($scope, $ownerType, $ownerId);

            $erased = $this->deliveries()->where('scope', $scope)->where('owner_type', $ownerType)->where('owner_id', $ownerId)
                ->update(LedgerRules::forgetDeliveryChanges($now));
            $this->effects()->where('scope', $scope)->where('origin_type', $ownerType)->where('origin_id', $ownerId)
                ->update(LedgerRules::forgetEffectChanges($now));
            foreach (array_chunk($ownEffects, 500) as $ids) {
                $this->effects()->where('scope', $scope)->whereIn('id', $ids)->update(LedgerRules::forgetEffectChanges($now));
            }

            return $erased;
        });
    }

    /**
     * The effects whose deliveries ALL belong to this owner: their message snapshot was written for this owner alone,
     * so forgetting the owner erases it. An effect that also addresses anybody else (another owner, a raw token, a
     * topic) keeps its snapshot: the others still need it to be sent.
     *
     * @return list<string>
     */
    private function effectsAddressedOnlyTo(string $scope, string $ownerType, string $ownerId): array
    {
        $own = [];
        $candidates = self::strings($this->deliveries()->where('scope', $scope)->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->distinct()->pluck('effect_id')->all());
        foreach (array_chunk($candidates, 500) as $ids) {
            $shared = self::strings($this->deliveries()->where('scope', $scope)->whereIn('effect_id', $ids)
                ->where(static fn (Builder $q): Builder => $q
                    ->whereNull('owner_type')->orWhereNull('owner_id')
                    ->orWhere('owner_type', '!=', $ownerType)->orWhere('owner_id', '!=', $ownerId))
                ->distinct()->pluck('effect_id')->all());
            array_push($own, ...array_values(array_diff($ids, $shared)));
        }

        return $own;
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

        // Read back by primary key only and keep the rows this owner holds: a condition on scope/state here lets the
        // planner pick a (scope, state) index and walk every leased row of the scope.
        $rows = [];
        foreach ($this->deliveries()->whereIn('id', $ids)->get() as $row) {
            $row = Row::of($row);
            if (Row::str($row, 'scope') === $scope && Row::strOrNull($row, 'lease_owner') === $owner && Row::str($row, 'state') === DeliveryState::Leased->value) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => [Row::int($a, 'next_attempt_at'), Row::str($a, 'id')] <=> [Row::int($b, 'next_attempt_at'), Row::str($b, 'id')]);

        $claimed = [];
        foreach ($rows as $row) {
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

    /**
     * The first `$limit` due candidates in (next_attempt_at, id) order: one query PER claimable state, each read in
     * index order (mp_deliveries_claim_idx, or mp_deliveries_effect_claim_idx for one effect) and stopped at the limit,
     * then merged. A single `state IN (...)` query cannot be served in that order by any index and made SQLite sort
     * every due row of the scope on every claim (W5-P).
     *
     * @return list<array{id: string, version: int}>
     */
    private function candidates(string $scope, ?string $effectId, int $now, int $limit, bool $lock = false): array
    {
        $found = [];
        foreach (DeliveryStateMachine::sourcesOf(DeliveryEvent::Claim) as $state) {
            $query = ($effectId === null ? $this->deliveries() : $this->deliveries()->where('effect_id', $effectId))
                ->where('scope', $scope)
                ->where('state', $state->value)
                ->where('next_attempt_at', '<=', $now)
                ->where(static fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->limit($limit);
            if ($lock) {
                $query->lock('for update skip locked');
            }
            foreach ($query->get(['id', 'version', 'next_attempt_at']) as $row) {
                $row = Row::of($row);
                $found[] = ['id' => Row::str($row, 'id'), 'version' => Row::int($row, 'version'), 'at' => Row::int($row, 'next_attempt_at')];
            }
        }
        usort($found, static fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);

        return array_map(static fn (array $c): array => ['id' => $c['id'], 'version' => $c['version']], array_slice($found, 0, $limit));
    }

    /** @return list<string> */
    private function claimSkipLocked(string $scope, string $owner, int $limit, ?string $effectId, int $now, int $lease): array
    {
        $connection = $this->connection();
        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // InnoDB at its default REPEATABLE READ also locks the GAPS of the index range a locking read scans. The
            // UPDATE of another claimer moves its rows inside that index (state pending -> leased) and would wait for
            // this transaction, so claimers would run one after the other (seen on MySQL 8.4: lock wait timeout while
            // a second worker claimed inside the first one's transaction). READ COMMITTED locks the rows only. The
            // statement applies to the next transaction of this session and to nothing else.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $connection->transaction(function () use ($scope, $owner, $limit, $effectId, $now, $lease): array {
            $ids = array_column($this->candidates($scope, $effectId, $now, $limit, true), 'id');
            if ($ids === []) {
                return [];
            }

            $changes = LedgerRules::claimChanges($owner, 0, $now, $lease);
            $changes['version'] = new Expression('version + 1');
            // The rows are locked by this transaction (FOR UPDATE SKIP LOCKED): no further guard is needed, and none
            // that would let the planner prefer a state index over the primary key.
            $this->deliveries()->whereIn('id', $ids)->update($changes);

            return $ids;
        });
    }

    /**
     * Optimistic claim, set-based: the candidates are grouped by version and each group is taken with ONE guarded
     * UPDATE (id IN ..., version = v, still claimable); a row another worker took in between simply does not match.
     * The rows really won are the ones read back with this owner (claimDue()). Returns every id attempted.
     *
     * @return list<string>
     */
    private function claimOptimistic(string $scope, string $owner, int $limit, ?string $effectId, int $now, int $lease): array
    {
        $attempted = [];
        $won = 0;
        for ($round = 0; $round < 3 && $won < $limit; $round++) {
            $candidates = $this->candidates($scope, $effectId, $now, $limit - $won);
            if ($candidates === []) {
                break;
            }
            $byVersion = [];
            foreach ($candidates as $candidate) {
                $byVersion[$candidate['version']][] = $candidate['id'];
                $attempted[] = $candidate['id'];
            }
            foreach ($byVersion as $version => $ids) {
                // Guarded by (id, version) only. Every state change bumps the version (except leased -> sending, which
                // starts from a claimed row), so an unchanged version proves the row is still in the claimable state
                // it was read in. A `state IN (...)` condition here made SQLite walk the (state, updated_at) index,
                // i.e. every pending row, for each 500-row claim (W5-P; EXPLAIN QUERY PLAN in ScalingQueryPlansTest).
                $won += $this->deliveries()
                    ->whereIn('id', $ids)
                    ->where('version', $version)
                    ->update(LedgerRules::claimChanges($owner, $version, $now, $lease));
            }
        }

        return $attempted;
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

    public function markAttemptsStarted(array $leases): array
    {
        if ($leases === []) {
            return [];
        }
        $now = $this->nowMs();
        $groups = [];
        foreach ($leases as $lease) {
            $groups[LedgerRules::scope($lease->scope)."\0".$lease->owner."\0".$lease->version][] = $lease;
        }

        $started = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $ids = array_map(static fn (Lease $l): string => $l->deliveryId, $group);
            // Lease owner + version prove an in-flight state (every other state change bumps the version). No state
            // condition: it would let the planner pick a (scope, state, ...) index and walk every leased row instead
            // of the primary key. The scope is checked like in markAttemptStarted() (a lease presented under another
            // scope starts nothing), but through an expression no index can serve, for the same reason: with a plain
            // "scope = ?" SQLite walks the claim index.
            $guard = fn (): Builder => $this->deliveries()
                ->whereIn('id', $ids)
                ->whereRaw("coalesce(scope, '') = ?", [LedgerRules::scope($first->scope)])
                ->where('lease_owner', $first->owner)
                ->where('version', $first->version);
            $updated = $guard()
                ->where('lease_expires_at', '>', $now)
                ->update(['state' => DeliveryState::Sending->value, 'attempt_started_at' => $now, 'updated_at' => $now]);
            if ($updated === count($ids)) {
                array_push($started, ...$ids);

                continue;
            }
            // Some leases were lost: the rows this call marked are the ones holding this lease, sending, started now.
            array_push($started, ...self::strings($guard()->where('attempt_started_at', $now)->pluck('id')->all()));
        }

        return $started;
    }

    /**
     * One read by primary key for the whole batch, then the guarded UPDATEs in one short transaction (no network
     * inside it: the relay calls this between two windows). A row whose guard no longer matches (a cancel flipped
     * its flag meanwhile) goes through record(), which reads it again.
     */
    public function recordMany(array $records): array
    {
        if ($records === []) {
            return [];
        }
        $now = $this->nowMs();
        $rows = [];
        foreach ($this->deliveries()->whereIn('id', array_map(static fn (array $r): string => $r[0]->deliveryId, $records))->get() as $row) {
            $row = Row::of($row);
            $rows[Row::str($row, 'id')] = $row;
        }

        $out = [];
        $again = [];
        $this->connection()->transaction(function () use ($records, $rows, $now, &$out, &$again): void {
            foreach ($records as $i => [$lease, $result, $budget]) {
                $row = $rows[$lease->deliveryId] ?? null;
                if ($row === null || Row::str($row, 'scope') !== LedgerRules::scope($lease->scope)) {
                    $out[$lease->deliveryId] = Recording::NotFound;

                    continue;
                }
                $verdict = self::verdict($row, $lease);
                $changes = $verdict === null ? LedgerRules::recordChanges($row, $result, $budget, $now, $this->rules->settings->markerIssued) : null;
                if ($verdict !== null || $changes === null) {
                    $out[$lease->deliveryId] = $verdict ?? Recording::IgnoredStaleLease;

                    continue;
                }
                $updated = $this->deliveries()
                    ->where('id', $lease->deliveryId)
                    ->where('lease_owner', $lease->owner)
                    ->where('version', $lease->version)
                    ->where('cancel_requested', Row::bool($row, 'cancel_requested'))
                    ->update($changes);
                if ($updated === 1) {
                    $out[$lease->deliveryId] = Recording::Recorded;
                } else {
                    $again[] = $i;
                }
            }
        });
        foreach ($again as $i) {
            [$lease, $result, $budget] = $records[$i];
            $out[$lease->deliveryId] = $this->record($lease, $result, $budget);
        }

        return $out;
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
            // Defensive: a row that still holds this lease is leased or sending, and the rules accept a record from
            // both, so no test reaches this. It is what keeps a future state from being written over.
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

        // Defensive: the flag flips only once (false -> true) under a valid lease, so the second read settles it and
        // a third lost round is not reachable today; the bound is what keeps this loop finite whatever changes.
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

    public function hasOpenDeliveries(string $scope, string $effectId): bool
    {
        // mp_deliveries_effect_claim_idx (effect_id, scope, state, ...): four index seeks, no row of a final state read.
        return $this->deliveries()->where('effect_id', $effectId)->where('scope', LedgerRules::scope($scope))
            ->whereIn('state', self::openStates())->exists();
    }

    public function campaignHasOpenDeliveries(string $scope, string $campaignId): bool
    {
        // mp_deliveries_campaign_state_idx (scope, campaign_id, state).
        return $this->deliveries()->where('scope', LedgerRules::scope($scope))->where('campaign_id', $campaignId)
            ->whereIn('state', self::openStates())->exists();
    }

    /** @return list<string> */
    private static function openStates(): array
    {
        return DeliveryState::values(DeliveryState::Pending, DeliveryState::Deferred, DeliveryState::Leased, DeliveryState::Sending);
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

    /**
     * One query per state, each reading only the rows of that state that need a worker (due, or lease expired):
     * mp_deliveries_work_idx (state, next_attempt_at, scope) for the waiting states, the (state, ...) prune index for
     * the in-flight ones (few rows: at most the chunks being worked on). Terminal rows are never read, however many.
     * The single OR query this replaces scanned the whole table on SQLite.
     */
    public function scopesWithWork(int $limit = 100): array
    {
        $now = $this->nowMs();
        $scopes = [];
        foreach ([[DeliveryState::Pending, 'next_attempt_at'], [DeliveryState::Deferred, 'next_attempt_at'], [DeliveryState::Leased, 'lease_expires_at'], [DeliveryState::Sending, 'lease_expires_at']] as [$state, $column]) {
            foreach (self::strings($this->deliveries()->where('state', $state->value)->where($column, '<=', $now)->distinct()->pluck('scope')->all()) as $scope) {
                $scopes[$scope] = true;
            }
        }
        $scopes = array_map('strval', array_keys($scopes));
        sort($scopes, SORT_STRING);

        return array_slice($scopes, 0, $limit);
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
