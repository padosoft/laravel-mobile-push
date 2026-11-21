<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Forget me" for the data this package holds about one owner, strictly inside one scope (a tenant is never crossed:
 * the same owner id in another scope is left alone).
 *
 * Order matters: pending ledger deliveries are cancelled FIRST, so a queued job cannot send them or re-create them;
 * the device registrations are then deleted, so even a job that survived finds no eligible device; the ledger keeps
 * non-personal tombstones (state, counters, non-reversible logical key) that stop a re-submitted job from re-creating
 * the rows. Each store is processed on its own: one failing store never hides the others, and the report says which
 * one failed so the command can simply be run again (every step is idempotent).
 *
 * Callable by the host application: `app(OwnerEraser::class)->forget(new OwnerRef(User::class, '42'))`.
 * What it cannot reach is listed in every report (ErasureReport::NOT_REACHED).
 */
final class OwnerEraser
{
    private const BATCH = 400;

    private readonly OwnerStores $stores;

    public function __construct(Container $container)
    {
        $this->stores = new OwnerStores($container);
    }

    public function forget(OwnerRef $owner, ?string $scope = null): ErasureReport
    {
        $scope = $this->stores->scope($scope);
        /** @var list<string> $indexes keyed destination indexes of the owner's devices and tokens (to find their log rows) */
        $indexes = [];

        $reports = [
            $this->step('ledger: pending deliveries cancelled', fn (): StoreReport => $this->cancelPending($owner, $scope)),
            $this->step('devices', fn (): StoreReport => $this->devices($owner, $scope, $indexes)),
            $this->step('live activities', fn (): StoreReport => $this->liveActivities($owner, $scope, $indexes)),
            $this->step('ledger: deliveries erased', fn (): StoreReport => $this->eraseLedger($owner, $scope)),
            $this->step('send log', fn (): StoreReport => $this->logs($owner, $scope, $indexes)),
        ];

        return new ErasureReport($owner->type, $scope, $reports);
    }

    /** @param  \Closure(): StoreReport  $run */
    private function step(string $store, \Closure $run): StoreReport
    {
        try {
            return $run();
        } catch (\Throwable $e) {
            // Class name only: an exception message can carry SQL with bound values.
            return StoreReport::failed($store, 'error '.$e::class);
        }
    }

    private function cancelPending(OwnerRef $owner, string $scope): StoreReport
    {
        $ledger = $this->stores->ledger();
        if ($ledger === null) {
            return StoreReport::notPresent('ledger: pending deliveries cancelled', 'no ledger tables (light profile)');
        }

        $cancelled = $ledger->cancelOwner($scope, $owner->type, $owner->id);

        return StoreReport::done('ledger: pending deliveries cancelled', $cancelled, $cancelled > 0 ? 'a request already on the wire cannot be recalled' : '');
    }

    /** @param  list<string>  $indexes */
    private function devices(OwnerRef $owner, string $scope, array &$indexes): StoreReport
    {
        $query = $this->stores->devices();
        if ($query === null) {
            return StoreReport::notPresent('devices', 'no device registry table');
        }

        $query = $this->owned($query, $owner, $scope);
        foreach ($query->clone()->get(['token_index', 'fid_index']) as $row) {
            foreach (['token_index', 'fid_index'] as $column) {
                $value = $row->getAttribute($column);
                if (is_string($value) && $value !== '') {
                    $indexes[] = $value;
                }
            }
        }

        return StoreReport::done('devices', $query->toBase()->delete(), 'registrations deleted, tokens included');
    }

    /** @param  list<string>  $indexes */
    private function liveActivities(OwnerRef $owner, string $scope, array &$indexes): StoreReport
    {
        $activities = $this->stores->activities();
        $starts = $this->stores->pushToStartTokens();
        if ($activities === null && $starts === null) {
            return StoreReport::notPresent('live activities', 'module not installed or not migrated');
        }

        $deleted = [0, 0];
        if ($activities !== null) {
            $query = $this->owned($activities, $owner, $scope);
            foreach ($query->clone()->pluck('update_token_index') as $value) {
                if (is_string($value) && $value !== '') {
                    $indexes[] = $value;
                }
            }
            $deleted[0] = $query->toBase()->delete();
        }
        if ($starts !== null) {
            $query = $this->owned($starts, $owner, $scope);
            foreach ($query->clone()->pluck('token_index') as $value) {
                if (is_string($value) && $value !== '') {
                    $indexes[] = $value;
                }
            }
            $deleted[1] = $query->toBase()->delete();
        }

        return StoreReport::done('live activities', $deleted[0] + $deleted[1], sprintf('%d activities, %d push-to-start tokens', $deleted[0], $deleted[1]));
    }

    private function eraseLedger(OwnerRef $owner, string $scope): StoreReport
    {
        $ledger = $this->stores->ledger();
        if ($ledger === null) {
            return StoreReport::notPresent('ledger: deliveries erased', 'no ledger tables (light profile)');
        }

        // The ledger erases the snapshot of the effects that originate from the owner and of those addressed to the
        // owner alone: counted as the difference, since only the ledger knows which ones those are.
        $stored = fn (): int => $this->stores->effects()?->where('scope', $scope)->whereNotNull('message')->count() ?? 0;
        $before = $stored();
        $erased = $ledger->forgetOwner($scope, $owner->type, $owner->id);
        $snapshots = max(0, $before - $stored());

        return StoreReport::done('ledger: deliveries erased', $erased, sprintf('destination, owner and device snapshot erased; %d message snapshot(s) erased; non-personal tombstones kept', $snapshots));
    }

    /** @param  list<string>  $indexes */
    private function logs(OwnerRef $owner, string $scope, array $indexes): StoreReport
    {
        $logs = $this->stores->logs();
        if ($logs === null) {
            return StoreReport::notPresent('send log', 'no log table (database sink not installed)');
        }

        $deleted = $logs->clone()->where('scope', $scope)->where('origin_type', $owner->type)->where('origin_id', $owner->id)->toBase()->delete();
        // Rows that name only the destination (a send by token, no notifiable) are removed when the token was one of the
        // owner's. A row that belongs to another notifiable is never touched, even if it addressed the same phone.
        foreach (array_chunk(array_values(array_unique($indexes)), self::BATCH) as $chunk) {
            $deleted += $logs->clone()->where('scope', $scope)->whereNull('origin_id')->whereIn('destination_index', $chunk)->toBase()->delete();
        }

        return StoreReport::done('send log', $deleted, 'rows tied to the owner or to the owner\'s device tokens');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function owned(Builder $query, OwnerRef $owner, string $scope): Builder
    {
        return $query->where('scope', $scope)->where('owner_type', $owner->type)->where('owner_id', $owner->id);
    }
}
