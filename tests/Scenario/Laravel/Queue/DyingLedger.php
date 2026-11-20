<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Queue;

use Padosoft\MobilePush\Core\Ledger\Delivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryCounts;
use Padosoft\MobilePush\Core\Ledger\Effect;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\IntentResult;
use Padosoft\MobilePush\Core\Ledger\Lease;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\LedgerHealth;
use Padosoft\MobilePush\Core\Ledger\PruneResult;
use Padosoft\MobilePush\Core\Ledger\ReclaimReport;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/** The worker process dies: the exception stands for the process going away at that exact point. */
final class WorkerDied extends \Error {}

/**
 * The real ledger, with a worker that dies at a chosen point:
 *  - `$dieOnStart = n`: the n-th markAttemptStarted() succeeds (the row is "sending") and the process dies right
 *    after, as the request leaves;
 *  - `$loseRecordOf = n`: the n-th recorded outcome (and every retry of it) never reaches the database: the response
 *    was received, the process dies before saving it.
 */
final class DyingLedger implements Ledger
{
    public ?int $dieOnStart = null;

    public ?int $loseRecordOf = null;

    private int $starts = 0;

    private int $records = 0;

    private ?string $lost = null;

    public function __construct(public readonly Ledger $inner) {}

    public function markAttemptStarted(Lease $lease): bool
    {
        $started = $this->inner->markAttemptStarted($lease);
        if ($started && ++$this->starts === $this->dieOnStart) {
            throw new WorkerDied('the worker died while the request was leaving');
        }

        return $started;
    }

    public function record(Lease $lease, SendResult $result, ?RetryBudget $budget = null): Recording
    {
        if ($this->lost === null && ++$this->records === $this->loseRecordOf) {
            $this->lost = $lease->deliveryId;
        }
        if ($this->lost === $lease->deliveryId) {
            throw new WorkerDied('the worker died before saving the outcome');
        }

        return $this->inner->record($lease, $result, $budget);
    }

    public function claimDue(string $scope, string $owner, int $limit, ?string $effectId = null, ?int $leaseSeconds = null): array
    {
        return $this->inner->claimDue($scope, $owner, $limit, $effectId, $leaseSeconds);
    }

    public function reclaimExpiredLeases(string $scope, int $limit = 500): ReclaimReport
    {
        return $this->inner->reclaimExpiredLeases($scope, $limit);
    }

    public function expireDue(string $scope, int $limit = 500): int
    {
        return $this->inner->expireDue($scope, $limit);
    }

    public function delivery(string $scope, string $deliveryId): ?Delivery
    {
        return $this->inner->delivery($scope, $deliveryId);
    }

    public function counts(string $scope, string $effectId): DeliveryCounts
    {
        return $this->inner->counts($scope, $effectId);
    }

    public function campaignCounts(string $scope, string $campaignId): DeliveryCounts
    {
        return $this->inner->campaignCounts($scope, $campaignId);
    }

    public function results(string $scope, string $effectId): \Generator
    {
        return $this->inner->results($scope, $effectId);
    }

    public function health(?string $scope = null): LedgerHealth
    {
        return $this->inner->health($scope);
    }

    public function scopesWithWork(int $limit = 100): array
    {
        return $this->inner->scopesWithWork($limit);
    }

    public function recordIntent(EffectIntent $intent, iterable $destinations): IntentResult
    {
        return $this->inner->recordIntent($intent, $destinations);
    }

    public function effect(string $scope, string $effectId): ?Effect
    {
        return $this->inner->effect($scope, $effectId);
    }

    public function message(string $scope, string $effectId): ?Message
    {
        return $this->inner->message($scope, $effectId);
    }

    public function cancelEffect(string $scope, string $effectId): int
    {
        return $this->inner->cancelEffect($scope, $effectId);
    }

    public function cancelCampaign(string $scope, string $campaignId): int
    {
        return $this->inner->cancelCampaign($scope, $campaignId);
    }

    public function cancelOwner(string $scope, string $ownerType, string $ownerId): int
    {
        return $this->inner->cancelOwner($scope, $ownerType, $ownerId);
    }

    public function forgetOwner(string $scope, string $ownerType, string $ownerId): int
    {
        return $this->inner->forgetOwner($scope, $ownerType, $ownerId);
    }

    public function prune(int $retentionSeconds, ?string $scope = null): PruneResult
    {
        return $this->inner->prune($retentionSeconds, $scope);
    }
}
