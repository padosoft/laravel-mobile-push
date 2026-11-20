<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * Deliveries: claiming under a lease, recording outcomes, reading.
 *
 * Worker protocol (the relay is this loop):
 *   foreach (claimDue(scope, owner, n) as $claimed):
 *       markAttemptStarted($claimed->lease) or skip it (lease lost)  // right before the request leaves
 *       send...                                                      // no SQL transaction open here
 *       record($claimed->lease, $result, $finalBudget)               // before the queue job is acknowledged
 *
 * Every method is scoped: an id from another scope is treated as not found.
 */
interface DeliveryStore
{
    /**
     * Lease up to `$limit` due deliveries: pending or deferred, next_attempt_at <= now, effect not expired.
     * Before claiming it reclaims expired leases (reclaimExpiredLeases()) and expires overdue rows (expireDue()),
     * so an expired lease is never handed out without the unknown-outcome rule. Two workers never obtain the same
     * row. Undecryptable raw destinations are ended as Skipped instead of being handed out.
     *
     * @param  string  $owner  worker identity (host:pid:uuid); stored as the lease owner
     * @return list<ClaimedDelivery>
     */
    public function claimDue(string $scope, string $owner, int $limit, ?string $effectId = null, ?int $leaseSeconds = null): array;

    /**
     * Note that the request is about to leave. Returns false when the lease is no longer valid (expired, cancelled,
     * taken over): the worker must NOT send.
     */
    public function markAttemptStarted(Lease $lease): bool;

    /**
     * Persist the outcome of one delivery with the Sender's final budget. Exactly one definitive transition per
     * delivery: a terminal row is never overwritten, a write from a stale lease is ignored. A Deferred result
     * stores next_attempt_at and frees the lease (it becomes Cancelled when a cancel arrived meanwhile).
     */
    public function record(Lease $lease, SendResult $result, ?RetryBudget $budget = null): Recording;

    /**
     * Apply the lease-expiry rules: not started → due again with the same attempts; started → unknown outcome: due
     * again with the budget marked when the effect is replaceable and the policy, attempts and deadline allow it,
     * otherwise ended as OutcomeUnknown. Never recorded as failed.
     */
    public function reclaimExpiredLeases(string $scope, int $limit = 500): ReclaimReport;

    /** End as Expired the waiting deliveries whose effect's logical expiry has passed. Returns the rows changed. */
    public function expireDue(string $scope, int $limit = 500): int;

    public function delivery(string $scope, string $deliveryId): ?Delivery;

    public function counts(string $scope, string $effectId): DeliveryCounts;

    public function campaignCounts(string $scope, string $campaignId): DeliveryCounts;

    /**
     * Cursor over the deliveries of an effect as SendResults (bounded memory; feed it to SendReport).
     * Raw destinations are decrypted; registry and erased destinations are RecordedDestination.
     *
     * @return \Generator<int, SendResult>
     */
    public function results(string $scope, string $effectId): \Generator;

    /** Operational metrics. Null scope = every scope (an operator metric that exposes no row). */
    public function health(?string $scope = null): LedgerHealth;

    /**
     * Scopes that have due or expired-lease work, so a sweeper that does not know the tenants can visit them.
     *
     * @return list<string>
     */
    public function scopesWithWork(int $limit = 100): array;
}
